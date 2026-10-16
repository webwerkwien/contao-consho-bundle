<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalProductTileRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductTile;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Seit B-10 liest die Kachel den Ab-Preis aus der Projektion der Sichtbarkeit.
 * Die Fixtures schreiben den Katalog, repository() baut davor die Projektion
 * mit dem echten Projektor (Shop 1 in EUR, Startpunkte de, en, fr).
 */
class DbalProductTileRepositoryTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_000_000;
    private const SHOP = 1;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => self::SHOP, 'title' => 'Shop', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', self::SHOP);
        $this->insertRootPage(2, 'en', self::SHOP);
        $this->insertRootPage(3, 'fr', self::SHOP);
    }

    public function testReadsWhatATilePrints(): void
    {
        $this->product(1, 'Schwedenbitter', teaser: 'Kräuterbitter nach alter Rezeptur');
        $this->price(1, '12.6000');

        $tiles = $this->forIds([1]);

        self::assertCount(1, $tiles);
        self::assertSame('Schwedenbitter', $tiles[0]->title);
        self::assertSame('Kräuterbitter nach alter Rezeptur', $tiles[0]->teaser);
        self::assertSame('12.6', $tiles[0]->price);
        self::assertFalse($tiles[0]->isFrom);
    }

    /**
     * Block H (H-2): der Streichpreis der Zeile, die die Zahl liefert — brutto
     * oder netto wie die Zahl selbst.
     */
    public function testDieKachelLiestDenStreichpreisIhrerZeile(): void
    {
        $this->product(1, 'Essig');
        $this->price(1, '9.6000');
        $this->connection->update('tl_consho_product_list_price', ['compareNet' => '10.0000', 'compareGross' => '12.0000'], ['pid' => 1]);
        $this->product(2, 'Salz');
        $this->price(2, '5.0000');

        $tiles = $this->forIds([1, 2]);

        self::assertSame(12.0, (float) $tiles[0]->compare);
        self::assertSame('', $tiles[1]->compare);
    }

    /**
     * Abo-2b, P88/P89: die Kachel weiß, ob ihre Zahl ein Abo-Betrag ist —
     * dann ohne Kaufknopf, denn der kauft einmalig — und ob es den
     * Einmalkauf auch im Abo gibt.
     */
    public function testAboBetragUndAuchImAbo(): void
    {
        $this->product(1, 'Mitgliedschaft');
        $this->price(1, '1.2000', rule: 4);
        $this->product(2, 'E-Book');
        $this->price(2, '9.0000', also: true);
        $this->product(3, 'Salz');
        $this->price(3, '5.0000');

        [$member, $book, $salt] = $this->forIds([1, 2, 3]);

        self::assertSame([4, false, false], [$member->subscriptionRule, $member->alsoSubscription, $member->isBuyable]);
        self::assertSame([0, true, true], [$book->subscriptionRule, $book->alsoSubscription, $book->isBuyable]);
        self::assertSame([0, false, true], [$salt->subscriptionRule, $salt->alsoSubscription, $salt->isBuyable]);
    }

    /** Abo-2b, L4b: bei Varianten zählt das Modell der Zeile mit dem Ab-Preis. */
    public function testVariantenNurImAbo(): void
    {
        $this->product(1, 'Kurs');
        $this->variant(10, of: 1);
        $this->price(10, '30.0000', rule: 2);
        $this->variant(11, of: 1);
        $this->price(11, '12.0000', rule: 5);

        $tile = $this->forIds([1])[0];

        self::assertSame(['12', true, 5], [$tile->price, $tile->isFrom, $tile->subscriptionRule]);
    }

    /**
     * Fable-Review Abo-2b, K1: Variante A einmalig mit „auch im Abo", die
     * billigere Variante B nur im Abo — die Zahl ist B's Abo-Betrag, und
     * „auch im Abo" stünde unter einem Abo-Betrag falsch.
     */
    public function testAuchImAboNurUnterEinemEinmalkaufPreis(): void
    {
        $this->product(1, 'Kurs');
        $this->variant(10, of: 1);
        $this->price(10, '30.0000', also: true);
        $this->variant(11, of: 1);
        $this->price(11, '12.0000', rule: 5);

        $tile = $this->forIds([1])[0];

        self::assertSame([5, false], [$tile->subscriptionRule, $tile->alsoSubscription]);
    }

    public function testKeepsTheOrderItWasAskedFor(): void
    {
        /*
         * 🔴 An IN clause returns rows in whatever order the engine likes. A
         * listing sorted by price that arrives sorted by id is a defect nobody
         * sees until two prices differ.
         */
        $this->product(1, 'A');
        $this->price(1, '10.0000');
        $this->product(2, 'B');
        $this->price(2, '10.0000');
        $this->product(3, 'C');
        $this->price(3, '10.0000');

        self::assertSame([3, 1, 2], array_map(static fn ($t) => $t->id, $this->forIds([3, 1, 2])));
    }

    public function testTakesTheCheapestVisibleVariantPrice(): void
    {
        $this->product(1, 'Schwedenbitter');
        $this->variant(10, of: 1);
        $this->price(10, '25.0000');
        $this->variant(11, of: 1);
        $this->price(11, '12.6000');

        $tile = $this->forIds([1])[0];

        self::assertSame('12.6', $tile->price);
        self::assertTrue($tile->isFrom, 'two different variant prices, so no single figure is true');
    }

    public function testVariantsAtOnePriceNeedNoFrom(): void
    {
        // Colours make no price difference — Michael, 2026-09-11. Writing
        // "ab 12,60 €" there would suggest a cheaper one exists.
        $this->product(1, 'Schwedenbitter');
        $this->variant(10, of: 1);
        $this->price(10, '12.6000');
        $this->variant(11, of: 1);
        $this->price(11, '12.6000');

        self::assertFalse($this->forIds([1])[0]->isFrom);
    }

    public function testAnInvisibleVariantSetsNoPrice(): void
    {
        $this->product(1, 'Schwedenbitter');
        $this->variant(10, of: 1);
        $this->price(10, '25.0000');
        $this->variant(11, of: 1, published: false);
        $this->price(11, '5.0000');

        $tile = $this->forIds([1])[0];

        self::assertSame('25', $tile->price, '"ab 5 €" for something nobody can buy would be untrue');
        self::assertFalse($tile->isFrom);
    }

    public function testTheMainProductsOwnPriceDoesNotCountOnceItHasVariants(): void
    {
        // Befund 6 aus A-4b: Die Detailseite fragt nur die Varianten — die Kachel jetzt auch.
        $this->product(1, 'Schwedenbitter');
        $this->price(1, '10.0000');
        $this->variant(10, of: 1);
        $this->price(10, '15.0000');
        $this->variant(11, of: 1);
        $this->price(11, '18.0000');

        $tile = $this->forIds([1])[0];

        self::assertSame('15', $tile->price);
        self::assertTrue($tile->isFrom);
    }

    public function testThePriceIsTheOneTheListingSortedBy(): void
    {
        /*
         * B-10: Die Kachel nimmt den Ab-Preis aus der Zeile der Projektion der
         * Sichtbarkeit, nach der ProductQuery sortiert hat — nicht aus der
         * Listenpreis-Projektion. Die Zeile weicht hier absichtlich ab und wird
         * nicht neu gebaut.
         */
        $this->product(1, 'Schwedenbitter');
        $this->price(1, '12.6000');
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => self::SHOP, 'language' => 'de', 'net' => '8.2500', 'gross' => '9.9000']);

        $tile = (new DbalProductTileRepository($this->connection))->forIds([1], 'EUR', 'AT', false, self::SHOP, 'de', 'de')[0];

        self::assertSame('9.9', $tile->price);
    }

    public function testAskingForNothingQueriesNothing(): void
    {
        self::assertSame([], $this->forIds([]));
    }

    public function testTheTileShowsTheTranslatedTitle(): void
    {
        $this->product(1, 'Kräuterbitter');
        $this->price(1, '12.6000');
        $this->translation(1, 'en', alias: 'herbal-bitters', title: 'Herbal Bitters');

        $tiles = $this->repository()->forIds([1], 'EUR', 'AT', false, self::SHOP, 'en', 'de');

        self::assertSame('Herbal Bitters', $tiles[0]->title);
    }

    public function testAnUntranslatedTitleFallsBack(): void
    {
        // Übersetzungszeile vorhanden, Titel darin leer — der Rückfall gilt je
        // Feld, nicht je Zeile: der Teaser unten ist übersetzt, der Titel nicht.
        $this->product(1, 'Kräuterbitter');
        $this->price(1, '12.6000');
        $this->translation(1, 'en', alias: 'herbal-bitters', title: '', teaser: 'Herbal notes');

        $tiles = $this->repository()->forIds([1], 'EUR', 'AT', false, self::SHOP, 'en', 'de');

        self::assertSame('Kräuterbitter', $tiles[0]->title);
    }

    public function testTheTeaserIsTranslatedToo(): void
    {
        $this->product(1, 'Kräuterbitter', teaser: 'Nach alter Rezeptur');
        $this->price(1, '12.6000');
        $this->translation(1, 'en', alias: 'herbal-bitters', title: 'Herbal Bitters', teaser: 'Old family recipe');

        $tiles = $this->repository()->forIds([1], 'EUR', 'AT', false, self::SHOP, 'en', 'de');

        self::assertSame('Old family recipe', $tiles[0]->teaser);
    }

    public function testWithoutALanguageNothingChanges(): void
    {
        // Der Prüfstein: in der Standardsprache exakt das Ergebnis von vorher —
        // auch wenn eine Übersetzung existiert, darf sie nicht greifen.
        $this->product(1, 'Kräuterbitter', teaser: 'Nach alter Rezeptur');
        $this->price(1, '12.6000');
        $this->translation(1, 'en', alias: 'herbal-bitters', title: 'Herbal Bitters', teaser: 'Old family recipe');

        $tiles = $this->repository()->forIds([1], 'EUR', 'AT', false, self::SHOP, 'de', 'de');

        self::assertSame('Kräuterbitter', $tiles[0]->title);
        self::assertSame('Nach alter Rezeptur', $tiles[0]->teaser);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<\Webwerkwien\ContaoConshoBundle\Catalog\ProductTile>
     */
    private function forIds(array $ids): array
    {
        return $this->repository()->forIds($ids, 'EUR', 'AT', false, self::SHOP, 'de', 'de');
    }

    public function testDieKachelLiestDieNettoSpalteWennDerBesucherNettoSieht(): void
    {
        // C-4: Die Projektion führt beide Spalten (C-1a), und showsNet wählt
        // eine aus. Ohne diesen Test bliebe „der Händler sieht netto" eine
        // Behauptung über eine Spalte, die niemand je gelesen hat.
        $this->product(1, 'Schwedenbitter');
        $this->price(1, '12.6000');

        self::assertSame('10.5', $this->repository()->forIds([1], 'EUR', 'AT', true, self::SHOP, 'de', 'de')[0]->price);
        self::assertSame('12.6', $this->forIds([1])[0]->price);
    }

    public function testAbGiltFuerDenAngezeigtenBetragUndNichtImmerFuerDasBrutto(): void
    {
        /*
         * ⚠️ Zwei Varianten, gleiches Brutto, verschiedenes Netto — der Fall
         * entsteht, sobald sie in verschiedenen Steuerklassen sitzen. Brutto
         * gäbe es nichts zu sagen, netto schon: „ab" hängt an dem Betrag, den
         * der Besucher liest, sonst steht in der Liste ein Preis ohne „ab",
         * über einer Produktseite mit zwei verschiedenen.
         */
        $this->product(1, 'Schwedenbitter');
        $this->price(1, '12.0000');
        $this->variant(2, 1);
        $this->variant(3, 1);

        $this->connection->insert('tl_consho_product_list_price', ['pid' => 2, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);
        $this->connection->insert('tl_consho_product_list_price', ['pid' => 3, 'currency' => 'EUR', 'country' => 'AT', 'net' => '11.4286', 'gross' => '12.0000']);

        self::assertFalse($this->forIds([1])[0]->isFrom);
        self::assertTrue($this->repository()->forIds([1], 'EUR', 'AT', true, self::SHOP, 'de', 'de')[0]->isFrom);
    }

    public function testTheFromPriceIgnoresAVariantHiddenInThatLanguage(): void
    {
        /*
         * B-8: Variante 2 trägt einen eigenen, nicht übersetzten Warnhinweis und
         * ist auf fr unsichtbar (VisibilityCriteria, Regel 3). Die Kachel nannte
         * trotzdem ihren Preis als „ab"-Preis — die Produktseite zeigt ihn nie.
         */
        $this->product(1, 'Schwedenbitter');
        $this->translation(1, 'fr', 'amer-suedois', 'Amer suédois');
        $this->variant(2, 1);
        $this->connection->update('tl_consho_product', ['safetyWarnings' => 'Nicht für Kinder'], ['id' => 2]);
        $this->price(2, '5.0000');
        $this->variant(3, 1);
        $this->price(3, '20.0000');

        $german = $this->repository()->forIds([1], 'EUR', 'AT', false, self::SHOP, 'de', 'de');
        $french = $this->repository()->forIds([1], 'EUR', 'AT', false, self::SHOP, 'fr', 'de');

        self::assertSame(5.0, (float) $german[0]->price);
        self::assertTrue($german[0]->isFrom);

        self::assertSame(20.0, (float) $french[0]->price);
        self::assertFalse($french[0]->isFrom);
    }

    public function testDieKachelNimmtDieZeileIhrerWaehrung(): void
    {
        /*
         * Zwei Startpunkte derselben Sprache in zwei Währungen (der DACH-Fall)
         * haben seit C-1c Aufgabe 5b zwei Projektionszeilen je Produkt. Ohne
         * die Währung im LEFT JOIN träfe die Kachel beide und nähme den Preis
         * der Zeile, die die Datenbank zuerst liefert — eine der beiden
         * Behauptungen unten wäre dann falsch, gleich welche.
         */
        $this->insertRootPage(4, 'de', self::SHOP, 'CHF');
        $this->product(1, 'Schwedenbitter');
        $this->price(1, '12.6000');
        $this->price(1, '15.0000', 'CHF');

        self::assertSame(12.6, (float) $this->repository()->forIds([1], 'EUR', 'AT', false, self::SHOP, 'de', 'de')[0]->price);
        self::assertSame(15.0, (float) $this->repository()->forIds([1], 'CHF', 'AT', false, self::SHOP, 'de', 'de')[0]->price);
    }

    /**
     * The repository over a projection built from the fixtures as they stand —
     * at NOW, the moment the tests used to pass to forIds() itself.
     */
    private function repository(): DbalProductTileRepository
    {
        (new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        ))->rebuild(self::NOW);

        return new DbalProductTileRepository($this->connection);
    }

    /**
     * 🔴 Michaels Entscheidung vom 2026-09-22 im Wortlaut: bei einem Produkt
     * mit Varianten **„kein Knopf, Kachel verlinkt"**. Es gibt dort keinen
     * kaufbaren Artikel, solange niemand gewählt hat, und eine Ersatzregel
     * („welche nehmen wir?") überraschte irgendwann jemanden mit der falschen
     * Flasche.
     */
    public function testEinProduktOhneVariantenIstAusDerListeKaufbar(): void
    {
        $this->product(1, 'Kräutersalz');
        $this->price(1, '8.9000');

        self::assertTrue($this->forIds([1])[0]->isBuyable);
    }

    /**
     * Block G (G7): Die Kachel sagt den Zustand, wo Bestand geführt wird —
     * mit Varianten den besten der sichtbaren. Eine versteckte Variante auf
     * Lager zählt nicht.
     */
    public function testDieKachelSagtDenBestand(): void
    {
        $this->product(1, 'Schwedenbitter');
        $this->price(1, '8.9000');
        $this->product(5, 'Wanderstock');
        $this->variant(6, of: 5);
        $this->variant(7, of: 5, published: false);
        $this->price(6, '20.0000');
        $this->price(7, '20.0000');
        $this->product(9, 'Ohne Bestand');
        $this->price(9, '1.0000');

        $this->connection->update('tl_consho_product', ['stockTracked' => 1], ['id' => 1]);
        $this->connection->update('tl_consho_product', ['stockTracked' => 1], ['id' => 5]);
        (new StockRows($this->connection))->sync(1, self::NOW);
        (new StockRows($this->connection))->sync(5, self::NOW);
        $this->connection->update('tl_consho_stock', ['quantity' => '9.0000'], ['article' => 7]);

        // Builds the projection; the tiles come from a repository that knows the stock.
        $this->repository();
        $tiles = (new DbalProductTileRepository($this->connection, new Availability($this->connection)))->forIds([1, 5, 9], 'EUR', 'AT', false, self::SHOP, 'de', 'de');

        self::assertSame(['unavailable', 'unavailable', ''], array_map(static fn (ProductTile $tile): string => $tile->stock, $tiles));

        $this->connection->update('tl_consho_stock', ['quantity' => '3.0000'], ['article' => 6]);
        $tiles = (new DbalProductTileRepository($this->connection, new Availability($this->connection)))->forIds([5], 'EUR', 'AT', false, self::SHOP, 'de', 'de');

        self::assertSame('available', $tiles[0]->stock);
    }

    public function testEinProduktMitVariantenIstEsNicht(): void
    {
        $this->product(1, 'Schwedenbitter');
        $this->variant(2, of: 1);
        $this->price(2, '12.6000');

        self::assertFalse($this->forIds([1])[0]->isBuyable);
    }

    /**
     * ⚠️ NICHT dasselbe wie `isFrom`. Das sagt nur, ob die Varianten sich im
     * PREIS unterscheiden — zwei gleich teure Varianten sind trotzdem zwei
     * Varianten, und das Hauptprodukt bleibt unkaufbar.
     */
    public function testZweiGleichTeureVariantenMachenDasProduktTrotzdemUnkaufbar(): void
    {
        $this->product(1, 'Schwedenbitter');
        $this->variant(2, of: 1);
        $this->variant(3, of: 1);
        $this->price(2, '12.6000');
        $this->price(3, '12.6000');

        $tile = $this->forIds([1])[0];

        self::assertFalse($tile->isFrom, 'Ein Preis, also kein „ab".');
        self::assertFalse($tile->isBuyable, 'Zwei Varianten, also keine Kaufentscheidung.');
    }

    /**
     * Die Menge, die der Knopf in den Warenkorb legt. Gekürzt wird der WERT:
     * `minPurchase` ist `decimal(10,4)`, und ein roh durchgereichtes „1,0000"
     * stand am 2026-09-21 auf jeder Produktseite im Mengenfeld.
     */
    public function testDieKaufmengeIstDieMindestmengeOhneUeberfluessigeNullen(): void
    {
        $this->product(1, 'Kräutersalz');
        $this->price(1, '8.9000');
        $this->connection->update('tl_consho_product', ['minPurchase' => '2.5000'], ['id' => 1]);

        self::assertSame('2.5', $this->forIds([1])[0]->purchaseQuantity);
    }

    public function testOhneMindestmengeWirdEinesGekauft(): void
    {
        $this->product(1, 'Kräutersalz');
        $this->price(1, '8.9000');

        self::assertSame('1', $this->forIds([1])[0]->purchaseQuantity);
    }

    private function product(int $id, string $title, string $teaser = ''): void
    {
        $this->connection->insert('tl_consho_product', [
            'id' => $id,
            'pid' => 0,
            'published' => 1,
            'title' => $title,
            'teaser' => $teaser,
        ]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => $id, 'shop' => self::SHOP]);
    }

    private function variant(int $id, int $of, bool $published = true): void
    {
        $this->connection->insert('tl_consho_product', [
            'id' => $id,
            'pid' => $of,
            'published' => $published ? 1 : 0,
            'title' => 'Variante '.$id,
        ]);
    }

    private function translation(int $pid, string $language, ?string $alias, string $title = '', string $teaser = ''): void
    {
        $this->connection->insert('tl_consho_translation', [
            'pid' => $pid,
            'ptable' => 'tl_consho_product',
            'language' => $language,
            'alias' => $alias,
            'title' => $title,
            'teaser' => $teaser,
        ]);
    }

    private function price(int $productId, string $gross, string $currency = 'EUR', int $rule = 0, bool $also = false): void
    {
        $this->connection->insert('tl_consho_product_list_price', [
            'pid' => $productId,
            'currency' => $currency,
            'country' => 'AT',
            'net' => number_format(((float) $gross) / 1.2, 4, '.', ''),
            'gross' => $gross,
            'subscriptionRule' => $rule,
            'alsoSubscription' => $also ? 1 : 0,
        ]);
    }
}

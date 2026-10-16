<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Die Projektion der Sichtbarkeit schreiben und nachführen (B-10, 5.7) — gegen
 * eine echte Datenbank.
 *
 * Welche Zeilen hineingehören, prüft VisibilityRowsTest. Hier geht es darum,
 * dass refresh, rebuild und der Sprachabgleich genau diese Zeilen hinstellen —
 * und keine alten stehen lassen.
 */
class VisibilityProjectorTest extends TestCase
{
    use CatalogSchema;

    // Zehnstellig wie ein echter Zeitstempel — siehe VisibilityCriteriaTest.
    private const NOW = 1_790_000_000;
    private const SHOP = 1;

    private Connection $connection;
    private ShopLanguages $languages;
    private ProjectionState $state;
    private VisibilityProjector $projector;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);

        // de ist Standardsprache, fr Übersetzungssprache.
        $this->insertShop(['id' => self::SHOP, 'title' => 'Shop', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', self::SHOP);
        $this->insertRootPage(2, 'fr', self::SHOP);

        $this->languages = new ShopLanguages($this->connection);
        $this->state = new ProjectionState($this->connection);
        $this->projector = new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            $this->languages,
            $this->state,
        );
    }

    public function testRefreshWritesMainAndVariantRowsPerLanguage(): void
    {
        $this->main(1);
        $this->translate(1, 'fr');
        $this->variant(2, 1);
        $this->listPrice(2, '12.0000');

        // Zeile eines anderen Hauptprodukts: refresh([1]) darf sie nicht anfassen.
        $this->insertVisibility(['tstamp' => 1, 'pid' => 9, 'main' => 9, 'variant' => 0, 'shop' => self::SHOP, 'language' => 'de', 'net' => '1.0000', 'gross' => '1.2000']);

        self::assertTrue($this->projector->refresh([1], self::NOW));

        self::assertSame([
            '1:de/EUR H 1 (main 1) 12.0000',
            '1:de/EUR V 2 (main 1) -',
            '1:de/EUR H 9 (main 9) 1.2000',
            '1:fr/EUR H 1 (main 1) 12.0000',
            '1:fr/EUR V 2 (main 1) -',
        ], $this->stored());

        self::assertSame([self::NOW], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT DISTINCT tstamp FROM tl_consho_product_visibility WHERE main = 1')));
    }

    public function testRefreshRemovesAnUnpublishedProduct(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->projector->refresh([1], self::NOW);

        $this->connection->update('tl_consho_product', ['published' => 0], ['id' => 1]);

        self::assertTrue($this->projector->refresh([1], self::NOW));
        self::assertSame([], $this->stored());
    }

    public function testARehungVariantTakesTheLastVariantFromItsOldMainProduct(): void
    {
        // Hauptprodukt 1 ist nur über Variante 3 sichtbar und bepreist. Wird 3
        // umgehängt, steht 1 nur noch in den gespeicherten Zeilen von 3.
        $this->main(1);
        $this->main(2);
        $this->variant(3, 1);
        $this->listPrice(3, '12.0000');
        $this->projector->refresh([1, 2], self::NOW);

        // Die fr-Variantenzeile ohne Hauptzeile ist gewollt: variants() prüft
        // die Übersetzung des Hauptprodukts nicht, jeder Leser kommt über die
        // Hauptzeile (siehe VisibilityRows::variants()).
        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000', '1:de/EUR V 3 (main 1) -', '1:fr/EUR V 3 (main 1) -'], $this->stored());

        $this->connection->update('tl_consho_product', ['pid' => 2], ['id' => 3]);

        self::assertTrue($this->projector->refresh([3], self::NOW));
        self::assertSame(['1:de/EUR H 2 (main 2) 12.0000', '1:de/EUR V 3 (main 2) -', '1:fr/EUR V 3 (main 2) -'], $this->stored());
    }

    public function testReportingOnlyTheNewMainProductOfARehungVariantAlsoClearsTheOldOne(): void
    {
        // Ohne Rücksicht auf die gespeicherte Zeile von 3 stieße das Einfügen
        // an den eindeutigen Schlüssel (pid, shop, language, currency).
        $this->main(1);
        $this->main(2);
        $this->variant(3, 1);
        $this->listPrice(3, '12.0000');
        $this->projector->refresh([1, 2], self::NOW);

        $this->connection->update('tl_consho_product', ['pid' => 2], ['id' => 3]);

        self::assertTrue($this->projector->refresh([2], self::NOW));
        self::assertSame(['1:de/EUR H 2 (main 2) 12.0000', '1:de/EUR V 3 (main 2) -', '1:fr/EUR V 3 (main 2) -'], $this->stored());
    }

    public function testADeletedProductLosesItsRows(): void
    {
        // Gelöscht steht es nur noch in den gespeicherten Zeilen.
        $this->main(1);
        $this->variant(2, 1);
        $this->listPrice(2, '12.0000');
        $this->projector->refresh([1], self::NOW);

        $this->connection->executeStatement('DELETE FROM tl_consho_product WHERE id IN (1, 2)');

        self::assertTrue($this->projector->refresh([1], self::NOW));
        self::assertSame([], $this->stored());
    }

    public function testRefreshReportsWhetherTheRowsChanged(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');

        self::assertFalse($this->projector->refresh([], self::NOW));
        self::assertTrue($this->projector->refresh([1], self::NOW));

        // Ein neuer Zeitstempel allein ist keine Änderung.
        self::assertFalse($this->projector->refresh([1], self::NOW + 60));

        $this->connection->update('tl_consho_product_list_price', ['gross' => '14.0000', 'net' => '11.6667'], ['pid' => 1]);

        self::assertTrue($this->projector->refresh([1], self::NOW + 120));
        self::assertSame(['1:de/EUR H 1 (main 1) 14.0000'], $this->stored());
    }

    public function testRefreshWritesAcrossTheBlockBoundary(): void
    {
        foreach (range(1, 501) as $id) {
            $this->main($id);
            $this->listPrice($id, '12.0000');
        }

        self::assertTrue($this->projector->refresh(range(1, 501), self::NOW));
        self::assertSame(501, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_product_visibility WHERE language = 'de' AND variant = 0"));
    }

    public function testRebuildRemovesRowsWithoutATargetAndSetsTheMarker(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');

        // Shop 9 gibt es nicht mehr; it hat Shop 1 keine Root-Seite.
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 9, 'language' => 'de', 'net' => '1.0000', 'gross' => '1.2000']);
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => self::SHOP, 'language' => 'it', 'net' => '1.0000', 'gross' => '1.2000']);

        $this->state->set(ProjectionState::VISIBILITY_SHOP_PREFIX.'9', 123);

        self::assertNull($this->state->get(ProjectionState::VISIBILITY_BOUNDARIES));

        $this->projector->rebuild(self::NOW);

        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
        self::assertSame(self::NOW, $this->state->get(ProjectionState::VISIBILITY_BOUNDARIES));
        self::assertSame(
            [ProjectionState::VISIBILITY_SHOP_PREFIX.'1' => $this->fingerprint('de/EUR,fr/EUR', 'de')],
            $this->state->withPrefix(ProjectionState::VISIBILITY_SHOP_PREFIX),
        );
    }

    public function testSyncShopsBuildsANewLanguage(): void
    {
        $this->main(1);
        $this->translate(1, 'fr');
        $this->translate(1, 'it');
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        $this->insertRootPage(3, 'it', self::SHOP);

        self::assertTrue($this->projector->syncShops(self::NOW));
        self::assertSame([
            '1:de/EUR H 1 (main 1) 12.0000',
            '1:fr/EUR H 1 (main 1) 12.0000',
            '1:it/EUR H 1 (main 1) 12.0000',
        ], $this->stored());
    }

    public function testSyncShopsRemovesADroppedLanguage(): void
    {
        $this->main(1);
        $this->translate(1, 'fr');
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        $this->connection->delete('tl_page', ['id' => 2]);

        self::assertTrue($this->projector->syncShops(self::NOW));
        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testSyncShopsRemovesADeletedShop(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        $this->connection->delete('tl_consho_shop', ['id' => self::SHOP]);

        self::assertTrue($this->projector->syncShops(self::NOW));
        self::assertSame([], $this->stored());
        self::assertSame([], $this->state->withPrefix(ProjectionState::VISIBILITY_SHOP_PREFIX));
        self::assertFalse($this->projector->syncShops(self::NOW));
    }

    public function testSyncShopsRemovesRowsOfAShopWithoutTarget(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        // Zeilen am Zustand vorbei — Seed, SQL, ein Stand vor den Fingerabdrücken.
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 9, 'language' => 'de', 'net' => '1.0000', 'gross' => '1.2000']);

        self::assertTrue($this->projector->syncShops(self::NOW));
        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testALanguageWithoutAVisibleProductIsNotRebuiltOnEverySync(): void
    {
        // fr hat eine Root-Seite, aber kein übersetztes Produkt — also keine
        // einzige Zeile. Aus der Tabelle gelesen fehlte fr im Ist-Stand für
        // immer, und jedes Speichern einer Seite baute den ganzen Shop neu.
        $this->main(1);
        $this->listPrice(1, '12.0000');

        self::assertTrue($this->projector->syncShops(self::NOW));
        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());

        self::assertFalse($this->projector->syncShops(self::NOW + 60));
        self::assertSame([self::NOW], $this->tstamps());
    }

    public function testJederStartpunktBringtSeineEigeneWaehrungMit(): void
    {
        // Spec 4.0: Die Währung hängt am Startpunkt, das Heimatland am Shop.
        // Zwei Startpunkte desselben Shops in zwei Währungen sind damit zwei
        // Ziele mit zwei Ab-Preisen — vorher bekamen beide dieselbe Währung
        // aus tl_consho_shop angehängt.
        $this->insertRootPage(3, 'en', self::SHOP, 'GBP');
        $this->main(1);
        $this->translate(1, 'en');
        $this->listPrice(1, '12.0000');
        $this->listPrice(1, '9.0000', 'GBP');

        $this->projector->rebuild(self::NOW);

        self::assertSame([
            '1:de/EUR H 1 (main 1) 12.0000',
            '1:en/GBP H 1 (main 1) 9.0000',
        ], $this->stored());
    }

    public function testEineWaehrungDieNurEinenStartpunktBetrifftLaesstDenAnderenStehen(): void
    {
        // Die Gegenprobe zum Test darüber: Ein Produkt, das es nur in Euro
        // gibt, verschwindet aus der Pfund-Sprache und bleibt in der
        // Euro-Sprache — mit der Shop-Währung als einziger Quelle wäre es
        // entweder überall da oder nirgends.
        $this->insertRootPage(3, 'en', self::SHOP, 'GBP');
        $this->main(1);
        $this->translate(1, 'en');
        $this->listPrice(1, '12.0000');

        $this->projector->rebuild(self::NOW);

        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testDieselbeSpracheInZweiWaehrungenErgibtZweiZiele(): void
    {
        // Der DACH-Fall: example.de in Euro, example.ch in Franken, beide auf
        // Deutsch. Bis Aufgabe 5b trug die Projektion (pid, shop, language)
        // eindeutig und keine Währung — das zweite Ziel überschrieb das erste
        // still, und welcher der beiden Startpunkte gewann, entschied die
        // Sortierung. Mit der Währung im Schlüssel sind es wieder zwei Zeilen
        // mit zwei Ab-Preisen.
        $this->insertRootPage(3, 'de', self::SHOP, 'CHF');
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->listPrice(1, '15.0000', 'CHF');

        $this->projector->rebuild(self::NOW);

        self::assertSame([
            '1:de/CHF H 1 (main 1) 15.0000',
            '1:de/EUR H 1 (main 1) 12.0000',
        ], $this->stored());
    }

    public function testRefreshLaesstDieZeileDerAnderenWaehrungStehen(): void
    {
        // refresh() löscht je Ziel und schreibt es neu. Ohne die Währung in
        // dieser Löschung räumte das Euro-Ziel auch die Franken-Zeilen weg und
        // umgekehrt — am Ende stünde nur das zuletzt geschriebene Ziel da.
        $this->insertRootPage(3, 'de', self::SHOP, 'CHF');
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->listPrice(1, '15.0000', 'CHF');

        self::assertTrue($this->projector->refresh([1], self::NOW));
        self::assertSame([
            '1:de/CHF H 1 (main 1) 15.0000',
            '1:de/EUR H 1 (main 1) 12.0000',
        ], $this->stored());
    }

    public function testRefreshMerktEinenTauschDerPreiseZwischenDenWaehrungen(): void
    {
        // Der Fingerabdruck der gespeicherten Zeilen entscheidet, ob refresh()
        // eine Änderung meldet — und damit, ob der Seiten-Cache geleert wird.
        // Ohne die Währung darin sind „Euro 12, Franken 15" und „Euro 15,
        // Franken 12" dieselbe sortierte Liste: die Liste zeigte auf beiden
        // Seiten stundenlang die vertauschten Preise weiter.
        $this->insertRootPage(3, 'de', self::SHOP, 'CHF');
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->listPrice(1, '15.0000', 'CHF');
        $this->projector->refresh([1], self::NOW);

        $this->connection->update('tl_consho_product_list_price', ['gross' => '15.0000', 'net' => '12.5000'], ['pid' => 1, 'currency' => 'EUR']);
        $this->connection->update('tl_consho_product_list_price', ['gross' => '12.0000', 'net' => '10.0000'], ['pid' => 1, 'currency' => 'CHF']);

        self::assertTrue($this->projector->refresh([1], self::NOW + 60));
    }

    public function testRebuildRaeumtZeilenEinerWaehrungWegDieKeinStartpunktMehrFuehrt(): void
    {
        // Ein Startpunkt, der von Franken auf Euro gestellt wurde, lässt seine
        // Franken-Zeilen stehen. Für das Aufräumen zählt nicht das Paar aus
        // Shop und Sprache — das gibt es weiterhin — sondern das Ziel samt
        // Währung.
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => self::SHOP, 'language' => 'de', 'currency' => 'CHF', 'net' => '12.5000', 'gross' => '15.0000']);

        $this->projector->rebuild(self::NOW);

        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testEinProduktNurInEinerWaehrungStehtNurUnterDieserZeile(): void
    {
        // Die Gegenprobe: Zwei Startpunkte derselben Sprache, ein Produkt nur
        // in Franken bepreist. Ohne Währung im Schlüssel wäre es entweder in
        // beiden Zeilen zu sehen oder in keiner — je nachdem, welches Ziel
        // zuletzt geschrieben hat.
        $this->insertRootPage(3, 'de', self::SHOP, 'CHF');
        $this->main(1);
        $this->listPrice(1, '15.0000', 'CHF');

        $this->projector->rebuild(self::NOW);

        self::assertSame(['1:de/CHF H 1 (main 1) 15.0000'], $this->stored());
    }

    public function testEinStartpunktOhneSpracheErgibtKeinZiel(): void
    {
        // audiencesOf() meldet auch einen Startpunkt ohne Sprache, damit ihn
        // jemand benennen kann. Wer rechnet, überspringt ihn — sonst stünden
        // Zeilen mit leerer Sprache in der Projektion, die keine Liste je
        // liest.
        $this->connection->insert('tl_page', ['id' => 3, 'type' => 'root', 'title' => 'Ohne Sprache', 'language' => '', 'consho_shop' => self::SHOP, 'consho_currency' => 'EUR']);
        $this->main(1);
        $this->listPrice(1, '12.0000');

        $this->projector->rebuild(self::NOW);

        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testACurrencyChangeRebuildsTheShop(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->main(2);
        $this->listPrice(2, '12.0000');
        $this->listPrice(2, '15.0000', 'CHF');
        $this->projector->rebuild(self::NOW);

        // Seit C-1c am Startpunkt, nicht mehr am Shop. Beide Startpunkte,
        // sonst wäre es ein Währungswechsel für de und keiner für fr.
        $this->connection->update('tl_page', ['consho_currency' => 'CHF'], ['consho_shop' => self::SHOP]);

        self::assertTrue($this->projector->syncShops(self::NOW + 60));
        // Produkt 1 hat keinen Preis in CHF — und damit keine Zeile mehr.
        self::assertSame(['1:de/CHF H 2 (main 2) 15.0000'], $this->stored());
    }

    public function testEinWaehrungswechselAnEinemVonZweiStartpunktenBautDenShopNeu(): void
    {
        // Der Fingerabdruck trägt die Währung je Sprache, nicht eine je Shop:
        // Bliebe er eine Shop-Angabe, überdeckte die unveränderte Währung des
        // zweiten Startpunkts den Wechsel am ersten, und die Liste zeigte
        // weiter Euro-Preise.
        $this->insertRootPage(3, 'en', self::SHOP, 'GBP');
        $this->main(1);
        $this->translate(1, 'en');
        $this->listPrice(1, '12.0000');
        $this->listPrice(1, '9.0000', 'GBP');
        $this->listPrice(1, '15.0000', 'CHF');
        $this->projector->rebuild(self::NOW);

        $this->connection->update('tl_page', ['consho_currency' => 'CHF'], ['id' => 1]);

        self::assertTrue($this->projector->syncShops(self::NOW + 60));
        self::assertSame([
            '1:de/CHF H 1 (main 1) 15.0000',
            '1:en/GBP H 1 (main 1) 9.0000',
        ], $this->stored());
    }

    public function testEinWechselDesHeimatlandesBautDenShopNeu(): void
    {
        // Das Heimatland bleibt am Shop (Spec 4.0) und entscheidet das Brutto
        // jedes Ab-Preises — es gehört damit in den Fingerabdruck wie die
        // Währung, nur je Shop statt je Sprache. Bisher hielt es dort allein
        // die Formatzusage des Tests darüber fest; ein Wechsel selbst war
        // nirgends geprüft.
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => 'DE', 'net' => '10.0000', 'gross' => '11.9000']);
        $this->projector->rebuild(self::NOW);

        $this->connection->update('tl_consho_shop', ['country' => 'DE'], ['id' => self::SHOP]);

        self::assertTrue($this->projector->syncShops(self::NOW + 60));
        self::assertSame(['1:de/EUR H 1 (main 1) 11.9000'], $this->stored());
    }

    public function testAChangedDefaultLanguageRebuildsTheShop(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        $this->connection->update('tl_consho_shop', ['fallbackLanguage' => 'fr'], ['id' => self::SHOP]);

        self::assertTrue($this->projector->syncShops(self::NOW + 60));
        // Unübersetzt: jetzt in fr sichtbar, in de nicht mehr.
        self::assertSame(['1:fr/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testSyncShopsReportsNothingWhenTheLanguagesMatch(): void
    {
        $this->main(1);
        $this->translate(1, 'fr');
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        self::assertFalse($this->projector->syncShops(self::NOW));
        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000', '1:fr/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testRebuildShopUsesTheDefaultLanguageChangedInTheSameRequest(): void
    {
        // Befund 3: ShopLanguages cacht je Request. Wer den Shop speichert und
        // danach neu baut, darf nicht mit der alten Standardsprache rechnen.
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
        self::assertSame('de', $this->languages->fallbackOf(self::SHOP));

        $this->connection->update('tl_consho_shop', ['fallbackLanguage' => 'fr'], ['id' => self::SHOP]);

        $this->projector->rebuildShop(self::SHOP, self::NOW);

        // Unübersetzt: jetzt in fr sichtbar (Texte am Datensatz), in de nicht mehr.
        self::assertSame(['1:fr/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    /**
     * Die gespeicherten Zeilen, lesbar: Shop:Sprache/Währung,
     * H(aupt)/V(ariante), pid, Hauptprodukt, Brutto-Ab-Preis.
     *
     * Die Währung steht seit C-1c Aufgabe 5b mit im Schlüssel und deshalb auch
     * hier: Ohne sie zeigten zwei Zeilen derselben Sprache in zwei Währungen
     * als ein Eintrag und der Preis entschiede allein, ob der Test rot wird —
     * ein falsch geschriebener Währungsschlüssel bliebe unsichtbar.
     *
     * @return list<string>
     */
    private function stored(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT shop, language, currency, pid, main, variant, gross FROM tl_consho_product_visibility ORDER BY shop, language, currency, pid');

        return array_map(
            static fn (array $row): string => \sprintf(
                '%d:%s/%s %s %d (main %d) %s',
                $row['shop'],
                $row['language'],
                $row['currency'],
                1 === (int) $row['variant'] ? 'V' : 'H',
                $row['pid'],
                $row['main'],
                null === $row['gross'] ? '-' : \sprintf('%.4F', $row['gross']),
            ),
            $rows,
        );
    }

    private function main(int $id): void
    {
        $this->insertProduct(['id' => $id, 'pid' => 0, 'title' => 'Produkt '.$id, 'published' => 1]);
        $this->insertProductShop(['pid' => $id, 'shop' => self::SHOP]);
    }

    private function variant(int $id, int $pid): void
    {
        $this->insertProduct(['id' => $id, 'pid' => $pid, 'title' => 'Variante '.$id, 'published' => 1]);
    }

    private function listPrice(int $productId, string $gross, string $currency = 'EUR'): void
    {
        $this->insertListPrice(['pid' => $productId, 'currency' => $currency, 'country' => 'AT', 'gross' => $gross, 'net' => \sprintf('%.4F', (float) $gross / 1.2)]);
    }

    /**
     * Der Fingerabdruck eines Shops, wie ihn der Auftrag festlegt.
     */
    private function fingerprint(string $audiences, string $fallback, string $country = 'AT'): int
    {
        return crc32($audiences.'|'.$fallback.'|'.$country);
    }

    /**
     * @return list<int>
     */
    private function tstamps(): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn('SELECT DISTINCT tstamp FROM tl_consho_product_visibility ORDER BY tstamp'));
    }

    private function translate(int $productId, string $language): void
    {
        $this->insertTranslation(['pid' => $productId, 'ptable' => 'tl_consho_product', 'language' => $language, 'title' => 'Produit '.$productId, 'alias' => 'produit-'.$productId]);
    }
}

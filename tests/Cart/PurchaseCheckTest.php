<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\PurchaseCheck;
use Webwerkwien\ContaoConshoBundle\Cart\PurchaseVerdict;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * Gegen SQLite mit dem gemeinsamen Katalog-Testschema — die Sichtbarkeit
 * entscheidet VisibilityCriteria selbst, nicht ein Mock. Genau darum geht es:
 * die Regeln stehen an einer Stelle, und der Warenkorb ist ihr fünfter Leser.
 *
 * Die Einfüger des Traits liefern keine Id zurück (`insertProduct(): void`),
 * deshalb stehen die Ids hier als Konstanten. Eine Zuordnung zum Shop läuft
 * über `insertProductShop()` — eine Methode `assignToShop()` gibt es nicht.
 *
 * `check(..., '', '')` bedeutet „frag nicht nach Übersetzungen" (siehe
 * PurchaseCheck::check()) und ist nur dort richtig, wo keine Seite im Spiel
 * ist — die meisten Tests hier prüfen Regeln 1–3, für die das zutrifft. Die
 * Sprach-Tests weiter unten übergeben echte Sprachpaare.
 */
class PurchaseCheckTest extends TestCase
{
    use CatalogSchema;

    // Zehnstellig wie ein echter Zeitstempel — start/stop sind varchar(10),
    // siehe VisibilityCriteria::published().
    private const NOW = 1_700_000_000;

    private const SHOP = 1;
    private const MAIN = 1;
    private const VARIANT = 2;

    private PurchaseCheck $check;

    protected function setUp(): void
    {
        $this->createCatalogSchema(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->check = new PurchaseCheck($this->catalogConnection, new VisibilityCriteria());
    }

    public function testEinSichtbaresProduktOhneVariantenDarfGekauftWerden(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'minPurchase' => '1.0000', 'purchaseSteps' => '1.0000']);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        $verdict = $this->check->check(self::MAIN, self::SHOP, '2', self::NOW, '', '');

        self::assertTrue($verdict->allowed);
        self::assertSame('2.0000', $verdict->quantity);
        self::assertSame(self::MAIN, $verdict->mainProductId);
        self::assertNull($verdict->reason);
    }

    public function testEinUnveroeffentlichtesProduktWirdAbgelehnt(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 0]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertSame(PurchaseVerdict::UNAVAILABLE, $this->check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '')->reason);
    }

    public function testEinProduktEinesAnderenShopsWirdAbgelehnt(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 1]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => 2]);

        self::assertSame(PurchaseVerdict::UNAVAILABLE, $this->check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '')->reason);
    }

    public function testEinHauptproduktMitVariantenWirdAbgelehnt(): void
    {
        // Es ist keine Ware, sondern die Auswahl davor (VisibilityCriteria).
        $this->insertProduct(['id' => self::MAIN, 'published' => 1]);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'published' => 1]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertSame(PurchaseVerdict::NEEDS_CHOICE, $this->check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '')->reason);
    }

    public function testEineSichtbareVarianteDarfGekauftWerden(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'minPurchase' => '1.0000', 'purchaseSteps' => '1.0000']);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'published' => 1]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        $verdict = $this->check->check(self::VARIANT, self::SHOP, '1', self::NOW, '', '');

        self::assertTrue($verdict->allowed);
        // Mindestmenge und Schrittweite stehen am Hauptprodukt.
        self::assertSame(self::MAIN, $verdict->mainProductId);
    }

    public function testEineVarianteEinesAbgeschaltetenHauptproduktsWirdAbgelehnt(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 0]);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'published' => 1]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertSame(PurchaseVerdict::UNAVAILABLE, $this->check->check(self::VARIANT, self::SHOP, '1', self::NOW, '', '')->reason);
    }

    public function testDieMindestmengeDerVarianteKommtVomHauptprodukt(): void
    {
        // Die Variante trägt die Vorgabewerte der Spalte (1/1); maßgeblich
        // sind die 5 am Hauptprodukt — sonst fiele die falsche Zeile auf.
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'minPurchase' => '5.0000', 'purchaseSteps' => '3.0000']);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'published' => 1]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertSame('8.0000', $this->check->check(self::VARIANT, self::SHOP, '6', self::NOW, '', '')->quantity);
    }

    public function testEineUngueltigeMengeWirdAbgelehnt(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'minPurchase' => '5.0000', 'purchaseSteps' => '1.0000']);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertSame(PurchaseVerdict::INVALID_QUANTITY, $this->check->check(self::MAIN, self::SHOP, '2', self::NOW, '', '')->reason);
    }

    /**
     * Spec F, offene Punkte (K6): 1,5 Lizenzschlüssel ergaben abgerundet
     * einen. Digitales und Wertgutscheine gibt es nur ganz; eine
     * Dienstleistung darf 1,5 Stunden sein, Ware 1,5 kg.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function kinds(): iterable
    {
        yield 'digital' => ['digital', false];
        yield 'Wertgutschein' => ['voucher', false];
        yield 'Dienstleistung' => ['service', true];
        yield 'körperlich' => ['physical', true];
    }

    /** @dataProvider kinds */
    public function testNurGanzeMengenBeiDigitalemUndGutscheinen(string $kind, bool $fractionAllowed): void
    {
        $this->catalogConnection->insert('tl_consho_product_type', ['id' => 4, 'kind' => $kind]);
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'productType' => 4, 'minPurchase' => '0.5000', 'purchaseSteps' => '0.5000']);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertTrue($this->check->check(self::MAIN, self::SHOP, '2', self::NOW, '', '')->allowed, 'ganz geht immer');
        self::assertSame($fractionAllowed, $this->check->check(self::MAIN, self::SHOP, '1.5', self::NOW, '', '')->allowed);
    }

    public function testEineUnbekannteIdWirdAbgelehnt(): void
    {
        self::assertSame(PurchaseVerdict::UNAVAILABLE, $this->check->check(999, self::SHOP, '1', self::NOW, '', '')->reason);
    }

    public function testEinInDieserSpracheUnsichtbaresProduktDarfDortNichtGekauftWerden(): void
    {
        // Die fünfte Sichtbarkeitsregel: Sicherheitstexte am Produkt, aber
        // keine Übersetzung — auf der französischen Seite zeigt der Katalog
        // das Produkt nicht, also darf es von dort auch nicht in den
        // Warenkorb. Ohne die Sprache prüfte der Warenkorb vier von fünf
        // Regeln und nähme auf, was die Seite gar nicht anbietet.
        $this->insertProduct([
            'id' => self::MAIN,
            'published' => 1,
            'minPurchase' => '1.0000',
            'purchaseSteps' => '1.0000',
            'safetyWarnings' => 'Enthält Alkohol',
        ]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        self::assertSame(
            PurchaseVerdict::UNAVAILABLE,
            $this->check->check(self::MAIN, self::SHOP, '1', self::NOW, 'fr', 'de')->reason,
        );

        // In der Ausgangssprache derselbe Artikel, dieselbe Zeile: erlaubt.
        self::assertTrue($this->check->check(self::MAIN, self::SHOP, '1', self::NOW, 'de', 'de')->allowed);
    }

    /**
     * Befund 1 (Code-Review): vor der Pflichtparameter-Änderung bekam ein
     * Aufrufer, der nur $language übergab und $fallback vergaß, lautlos
     * $language !== $fallback ('de' !== '') — die Hauptsprache galt dann als
     * Fremdsprache, VisibilityCriteria verlangte eine Übersetzungszeile für
     * 'de', die es für die Hauptsprache nie gibt, und ein völlig normales
     * Produkt ohne jeden Sicherheitstext wurde fälschlich abgelehnt. Das war
     * mit dem alten Vorgabewert `string $fallback = ''` reproduzierbar (siehe
     * Git-Historie dieses Tests).
     *
     * Jetzt sind beide Parameter Pflicht: das Weglassen ist kein stiller
     * Laufzeitfehler mehr, sondern ein sofortiger ArgumentCountError beim
     * Aufruf — ein Fehler beim Analysieren, nicht beim Verkauf.
     */
    public function testFehlendeFallbackSpracheIstEinFehlerBeimAufruf(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'minPurchase' => '1.0000', 'purchaseSteps' => '1.0000']);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        $this->expectException(\ArgumentCountError::class);

        /** @phpstan-ignore-next-line arguments.count (das fehlende Argument ist genau der Punkt dieses Tests) */
        $this->check->check(self::MAIN, self::SHOP, '1', self::NOW, 'de');
    }

    public function testMitUebersetztenSicherheitstextenDarfAuchDortGekauftWerden(): void
    {
        $this->insertProduct([
            'id' => self::MAIN,
            'published' => 1,
            'minPurchase' => '1.0000',
            'purchaseSteps' => '1.0000',
            'safetyWarnings' => 'Enthält Alkohol',
        ]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);
        $this->insertTranslation([
            'pid' => self::MAIN,
            'ptable' => 'tl_consho_product',
            'language' => 'fr',
            'title' => 'Amer suédois',
            // Ohne Alias gilt die Übersetzung als unvollständig — die
            // Variante hätte in dieser Sprache keine eigene Adresse
            // (VisibilityCriteria::translated()).
            'alias' => 'amer-suedois',
            'safetyWarnings' => 'Contient de l’alcool',
        ]);

        self::assertTrue($this->check->check(self::MAIN, self::SHOP, '1', self::NOW, 'fr', 'de')->allowed);
    }

    public function testEinAngebotenesModellDarfInDenWarenkorb(): void
    {
        $this->visibleProduct();

        $check = new PurchaseCheck($this->catalogConnection, new VisibilityCriteria(), $this->offers([0, 5]));

        self::assertTrue($check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '', 5, [3])->allowed);
        self::assertTrue($check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '', 0, [])->allowed);
    }

    public function testEinNichtAngebotenesModellWirdAbgelehnt(): void
    {
        $this->visibleProduct();

        $check = new PurchaseCheck($this->catalogConnection, new VisibilityCriteria(), $this->offers([5]));

        // Regel 7 hat das Produkt nicht, und als Einmalkauf wird es nicht verkauft.
        self::assertSame(PurchaseVerdict::NOT_OFFERED, $check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '', 7)->reason);
        self::assertSame(PurchaseVerdict::NOT_OFFERED, $check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '', 0)->reason);
        self::assertSame(PurchaseVerdict::NOT_OFFERED, $check->check(self::MAIN, self::SHOP, '1', self::NOW, '', '', -1)->reason);
    }

    public function testDieGruppenGehenAnDieAngebote(): void
    {
        $this->visibleProduct();

        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->expects(self::once())->method('allows')->with(self::MAIN, 5, [3, 4])->willReturn(true);

        (new PurchaseCheck($this->catalogConnection, new VisibilityCriteria(), $offers))
            ->check(self::MAIN, self::SHOP, '1', self::NOW, '', '', 5, [3, 4])
        ;
    }

    public function testOhneModellFragtDieMengenaenderungNichtNachDemAngebot(): void
    {
        // Die Menge einer bestehenden Zeile zu ändern ändert nicht ihr Modell.
        // Fragte die Prüfung dann nach dem Einmalkauf, ließe sich die Menge
        // eines reinen Abo-Produkts nie mehr ändern. Ob das Modell noch gilt,
        // sagt der Warenkorb über den Preis (CartPricer).
        $this->visibleProduct();

        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->expects(self::never())->method('allows');

        self::assertTrue((new PurchaseCheck($this->catalogConnection, new VisibilityCriteria(), $offers))->check(self::MAIN, self::SHOP, '1', self::NOW, '', '')->allowed);
    }

    public function testJederAblehnungsgrundHatSeinenHinweis(): void
    {
        self::assertSame('not_offered', PurchaseVerdict::refuse(PurchaseVerdict::NOT_OFFERED)->notice()->value);
        self::assertSame('needs_choice', PurchaseVerdict::refuse(PurchaseVerdict::NEEDS_CHOICE)->notice()->value);
        self::assertSame('invalid_quantity', PurchaseVerdict::refuse(PurchaseVerdict::INVALID_QUANTITY)->notice()->value);
        self::assertSame('unavailable', PurchaseVerdict::refuse(PurchaseVerdict::UNAVAILABLE)->notice()->value);
    }

    /**
     * Befund 2 (Code-Review): `refuse()` gab bisher `quantity = '0.0000'` und
     * `mainProductId = 0` aus — beide sehen wie echte Werte aus. Ein Aufrufer,
     * der sie liest, ohne vorher `allowed` zu prüfen, bekäme keinen Fehler,
     * sondern eine plausibel aussehende Nullzeile im Warenkorb. Beide sind
     * jetzt `null`, damit ein fehlender Null-Test ein PHPStan-Fehler ist statt
     * einer stillen Falle.
     */
    public function testEineAblehnungHatWederMengeNochHauptproduktId(): void
    {
        $verdict = PurchaseVerdict::refuse(PurchaseVerdict::UNAVAILABLE);

        self::assertNull($verdict->quantity);
        self::assertNull($verdict->mainProductId);
    }

    private function visibleProduct(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'published' => 1, 'minPurchase' => '1.0000', 'purchaseSteps' => '1.0000']);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);
    }

    /**
     * @param list<int> $offered die Modelle, die das Produkt jedem anbietet
     */
    private function offers(array $offered): SubscriptionOffers
    {
        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->method('allows')->willReturnCallback(
            static fn (int $article, int $rule): bool => \in_array($rule, $offered, true),
        );

        return $offers;
    }
}

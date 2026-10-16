<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cron;

use Contao\CoreBundle\Cache\CacheTagManager;
use Webwerkwien\ContaoConshoBundle\Pricing\CatalogPriceRules;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Cron\ListPriceBoundaryCron;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTaxClassesInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTaxClasses;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTree;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalMarketRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRow;
use Webwerkwien\ContaoConshoBundle\Seo\SitemapCacheTags;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\ProjectionWriterInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Pricing\SqliteProjectionWriter;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

class ListPriceBoundaryCronTest extends TestCase
{
    use CatalogSchema;
    use TaxFixture;

    private const NOW = 1_790_000_000;

    private const LONG_AGO = self::NOW - 3600;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        // Der Markt, in dem die EUR-Preise der Tests projiziert werden (C25) —
        // ohne ihn entsteht keine Zeile und kein Preis ist fällig. Seit C-1c
        // gehören zwei Zeilen dazu: das Heimatland am Shop, die Währung am
        // Startpunkt (Spezifikation 4.0).
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => 99, 'title' => 'Markt', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 99, 'EUR');
    }

    public function testAPriceThatBeganAfterTheRowWasWrittenIsDue(): void
    {
        $this->price(validFrom: self::NOW - 60);
        $this->row(tstamp: self::LONG_AGO);

        self::assertSame([1], $this->due());
    }

    public function testARowWrittenAfterTheBeginningIsNotDue(): void
    {
        // Schon umgeschaltet — beim nächsten Lauf nicht noch einmal.
        $this->price(validFrom: self::NOW - 60);
        $this->row(tstamp: self::NOW - 30);

        self::assertSame([], $this->due());
    }

    public function testAPriceThatBeginsLaterIsNotDue(): void
    {
        $this->price(validFrom: self::NOW + 60);
        $this->row(tstamp: self::LONG_AGO);

        self::assertSame([], $this->due());
    }

    public function testTheFirstPriceOfAProductIsDueOnceItBegins(): void
    {
        $this->price(validFrom: self::NOW - 60);

        self::assertSame([1], $this->due());
    }

    public function testAPriceThatBeganAndEndedWithoutARowIsNeverDue(): void
    {
        // Sonst würde es jede Minute neu gerechnet, ohne dass je eine Zeile entsteht.
        $this->price(validFrom: self::NOW - 120, validTo: self::NOW - 60);

        self::assertSame([], $this->due());
    }

    public function testAnEndedPriceIsDueWhileItsRowIsOlderThanTheEnd(): void
    {
        $this->price(validTo: self::NOW - 60);
        $this->row(tstamp: self::LONG_AGO);

        self::assertSame([1], $this->due());
    }

    public function testAnEndedPriceWithoutARowIsNotDue(): void
    {
        $this->price(validTo: self::NOW - 60);

        self::assertSame([], $this->due());
    }

    public function testOnlyRowsThatCanBeAListPriceCount(): void
    {
        // Gruppen- und Staffelpreise stehen nicht in der Projektion.
        $this->price(validFrom: self::NOW - 60, customerGroup: 3);
        $this->price(validFrom: self::NOW - 60, minQuantity: '10.0000');

        self::assertSame([], $this->due());
    }

    public function testEinAbopreisKannDieKachelZahlSein(): void
    {
        // Abo-2b (P88): Ein Produkt ohne Einmalkauf steht mit seinem ersten
        // Modell in der Projektion — dessen Preisgrenze ist fällig wie jede.
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'validFrom' => self::NOW - 60, 'subscriptionRule' => 5]);
        $this->row(tstamp: self::LONG_AGO);

        self::assertSame([1], $this->due());
    }

    public function testTheRowOfTheSameCurrencyDecides(): void
    {
        $this->price(validFrom: self::NOW - 60);
        $this->row(tstamp: self::NOW, currency: 'CHF');

        self::assertSame([1], $this->due());
    }

    public function testAPriceInACurrencyNoShopSellsInIsNeverDue(): void
    {
        // Seit C25 entsteht eine Zeile nur je Markt. Ein CHF-Preis ohne CHF-Shop
        // bekommt nie eine — er wäre sonst jede Minute fällig, ohne dass sich
        // etwas ändert.
        $this->insertPrice(['pid' => 1, 'currency' => 'CHF', 'net' => '10.0000', 'validFrom' => self::NOW - 60]);

        self::assertSame([], $this->due());
    }

    public function testEinZweiterStartpunktDesselbenShopsMachtSeineWaehrungFaellig(): void
    {
        // C-1c: Die österreichische Firma verkauft über /en/ in Pfund. Vorher
        // wäre dafür ein zweiter Shop nötig gewesen; jetzt ist es ein zweiter
        // Startpunkt, und der GBP-Preis bekommt dadurch seine Zeile.
        $this->insertRootPage(2, 'en', 99, 'GBP');
        $this->insertPrice(['pid' => 1, 'currency' => 'GBP', 'net' => '10.0000', 'validFrom' => self::NOW - 60]);

        self::assertSame([1], $this->due());
    }

    public function testEinZweiterStartpunktDerselbenWaehrungAendertDieZeilenzahlNicht(): void
    {
        // Der Prüfstein: Solange nur eine Währung im Spiel ist, darf der Verbund
        // über die Startpunkte nichts doppelt zählen — DISTINCT im Markt-Verbund.
        $this->insertRootPage(2, 'en', 99, 'EUR');
        $this->price(validFrom: self::NOW - 60);
        $this->row(tstamp: self::LONG_AGO);

        self::assertSame([1], $this->due());
    }

    public function testEinDepublizierterStartpunktHaeltSeineWaehrungFaellig(): void
    {
        // Ohne published-Filter (siehe DbalMarketRepository): Wer den GBP-
        // Startpunkt depubliziert, nimmt seinen Preiszeilen nicht das Umschalten.
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'language' => 'en', 'consho_shop' => 99, 'consho_currency' => 'GBP', 'published' => 0]);
        $this->insertPrice(['pid' => 1, 'currency' => 'GBP', 'net' => '10.0000', 'validFrom' => self::NOW - 60]);

        self::assertSame([1], $this->due());
    }

    public function testEinStartpunktOhneWaehrungMachtKeinenPreisFaellig(): void
    {
        // Er zeigt nichts an; ein Preis „in gar keiner Währung" gibt es nicht.
        $this->connection->update('tl_page', ['consho_currency' => ''], ['id' => 1]);
        $this->price(validFrom: self::NOW - 60);

        self::assertSame([], $this->due());
    }

    public function testDieLeereWaehrungTrifftSichNichtSelbst(): void
    {
        // ⚠️ Der Fall, der ohne die Bedingung `consho_currency <> ''` still
        // durchginge: tl_consho_price.currency hat seit 2026-09-21 eine leere
        // Vorgabe, damit eine an der Maske vorbei geschriebene Zeile nicht zum
        // Euro-Preis wird. Stünde ein leerer Startpunkt als Markt ('', 'AT') da,
        // fände er über `m.currency = p.currency` genau diese Zeile — und die
        // Projektion bekäme eine Zeile für eine Währung, die es nicht gibt.
        $this->connection->update('tl_page', ['consho_currency' => ''], ['id' => 1]);
        $this->insertPrice(['pid' => 1, 'currency' => '', 'net' => '10.0000', 'validFrom' => self::NOW - 60]);

        self::assertSame([], $this->due());
    }

    public function testEineSeiteDieKeinStartpunktIstMachtKeinenPreisFaellig(): void
    {
        // consho_currency steht auf jeder Seite. Ein Restwert auf einer Seite,
        // die einmal Startpunkt war, ist kein Markt.
        $this->connection->update('tl_page', ['type' => 'regular'], ['id' => 1]);
        $this->price(validFrom: self::NOW - 60);

        self::assertSame([], $this->due());
    }

    public function testEinStartpunktEinerFremdenWebsiteMachtKeinenPreisFaellig(): void
    {
        // Ohne Shop kein Heimatland, ohne Heimatland kein Brutto.
        $this->connection->update('tl_page', ['consho_shop' => 0], ['id' => 1]);
        $this->price(validFrom: self::NOW - 60);

        self::assertSame([], $this->due());
    }

    public function testAPriceInAShopWithoutAHomeCountryIsNeverDue(): void
    {
        // Nach dem Deploy von C-1a der Normalfall, bis jemand das Heimatland pflegt.
        $this->connection->update('tl_consho_shop', ['country' => ''], ['id' => 99]);
        $this->price(validFrom: self::NOW - 60);

        self::assertSame([], $this->due());
    }

    public function testEineNieGespeichertePreiszeileIstNieFaellig(): void
    {
        // tstamp 0: die Preismaske wurde geöffnet, aber nie gespeichert — anders
        // als testTheFirstPriceOfAProductIsDueOnceItBegins, wo die Zeile steht.
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'validFrom' => self::NOW - 60, 'tstamp' => 0]);

        self::assertSame([], $this->due());
    }

    public function testAHangingMarketWithoutARateNeverInvalidatesOnceTheOtherMarketIsCorrect(): void
    {
        // I-1: zwei Euro-Shops, AT und DE — nur AT hat einen Satz. Die DE-Zeile
        // entsteht nie (TaxRateNotFound), der Preis bleibt deshalb jede Minute
        // fällig (productIdsDue joint über die Märkte). Die AT-Zeile ist aber
        // schon korrekt geschrieben — refresh() zählt keine echte Änderung mehr,
        // also darf nichts invalidiert werden.
        $this->insertShop(['id' => 100, 'title' => 'DE-Markt', 'country' => 'DE']);
        $this->insertRootPage(2, 'de', 100, 'EUR');
        $this->insertProduct(['id' => 1, 'pid' => 0]);
        $this->price(validFrom: self::NOW - 60);
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'tstamp' => self::LONG_AGO]);

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::never())->method('invalidateTags');

        (new ListPriceBoundaryCron(
            $this->connection,
            $this->realProjector(),
            null,
            new CatalogCacheTags($this->connection, $tags),
            null,
            null,
            null,
            static fn (): int => self::NOW,
        ))();
    }

    public function testASwitchedPriceInvalidatesTheSitemap(): void
    {
        // Contao hält die Sitemap einen Monat im Cache; beim Umschalten speichert
        // niemand etwas im Backend, also muss der Cron selbst entwerten.
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'validFrom' => time() - 60]);

        // Nur die Sitemaps der Startpunkte mit Shop, wie Contao je Startpunkt entwertet.
        $this->startPoints();

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with(['contao.sitemap.1']);

        (new ListPriceBoundaryCron($this->connection, $this->writingProjector(), new SitemapCacheTags($this->connection, $tags)))();
    }

    /**
     * Block H (H-3): eine Katalogregel, die beginnt, schaltet die Kacheln und
     * die Produktseiten um — niemand speichert in dem Moment etwas.
     */
    public function testEineBeginnendePreisregelMachtIhreProdukteFaelligUndEntwertetDieSeiten(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_price_rule');
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1, 'taxClass' => 1]);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000']);
        $this->startPoints();
        $this->connection->insert('tl_consho_price_rule', ['tstamp' => 1, 'title' => 'Aktion', 'published' => 1, 'percent' => '20', 'validFrom' => self::NOW - 30]);

        $state = new ProjectionState($this->connection);
        $state->set(ProjectionState::LIST_PRICE_TAX_BOUNDARIES, self::NOW - 60);

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with([CatalogCacheTags::LISTINGS, CatalogCacheTags::CATALOG]);

        (new ListPriceBoundaryCron($this->connection, $this->writingProjector(), null, new CatalogCacheTags($this->connection, $tags), null, $state, null, static fn (): int => self::NOW, new CatalogPriceRules($this->connection)))();
    }

    public function testASwitchedPriceInvalidatesTheListings(): void
    {
        // Die Listen zeigen, filtern und sortieren nach der Projektion (B-7b).
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'validFrom' => time() - 60]);
        $this->startPoints();

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with([CatalogCacheTags::LISTINGS]);

        (new ListPriceBoundaryCron($this->connection, $this->writingProjector(), null, new CatalogCacheTags($this->connection, $tags)))();
    }

    public function testASwitchedPriceRefreshesTheVisibilityProjectionToo(): void
    {
        // B-10: „bepreist" und Ab-Preis hängen an der Listenpreis-Projektion.
        // Der Preis-Projektor hier schreibt über einen Mock; die Zeile, die er
        // geschrieben hätte, steht deshalb schon da — älter als der Beginn,
        // sonst gälte der Preis als schon umgeschaltet und wäre nicht fällig.
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'validFrom' => time() - 60]);
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'tstamp' => time() - 3600]);
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1]);
        // Shop und Startpunkt stehen schon aus setUp() — Shop 99 in AT, ein
        // deutscher Euro-Startpunkt darunter.
        $this->insertProductShop(['pid' => 1, 'shop' => 99]);

        $visibility = new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        );

        (new ListPriceBoundaryCron($this->connection, $this->writingProjector(), null, null, $visibility))();

        self::assertSame('1', (string) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility WHERE pid = 1 AND variant = 0'));
    }

    public function testNothingDueInvalidatesNoListing(): void
    {
        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::never())->method('invalidateTags');

        (new ListPriceBoundaryCron($this->connection, $this->writingProjector(), null, new CatalogCacheTags($this->connection, $tags)))();
    }

    public function testNothingDueInvalidatesNothing(): void
    {
        $this->startPoints();

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::never())->method('invalidateTags');

        (new ListPriceBoundaryCron($this->connection, $this->writingProjector(), new SitemapCacheTags($this->connection, $tags)))();
    }

    // ── Steuersätze (C25) ───────────────────────────────────────────────

    public function testTheArticlesOfAClassWhoseRateChangedSinceTheLastRunAreDue(): void
    {
        $this->insertProduct(['id' => 1, 'pid' => 0, 'taxClass' => 1]);
        $this->insertProduct(['id' => 10, 'pid' => 1, 'taxClass' => 1]);
        $this->insertProduct(['id' => 2, 'pid' => 0, 'taxClass' => 2]);

        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->expects(self::once())->method('findClassesWithBoundaryIn')->with(self::LONG_AGO, self::NOW)->willReturn([1]);

        $cron = new ListPriceBoundaryCron($this->connection, $this->writingProjector(), null, null, null, new ProjectionState($this->connection), $rates);

        self::assertSame([1, 10], $cron->productIdsWithTaxBoundary(self::LONG_AGO, self::NOW));
    }

    public function testTheFirstRunOnlySetsTheMarker(): void
    {
        // Ohne Marker ist nichts bekannt, was seither fällig wurde — den Stand
        // davor baut die Migration (ListPriceMarketMigration).
        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->expects(self::never())->method('findClassesWithBoundaryIn');
        $state = new ProjectionState($this->connection);
        $clock = static fn (): int => self::NOW;

        (new ListPriceBoundaryCron($this->connection, $this->writingProjector(), null, null, null, $state, $rates, $clock))();

        self::assertSame(self::NOW, $state->get(ProjectionState::LIST_PRICE_TAX_BOUNDARIES));
    }

    public function testEveryRunMovesTheMarkerOnEvenWithoutABoundary(): void
    {
        $state = new ProjectionState($this->connection);
        $state->set(ProjectionState::LIST_PRICE_TAX_BOUNDARIES, self::LONG_AGO);

        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->expects(self::once())->method('findClassesWithBoundaryIn')->with(self::LONG_AGO, self::NOW)->willReturn([]);
        $clock = static fn (): int => self::NOW;

        (new ListPriceBoundaryCron($this->connection, $this->writingProjector(), null, null, null, $state, $rates, $clock))();

        self::assertSame(self::NOW, $state->get(ProjectionState::LIST_PRICE_TAX_BOUNDARIES));
    }

    /**
     * Ablauftest mit echtem Projektor gegen SQLite (wie
     * CatalogRefreshListenerTest::listener()): ersetzt man in __invoke() den
     * Steuerteil durch [] statt productIdsWithTaxBoundary(), bleibt dieser
     * Test rot — anders als ein Test, der nur die Methode isoliert aufruft.
     */
    public function testEinBeginnenderSteuersatzGibtDemArtikelSeineZeile(): void
    {
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1, 'taxClass' => 1]);
        // Ohne validFrom/validTo: nur der Steuerpfad macht das Produkt fällig.
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000']);

        $state = new ProjectionState($this->connection);
        $state->set(ProjectionState::LIST_PRICE_TAX_BOUNDARIES, self::LONG_AGO);

        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->expects(self::once())->method('findClassesWithBoundaryIn')->with(self::LONG_AGO, self::NOW)->willReturn([1]);

        (new ListPriceBoundaryCron(
            $this->connection,
            $this->realProjector(),
            null,
            null,
            null,
            $state,
            $rates,
            static fn (): int => self::NOW,
        ))();

        $rows = $this->connection->fetchAllAssociative('SELECT pid, currency, country, gross, tstamp FROM tl_consho_product_list_price');

        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['pid']);
        self::assertSame('EUR', $rows[0]['currency']);
        self::assertSame('AT', $rows[0]['country']);
        // SQLite liefert NUMERIC uneinheitlich zurück — deshalb Vergleich per sprintf.
        self::assertSame('12.0000', \sprintf('%.4F', $rows[0]['gross']));
        self::assertSame(self::NOW, $state->get(ProjectionState::LIST_PRICE_TAX_BOUNDARIES));

        // Merkwert statt des tstamp aus dem ersten Lauf: Der Schreiber setzt
        // time(), und beide Läufe liegen in derselben Sekunde — ein fälschlich
        // neu geschriebener tstamp sähe genauso aus wie der alte.
        $this->connection->executeStatement('UPDATE tl_consho_product_list_price SET tstamp = 1 WHERE pid = 1');

        // Zweiter Lauf: keine neue Grenze mehr — die Zeile bleibt unangetastet.
        $ratesSecondRun = $this->createMock(TaxRateRepositoryInterface::class);
        $ratesSecondRun->expects(self::once())->method('findClassesWithBoundaryIn')->with(self::NOW, self::NOW + 60)->willReturn([]);

        (new ListPriceBoundaryCron(
            $this->connection,
            $this->realProjector(),
            null,
            null,
            null,
            $state,
            $ratesSecondRun,
            static fn (): int => self::NOW + 60,
        ))();

        $rowsAfterSecondRun = $this->connection->fetchAllAssociative('SELECT tstamp FROM tl_consho_product_list_price WHERE pid = 1');

        self::assertCount(1, $rowsAfterSecondRun);
        self::assertSame(1, (int) $rowsAfterSecondRun[0]['tstamp']);
    }

    public function testEineSteuersatzgrenzeMitAenderungInvalidiertListingsUndCatalog(): void
    {
        // I-2: die Detailseite trägt CATALOG und den eigenen Produkt-Tag, nicht
        // LISTINGS — eine Satzgrenze muss deshalb beides invalidieren. Die Zeile
        // fehlt hier noch (anders als im I-1-Test), refresh() schreibt sie also
        // neu und zählt das als Änderung.
        $this->insertProduct(['id' => 1, 'pid' => 0, 'taxClass' => 1]);

        $state = new ProjectionState($this->connection);
        $state->set(ProjectionState::LIST_PRICE_TAX_BOUNDARIES, self::LONG_AGO);

        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->method('findClassesWithBoundaryIn')->with(self::LONG_AGO, self::NOW)->willReturn([1]);

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with([CatalogCacheTags::LISTINGS, CatalogCacheTags::CATALOG]);

        (new ListPriceBoundaryCron(
            $this->connection,
            $this->writingProjector(),
            null,
            new CatalogCacheTags($this->connection, $tags),
            null,
            $state,
            $rates,
            static fn (): int => self::NOW,
        ))();
    }

    public function testEineWiederholteSteuersatzgrenzeOhneAenderungInvalidiertTrotzdem(): void
    {
        // I-A: Ein Lauf schrieb die Listenpreise schon und scheiterte danach
        // (Zeitlimit in der Sichtbarkeit) — der Marker blieb stehen. Die
        // Wiederholung findet die neuen Beträge bereits vor, refresh() zählt 0.
        // Ohne die Satzgrenze in der Bedingung blieben Kachel und Caches alt.
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1, 'taxClass' => 1]);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000']);
        $this->row(self::LONG_AGO);

        $state = new ProjectionState($this->connection);
        $state->set(ProjectionState::LIST_PRICE_TAX_BOUNDARIES, self::LONG_AGO);

        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->method('findClassesWithBoundaryIn')->with(self::LONG_AGO, self::NOW)->willReturn([1]);

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with([CatalogCacheTags::LISTINGS, CatalogCacheTags::CATALOG]);

        (new ListPriceBoundaryCron(
            $this->connection,
            $this->realProjector(),
            null,
            new CatalogCacheTags($this->connection, $tags),
            null,
            $state,
            $rates,
            static fn (): int => self::NOW,
        ))();

        // Der Betrag stand schon richtig da — es war wirklich keine Änderung.
        self::assertSame('12.0000', \sprintf('%.4F', $this->connection->fetchOne('SELECT gross FROM tl_consho_product_list_price WHERE pid = 1')));
    }

    public function testDerMarkerLaeuftNichtZurueck(): void
    {
        // Uhrensprung oder überlappende Läufe: $last liegt schon nach $now, der
        // Steuerpfad läuft also gar nicht erst an — und der Marker darf trotzdem
        // nicht auf den älteren $now zurückfallen.
        $state = new ProjectionState($this->connection);
        $state->set(ProjectionState::LIST_PRICE_TAX_BOUNDARIES, self::NOW + 100);

        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->expects(self::never())->method('findClassesWithBoundaryIn');

        (new ListPriceBoundaryCron(
            $this->connection,
            $this->writingProjector(),
            null,
            null,
            null,
            $state,
            $rates,
            static fn (): int => self::NOW,
        ))();

        self::assertSame(self::NOW + 100, $state->get(ProjectionState::LIST_PRICE_TAX_BOUNDARIES));
    }

    /**
     * Startpunkt 1 mit Shop steht schon aus setUp() — hier kommt Startpunkt 2
     * ohne Shop dazu, eine fremde Website in derselben Installation.
     */
    private function startPoints(): void
    {
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'consho_shop' => 0]);
    }

    /**
     * Ein Projektor, der für Produkt 1 tatsächlich eine Zeile schreibt.
     */
    private function writingProjector(): ListPriceProjector
    {
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findCurrencies')->willReturn(['EUR']);
        $repository->method('findCandidates')->willReturn([new PriceRow('10.0000', 'EUR', null, '1.0000')]);

        return $this->projectorWith($repository);
    }

    /**
     * Ein echter Projektor gegen SQLite (wie CatalogRefreshListenerTest::listener()),
     * für den Ablauftest, der den Steuerpfad end-to-end durchrechnet statt ihn
     * über einen Mock zu stellen.
     */
    private function realProjector(): ListPriceProjector
    {
        $repository = new DbalPriceRepository($this->connection);

        $resolver = new ArticlePriceResolver(
            new PriceCalculator($repository),
            $repository,
            new BasePriceCalculator(),
            $this->grossCalculator(),
            new DbalArticleTaxClasses($this->connection),
        );

        return new ListPriceProjector(
            $repository,
            $resolver,
            new SqliteProjectionWriter($this->connection),
            new DbalArticleTree($this->connection),
            new DbalMarketRepository($this->connection),
        );
    }

    /**
     * @return list<int>
     */
    private function due(): array
    {
        return (new ListPriceBoundaryCron($this->connection, $this->projectorWith($this->createMock(PriceRepositoryInterface::class))))->productIdsDue(self::NOW);
    }

    private function projectorWith(PriceRepositoryInterface $repository): ListPriceProjector
    {
        $markets = $this->createMock(MarketRepositoryInterface::class);
        $markets->method('findMarkets')->willReturn([new Market('EUR', 'AT')]);

        $taxClasses = $this->createMock(ArticleTaxClassesInterface::class);
        $taxClasses->method('taxClassOf')->willReturn(1);

        return new ListPriceProjector(
            $repository,
            new ArticlePriceResolver(new PriceCalculator($repository), $repository, new BasePriceCalculator(), $this->grossCalculator(), $taxClasses),
            $this->createMock(ProjectionWriterInterface::class),
            $this->createMock(ArticleTreeInterface::class),
            $markets,
        );
    }

    private function price(int|null $validFrom = null, int|null $validTo = null, int $customerGroup = 0, string $minQuantity = '1.0000'): void
    {
        $this->insertPrice([
            'pid' => 1,
            'currency' => 'EUR',
            'net' => '10.0000',
            'customerGroup' => $customerGroup,
            'minQuantity' => $minQuantity,
            'validFrom' => $validFrom,
            'validTo' => $validTo,
        ]);
    }

    private function row(int $tstamp, string $currency = 'EUR'): void
    {
        $this->insertListPrice(['pid' => 1, 'currency' => $currency, 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'tstamp' => $tstamp]);
    }
}

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

        // The market in which the tests' EUR prices are projected (C25) —
        // without it no row is created and no price is due. Since C-1c it
        // takes two rows: the home country on the shop, the currency on the
        // start point (specification 4.0).
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
        // Already switched — not again on the next run.
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
        // Otherwise it would be recalculated every minute without a row ever being created.
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
        // Group and tier prices are not in the projection.
        $this->price(validFrom: self::NOW - 60, customerGroup: 3);
        $this->price(validFrom: self::NOW - 60, minQuantity: '10.0000');

        self::assertSame([], $this->due());
    }

    public function testEinAbopreisKannDieKachelZahlSein(): void
    {
        // Abo-2b (P88): A product without a one-time purchase is in the projection
        // with its first plan — that plan's price boundary is due like any other.
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
        // Since C25 a row is only created per market. A CHF price without a CHF
        // shop never gets one — otherwise it would be due every minute without
        // anything changing.
        $this->insertPrice(['pid' => 1, 'currency' => 'CHF', 'net' => '10.0000', 'validFrom' => self::NOW - 60]);

        self::assertSame([], $this->due());
    }

    public function testEinZweiterStartpunktDesselbenShopsMachtSeineWaehrungFaellig(): void
    {
        // C-1c: The Austrian company sells in pounds via /en/. Before, that
        // would have needed a second shop; now it is a second start point,
        // and the GBP price gets its row through it.
        $this->insertRootPage(2, 'en', 99, 'GBP');
        $this->insertPrice(['pid' => 1, 'currency' => 'GBP', 'net' => '10.0000', 'validFrom' => self::NOW - 60]);

        self::assertSame([1], $this->due());
    }

    public function testEinZweiterStartpunktDerselbenWaehrungAendertDieZeilenzahlNicht(): void
    {
        // The touchstone: as long as only one currency is involved, the join
        // over the start points must not count anything twice — DISTINCT in the market join.
        $this->insertRootPage(2, 'en', 99, 'EUR');
        $this->price(validFrom: self::NOW - 60);
        $this->row(tstamp: self::LONG_AGO);

        self::assertSame([1], $this->due());
    }

    public function testEinDepublizierterStartpunktHaeltSeineWaehrungFaellig(): void
    {
        // Without a published filter (see DbalMarketRepository): unpublishing the
        // GBP start point does not take the switch away from its price rows.
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'language' => 'en', 'consho_shop' => 99, 'consho_currency' => 'GBP', 'published' => 0]);
        $this->insertPrice(['pid' => 1, 'currency' => 'GBP', 'net' => '10.0000', 'validFrom' => self::NOW - 60]);

        self::assertSame([1], $this->due());
    }

    public function testEinStartpunktOhneWaehrungMachtKeinenPreisFaellig(): void
    {
        // It displays nothing; a price "in no currency at all" does not exist.
        $this->connection->update('tl_page', ['consho_currency' => ''], ['id' => 1]);
        $this->price(validFrom: self::NOW - 60);

        self::assertSame([], $this->due());
    }

    public function testDieLeereWaehrungTrifftSichNichtSelbst(): void
    {
        // ⚠️ The case that would slip through silently without the condition
        // `consho_currency <> ''`: tl_consho_price.currency has an empty default,
        // so that a row written past the form does not become a euro price.
        // If an empty start point stood as a market ('', 'AT'), it would find
        // exactly this row via `m.currency = p.currency` — and the projection
        // would get a row for a currency that does not exist.
        $this->connection->update('tl_page', ['consho_currency' => ''], ['id' => 1]);
        $this->insertPrice(['pid' => 1, 'currency' => '', 'net' => '10.0000', 'validFrom' => self::NOW - 60]);

        self::assertSame([], $this->due());
    }

    public function testEineSeiteDieKeinStartpunktIstMachtKeinenPreisFaellig(): void
    {
        // consho_currency is on every page. A leftover value on a page that was
        // once a start point is not a market.
        $this->connection->update('tl_page', ['type' => 'regular'], ['id' => 1]);
        $this->price(validFrom: self::NOW - 60);

        self::assertSame([], $this->due());
    }

    public function testEinStartpunktEinerFremdenWebsiteMachtKeinenPreisFaellig(): void
    {
        // No shop, no home country; no home country, no gross price.
        $this->connection->update('tl_page', ['consho_shop' => 0], ['id' => 1]);
        $this->price(validFrom: self::NOW - 60);

        self::assertSame([], $this->due());
    }

    public function testAPriceInAShopWithoutAHomeCountryIsNeverDue(): void
    {
        // The normal case right after upgrading to C-1a, until someone fills in the home country.
        $this->connection->update('tl_consho_shop', ['country' => ''], ['id' => 99]);
        $this->price(validFrom: self::NOW - 60);

        self::assertSame([], $this->due());
    }

    public function testEineNieGespeichertePreiszeileIstNieFaellig(): void
    {
        // tstamp 0: the price form was opened but never saved — unlike
        // testTheFirstPriceOfAProductIsDueOnceItBegins, where the row exists.
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'validFrom' => self::NOW - 60, 'tstamp' => 0]);

        self::assertSame([], $this->due());
    }

    public function testAHangingMarketWithoutARateNeverInvalidatesOnceTheOtherMarketIsCorrect(): void
    {
        // I-1: two euro shops, AT and DE — only AT has a rate. The DE row is
        // never created (TaxRateNotFound), so the price stays due every minute
        // (productIdsDue joins over the markets). The AT row, however, is
        // already written correctly — refresh() counts no real change any more,
        // so nothing may be invalidated.
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
        // Contao keeps the sitemap in the cache for a month; at the switch
        // nobody saves anything in the back end, so the cron must invalidate it itself.
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'validFrom' => time() - 60]);

        // Only the sitemaps of start points with a shop, the way Contao invalidates per start point.
        $this->startPoints();

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with(['contao.sitemap.1']);

        (new ListPriceBoundaryCron($this->connection, $this->writingProjector(), new SitemapCacheTags($this->connection, $tags)))();
    }

    /**
     * Block H (H-3): a catalogue rule that begins switches the tiles and
     * the product pages — nobody saves anything at that moment.
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
        // The lists display, filter and sort by the projection (B-7b).
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'validFrom' => time() - 60]);
        $this->startPoints();

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with([CatalogCacheTags::LISTINGS]);

        (new ListPriceBoundaryCron($this->connection, $this->writingProjector(), null, new CatalogCacheTags($this->connection, $tags)))();
    }

    public function testASwitchedPriceRefreshesTheVisibilityProjectionToo(): void
    {
        // B-10: "priced" and the from-price depend on the list price projection.
        // The price projector here writes through a mock; the row it would have
        // written is therefore already there — older than the start, otherwise
        // the price would count as already switched and would not be due.
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'validFrom' => time() - 60]);
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'tstamp' => time() - 3600]);
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1]);
        // Shop and start point already exist from setUp() — shop 99 in AT, a
        // German euro start point below it.
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

    // ── Tax rates (C25) ─────────────────────────────────────────────────

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
        // Without a marker nothing is known that has become due since — the state
        // before that is built by the migration (ListPriceMarketMigration).
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
     * Flow test with a real projector against SQLite (like
     * CatalogRefreshListenerTest::listener()): if the tax part in __invoke() is
     * replaced by [] instead of productIdsWithTaxBoundary(), this test stays
     * red — unlike a test that only calls the method in isolation.
     */
    public function testEinBeginnenderSteuersatzGibtDemArtikelSeineZeile(): void
    {
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1, 'taxClass' => 1]);
        // Without validFrom/validTo: only the tax path makes the product due.
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
        // SQLite returns NUMERIC inconsistently — hence the comparison via sprintf.
        self::assertSame('12.0000', \sprintf('%.4F', $rows[0]['gross']));
        self::assertSame(self::NOW, $state->get(ProjectionState::LIST_PRICE_TAX_BOUNDARIES));

        // A marker value instead of the tstamp from the first run: the writer sets
        // time(), and both runs fall in the same second — a tstamp rewritten by
        // mistake would look exactly like the old one.
        $this->connection->executeStatement('UPDATE tl_consho_product_list_price SET tstamp = 1 WHERE pid = 1');

        // Second run: no new boundary any more — the row stays untouched.
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
        // I-2: the detail page carries CATALOG and its own product tag, not
        // LISTINGS — a rate boundary must therefore invalidate both. The row is
        // still missing here (unlike in the I-1 test), so refresh() writes it
        // anew and counts that as a change.
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
        // I-A: A run had already written the list prices and failed afterwards
        // (time limit in the visibility) — the marker stayed where it was. The
        // retry finds the new amounts already in place, refresh() counts 0.
        // Without the rate boundary in the condition, tile and caches would stay old.
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

        // The amount was already correct — there really was no change.
        self::assertSame('12.0000', \sprintf('%.4F', $this->connection->fetchOne('SELECT gross FROM tl_consho_product_list_price WHERE pid = 1')));
    }

    public function testDerMarkerLaeuftNichtZurueck(): void
    {
        // Clock jump or overlapping runs: $last is already after $now, so the
        // tax path does not even start — and the marker must still not fall
        // back to the older $now.
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
     * Start point 1 with a shop already exists from setUp() — here start point 2
     * without a shop is added, a foreign website in the same installation.
     */
    private function startPoints(): void
    {
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'consho_shop' => 0]);
    }

    /**
     * A projector that actually writes a row for product 1.
     */
    private function writingProjector(): ListPriceProjector
    {
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findCurrencies')->willReturn(['EUR']);
        $repository->method('findCandidates')->willReturn([new PriceRow('10.0000', 'EUR', null, '1.0000')]);

        return $this->projectorWith($repository);
    }

    /**
     * A real projector against SQLite (like CatalogRefreshListenerTest::listener()),
     * for the flow test that computes the tax path end to end instead of
     * stubbing it with a mock.
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener;

use Contao\CoreBundle\Cache\CacheTagManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\EventListener\CatalogRefreshListener;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTaxClasses;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTree;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalMarketRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\SoldOutMarks;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Pricing\SqliteProjectionWriter;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * At the end of the request: list prices, then visibility, then shops (B-10).
 * With both real projectors against SQLite — the order can only be seen on
 * real tables. Shop 1 in EUR, root page de.
 */
class CatalogRefreshListenerTest extends TestCase
{
    use CatalogSchema;
    use TaxFixture;

    private Connection $connection;

    private CatalogRefreshQueue $queue;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);

        // Product 1, visible; its price is only in the price table, not yet in
        // the list price projection.
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 1]);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000']);

        $this->queue = new CatalogRefreshQueue();
    }

    public function testTheListPricesComeFirstThenTheVisibility(): void
    {
        // The other way round, the visibility would not find a list price yet —
        // the product would be missing from every list until the next change.
        $this->queue->add(1);

        $this->listener()();

        self::assertSame(
            [['pid' => 1, 'gross' => '12.0000']],
            array_map(
                static fn (array $row): array => ['pid' => (int) $row['pid'], 'gross' => \sprintf('%.4F', $row['gross'])],
                $this->connection->fetchAllAssociative('SELECT pid, gross FROM tl_consho_product_visibility WHERE variant = 0'),
            ),
        );
    }

    public function testAChangeInvalidatesTheListings(): void
    {
        $this->queue->add(1);

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with([CatalogCacheTags::LISTINGS]);

        $this->listener($tags)();
    }

    public function testAnUnchangedProjectionInvalidatesNothing(): void
    {
        $this->queue->add(1);
        $this->listener()();

        // Saved once more, nothing changed (say, only the title).
        $this->queue->add(1);

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::never())->method('invalidateTags');

        $this->listener($tags)();
    }

    public function testWithoutReportsNothingHappens(): void
    {
        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::never())->method('invalidateTags');

        $this->listener($tags)();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility'));
    }

    public function testAShopSyncBuildsANewLanguage(): void
    {
        $this->queue->add(1);
        $this->listener()();

        // A root page fr is added, the product is translated.
        $this->insertRootPage(2, 'fr', 1);
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => 'Produit', 'alias' => 'produit']);
        $this->queue->requestShopSync();

        $this->listener()();

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_product_visibility WHERE language = 'fr'"));
    }

    public function testChangingTheHomeCountryRecalculatesEveryGross(): void
    {
        // C25: home country from AT to DE — when saving the shop, without any
        // product or price being touched.
        $this->queue->add(1);
        $this->listener()();

        $this->connection->update('tl_consho_shop', ['country' => 'DE'], ['id' => 1]);
        $this->queue->requestShopSync();
        $this->listener(rates: ['AT' => '20.0000', 'DE' => '19.0000'])();

        self::assertSame(
            ['11.9000'],
            array_map(static fn (mixed $gross): string => \sprintf('%.4F', $gross), $this->connection->fetchFirstColumn("SELECT gross FROM tl_consho_product_visibility WHERE variant = 0 AND language = 'de'")),
        );
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_product_list_price WHERE country = 'AT'"));
    }

    public function testAProductAloneDoesNotTriggerTheMarketsComparison(): void
    {
        // M-2: only one product reported, no shop sync — the markets'
        // fingerprint must not be created then, or the expensive market
        // reconciliation would run on every product save instead of only on a
        // shop/page sync.
        $this->queue->add(1);
        $this->listener()();

        self::assertFalse((bool) $this->connection->fetchOne("SELECT 1 FROM tl_consho_projection_state WHERE name = 'list_price_markets'"));
    }

    /**
     * Block Verfügbarkeit, V1: the main product is saved, its variants are
     * marked — the "Manage stock" switch sits on the main product and decides
     * for them.
     */
    public function testEinGespeichertesProduktMarkiertSeineAusverkauftenVarianten(): void
    {
        $this->queue->add(1);
        $this->listener()();

        $this->insertProduct(['id' => 11, 'pid' => 1, 'published' => 1]);
        $this->insertProduct(['id' => 12, 'pid' => 1, 'published' => 1]);
        $this->connection->update('tl_consho_product', ['stockTracked' => 1], ['id' => 1]);
        (new StockRows($this->connection))->sync(1, 100);
        $this->connection->update('tl_consho_stock', ['quantity' => '3.0000'], ['article' => 12]);

        $this->queue->add(1);
        $this->listener(soldOut: true)();

        self::assertSame([11], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out')));
    }

    /**
     * Block Verfügbarkeit: the main product is deleted, the variants go along
     * as child records — only the main product is reported.
     */
    public function testDasLoeschenEinesHauptproduktsRaeumtDieMarkenSeinerVariantenAuf(): void
    {
        $this->connection->insert('tl_consho_sold_out', ['article' => 11]);
        $this->connection->insert('tl_consho_sold_out', ['article' => 12]);

        $this->queue->add(1);
        $this->listener(soldOut: true)();

        self::assertSame([], $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out'));
    }

    public function testEineNeueMarkeEntwertetDieListenAuchOhneProjektionsaenderung(): void
    {
        $this->queue->add(1);
        $this->listener()();

        $this->connection->update('tl_consho_product', ['stockTracked' => 1], ['id' => 1]);
        (new StockRows($this->connection))->sync(1, 100);
        $this->queue->add(1);

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with([CatalogCacheTags::LISTINGS]);

        $this->listener($tags, soldOut: true)();

        self::assertSame([1], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out')));
    }

    /**
     * @param array<string, string> $rates
     */
    private function listener(CacheTagManager|null $tags = null, array $rates = ['AT' => '20.0000'], bool $soldOut = false): CatalogRefreshListener
    {
        $repository = new DbalPriceRepository($this->connection);
        $resolver = new ArticlePriceResolver(new PriceCalculator($repository), $repository, new BasePriceCalculator(), $this->grossCalculator($rates), new DbalArticleTaxClasses($this->connection));

        return new CatalogRefreshListener(
            $this->queue,
            new ListPriceProjector($repository, $resolver, new SqliteProjectionWriter($this->connection), new DbalArticleTree($this->connection), new DbalMarketRepository($this->connection)),
            new VisibilityProjector(
                $this->connection,
                new VisibilityRows(new VisibilityCriteria()),
                new ShopLanguages($this->connection),
                new ProjectionState($this->connection),
            ),
            null === $tags ? null : new CatalogCacheTags($this->connection, $tags),
            null,
            $soldOut ? new SoldOutMarks($this->connection, new Availability($this->connection)) : null,
            $soldOut ? $this->connection : null,
        );
    }
}

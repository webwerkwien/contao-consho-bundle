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
 * Am Ende des Requests: Listenpreise, dann Sichtbarkeit, dann Shops (B-10).
 * Mit beiden echten Projektoren gegen SQLite — die Reihenfolge ist nur an
 * echten Tabellen zu sehen. Shop 1 in EUR, Startpunkt de.
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
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);

        // Produkt 1, sichtbar; sein Preis steht nur in der Preistabelle, noch
        // nicht in der Listenpreis-Projektion.
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 1]);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000']);

        $this->queue = new CatalogRefreshQueue();
    }

    public function testTheListPricesComeFirstThenTheVisibility(): void
    {
        // Andersherum fände die Sichtbarkeit noch keinen Listenpreis — das
        // Produkt fehlte in jeder Liste, bis zur nächsten Änderung.
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

        // Noch einmal gespeichert, nichts geändert (etwa nur der Titel).
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

        // Eine Root-Seite fr kommt dazu, das Produkt ist übersetzt.
        $this->insertRootPage(2, 'fr', 1);
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => 'Produit', 'alias' => 'produit']);
        $this->queue->requestShopSync();

        $this->listener()();

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_product_visibility WHERE language = 'fr'"));
    }

    public function testChangingTheHomeCountryRecalculatesEveryGross(): void
    {
        // C25: Heimatland von AT auf DE — beim Speichern des Shops, ohne dass ein
        // Produkt oder Preis angefasst wurde.
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
        // M-2: nur ein Produkt gemeldet, kein Shop-Sync — der Fingerabdruck der
        // Märkte darf dabei nicht angelegt werden, sonst liefe der teure
        // Markt-Abgleich bei jedem Produktspeichern statt nur bei einem
        // Shop-/Seiten-Sync.
        $this->queue->add(1);
        $this->listener()();

        self::assertFalse((bool) $this->connection->fetchOne("SELECT 1 FROM tl_consho_projection_state WHERE name = 'list_price_markets'"));
    }

    /**
     * Block Verfügbarkeit, V1: Gespeichert wird das Hauptprodukt, markiert
     * werden seine Varianten — der Schalter „Bestand führen" sitzt am
     * Hauptprodukt und entscheidet über sie.
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
     * Review Verfügbarkeit, 3: Gelöscht wird das Hauptprodukt, die Varianten
     * gehen als Kinddatensätze mit — gemeldet wird nur das Hauptprodukt.
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

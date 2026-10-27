<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCoverage;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalPinRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterResolver;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingPageRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Start point 1 (de, shop 1, EUR, page cache 3600 s) with list page 12;
 * start point 2 without a shop with page 20. List 50 on page 12 with the
 * default "250 ml" (option 11).
 *
 * Product 1 has 250 ml, product 2 nothing, product 3 nothing but is pinned
 * in list 50, product 4 has no price (visible but not listed — the start
 * page already reports that).
 */
class CatalogCoverageTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, type TEXT NOT NULL DEFAULT 'regular', title TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '', published INTEGER NOT NULL DEFAULT 0, consho_shop INTEGER NOT NULL DEFAULT 0, consho_currency TEXT NOT NULL DEFAULT '', includeCache INTEGER NOT NULL DEFAULT 0, cache INTEGER NOT NULL DEFAULT 0)",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_article (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, sorting INTEGER NOT NULL DEFAULT 0, published INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_content (
                id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL DEFAULT 0, ptable TEXT NOT NULL DEFAULT 'tl_article',
                type TEXT NOT NULL DEFAULT '', sorting INTEGER NOT NULL DEFAULT 0, tstamp INTEGER NOT NULL DEFAULT 0,
                invisible INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '',
                conshoFilter BLOB, conshoShowCounts INTEGER NOT NULL DEFAULT 0, conshoFollowsAddress INTEGER NOT NULL DEFAULT 1,
                conshoPinsOnly INTEGER NOT NULL DEFAULT 0, conshoProduct INTEGER NOT NULL DEFAULT 0
            )",
        );
        DcaSchema::create($this->connection, 'tl_consho_facet_page');

        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        // consho_currency on the start point, not on the shop: since C-1c the
        // visibility projector reads the currency from here. Without it every
        // product would be unpriced and the projection empty.
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'title' => 'Shop DE', 'language' => 'de', 'published' => 1, 'consho_shop' => 1, 'consho_currency' => 'EUR', 'includeCache' => 1, 'cache' => 3600]);
        $this->connection->insert('tl_page', ['id' => 12, 'pid' => 1, 'title' => 'Kräuterbitter']);
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'title' => 'Andere Website', 'language' => 'en']);
        $this->connection->insert('tl_page', ['id' => 20, 'pid' => 2, 'title' => 'Fremd']);

        $this->insertPropertyGroup(['id' => 1, 'title' => 'Volumen', 'alias' => 'volume']);
        $this->insertPropertyOption(['id' => 11, 'pid' => 1, 'title' => '250 ml', 'alias' => '250ml']);

        $this->product(1, 'Schwedenbitter', option: 11);
        $this->product(2, 'Kräutersalz');
        $this->product(3, 'Kräuteressig');
        $this->product(4, 'E-Book', priced: false);

        $this->connection->insert('tl_article', ['id' => 120, 'pid' => 12, 'published' => 1]);
        $this->connection->insert('tl_content', ['id' => 50, 'pid' => 120, 'type' => 'consho_product_list', 'tstamp' => 1, 'conshoFilter' => serialize(['11'])]);
        $this->connection->insert('tl_content', ['pid' => 50, 'ptable' => 'tl_content', 'type' => 'consho_product_pin', 'conshoProduct' => 3, 'tstamp' => 1]);

        // unlisted() asks ProductQuery, which reads the projection since B-10.
        $this->project();
    }

    private function project(): void
    {
        (new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        ))->rebuild(self::NOW);
    }

    public function testNamesTheProductNoListingShows(): void
    {
        // 1 through the default, 3 through the pin, 4 without a price not visible at all.
        self::assertSame(
            [['rootId' => 1, 'rootTitle' => 'Shop DE', 'productId' => 2, 'title' => 'Kräutersalz']],
            $this->coverage()->unlisted(self::NOW),
        );
    }

    public function testAListingInALayoutSectionReachesEveryStartPoint(): void
    {
        $this->connection->insert('tl_content', ['pid' => 7, 'ptable' => 'tl_theme', 'type' => 'consho_product_list', 'tstamp' => 1]);

        self::assertSame([], $this->coverage()->unlisted(self::NOW));
    }

    public function testAPinsOnlyListingShowsOnlyItsPins(): void
    {
        $this->connection->update('tl_content', ['conshoPinsOnly' => 1, 'conshoFilter' => null], ['id' => 50]);

        // By title: Kräutersalz before Schwedenbitter.
        self::assertSame([2, 1], array_column($this->coverage()->unlisted(self::NOW), 'productId'));
    }

    public function testAListingOfAnotherWebsiteDoesNotCount(): void
    {
        $this->connection->update('tl_article', ['pid' => 20], ['id' => 120]);

        self::assertSame([3, 2, 1], array_column($this->coverage()->unlisted(self::NOW), 'productId'));
    }

    public function testAnInvisibleListingDoesNotCount(): void
    {
        $this->connection->update('tl_content', ['invisible' => 1], ['id' => 50]);

        self::assertCount(3, $this->coverage()->unlisted(self::NOW));
    }

    public function testAnUnpublishedStartPointIsNotVerifiedButStillDescribed(): void
    {
        // Start points without lists, such as ones set up for testing, would otherwise make verify fail permanently.
        $this->connection->update('tl_page', ['published' => 0], ['id' => 1]);

        self::assertSame([], $this->coverage()->unlisted(self::NOW));
        self::assertFalse($this->coverage()->summary(self::NOW)[0]['published']);
    }

    public function testEinStartpunktWirdInSeinerEigenenWaehrungGeprueft(): void
    {
        // The DACH case: a second German start point in francs. The
        // currency comes from the start point, the home country from the shop — the
        // same rule that MarketCriteria states for the list prices (Spec 4.0).
        //
        // If the report took the shop currency instead, it would check the
        // franc page in euros, and on both sides of the comparison: it
        // would report the same products for it as for the euro page, although
        // not a single one has a franc price there and the page in
        // reality cannot show anything.
        $this->connection->insert('tl_page', ['id' => 3, 'type' => 'root', 'title' => 'Shop CH', 'language' => 'de', 'published' => 1, 'consho_shop' => 1, 'consho_currency' => 'CHF']);
        $this->project();

        // Unchanged: only the euro start point has something to report.
        self::assertSame(
            [['rootId' => 1, 'rootTitle' => 'Shop DE', 'productId' => 2, 'title' => 'Kräutersalz']],
            $this->coverage()->unlisted(self::NOW),
        );

        // And the report says it explicitly: visible yes, priced no.
        $summary = $this->coverage()->summary(self::NOW);

        self::assertSame([4, 0], [$summary[1]['visible'], $summary[1]['priced']]);
        self::assertSame('Shop CH', $summary[1]['rootTitle']);
    }

    public function testNamesLandingPagesWhosePageIsGone(): void
    {
        $this->connection->insert('tl_consho_facet_page', ['id' => 8, 'pid' => 99, 'alias' => 'verwaist']);
        $this->connection->insert('tl_consho_facet_page', ['id' => 9, 'pid' => 12, 'alias' => 'schwedenbitter-250-ml']);

        self::assertSame([['id' => 8, 'pageId' => 99, 'alias' => 'verwaist']], $this->coverage()->orphanLandingPages());
    }

    /**
     * Contao stores page titles encoded ("Shop &#40;DE&#41;"). The console
     * showed that raw (Spec C, open points) — decoded like the product titles
     * already were before.
     */
    public function testStartPointTitlesAreDecoded(): void
    {
        $this->connection->update('tl_page', ['title' => 'Shop &#40;DE&#41; &amp; mehr'], ['id' => 1]);
        $coverage = $this->coverage();

        self::assertSame('Shop (DE) & mehr', $coverage->unlisted(self::NOW)[0]['rootTitle']);
        self::assertSame('Shop (DE) & mehr', $coverage->summary(self::NOW)[0]['rootTitle']);
    }

    public function testSummarisesEachStartPointWithAShop(): void
    {
        self::assertSame(
            [['rootId' => 1, 'rootTitle' => 'Shop DE', 'language' => 'de', 'published' => true, 'visible' => 4, 'priced' => 3, 'listings' => 1, 'unfiltered' => 0, 'pageCache' => 3600]],
            $this->coverage()->summary(self::NOW),
        );
    }

    public function testWithoutACacheSettingThePageCacheIsOff(): void
    {
        $this->connection->update('tl_page', ['includeCache' => 0, 'cache' => 0], ['id' => 1]);

        self::assertNull($this->coverage()->summary(self::NOW)[0]['pageCache']);
    }

    private function product(int $id, string $title, int|null $option = null, bool $priced = true): void
    {
        $this->insertProduct(['id' => $id, 'title' => $title, 'published' => 1]);
        $this->insertProductShop(['pid' => $id, 'shop' => 1]);

        if (null !== $option) {
            $this->insertProductProperty(['pid' => $id, 'optionId' => $option]);
        }

        if ($priced) {
            $this->insertListPrice(['pid' => $id, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10', 'gross' => '12']);
        }
    }

    private function coverage(): CatalogCoverage
    {
        $criteria = new VisibilityCriteria();
        $products = new ProductQuery($this->connection);

        return new CatalogCoverage(
            $this->connection,
            new ListingPageRepository($this->connection),
            new FilterResolver($this->connection),
            $products,
            new DbalPinRepository($this->connection),
            $criteria,
            new ShopLanguages($this->connection),
            new VisibilityRows($criteria),
        );
    }
}

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
 * Startpunkt 1 (de, Shop 1, EUR, Seiten-Cache 3600 s) mit Listenseite 12;
 * Startpunkt 2 ohne Shop mit Seite 20. Liste 50 auf Seite 12 mit Vorgabe
 * „250 ml" (Option 11).
 *
 * Produkt 1 hat 250 ml, Produkt 2 nichts, Produkt 3 nichts, ist aber in Liste 50
 * angeheftet, Produkt 4 hat keinen Preis (sichtbar, aber nicht gelistet — das
 * meldet schon die Startseite).
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
        // consho_currency am Startpunkt, nicht am Shop: Seit C-1c liest der
        // Sichtbarkeits-Projektor die Währung von hier. Ohne sie wäre jedes
        // Produkt unbepreist und die Projektion leer.
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

        // unlisted() fragt ProductQuery, und die liest seit B-10 die Projektion.
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
        // 1 über die Vorgabe, 3 über die Anheftung, 4 ohne Preis gar nicht sichtbar.
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

        // Nach Titel: Kräutersalz vor Schwedenbitter.
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
        // Auf c5 gemessen: Test-Startpunkte ohne Listen ließen verify dauerhaft scheitern.
        $this->connection->update('tl_page', ['published' => 0], ['id' => 1]);

        self::assertSame([], $this->coverage()->unlisted(self::NOW));
        self::assertFalse($this->coverage()->summary(self::NOW)[0]['published']);
    }

    public function testEinStartpunktWirdInSeinerEigenenWaehrungGeprueft(): void
    {
        // Der DACH-Fall: ein zweiter deutscher Startpunkt in Franken. Die
        // Währung kommt vom Startpunkt, das Heimatland vom Shop — dieselbe
        // Regel, die MarketCriteria für die Listenpreise ausspricht (Spec 4.0).
        //
        // Nahm der Bericht statt dessen die Shop-Währung, prüfte er die
        // Frankenseite in Euro, und zwar auf beiden Seiten des Vergleichs: Er
        // meldete für sie dieselben Produkte wie für die Euroseite, obwohl
        // dort kein einziges einen Frankenpreis hat und die Seite in
        // Wirklichkeit gar nichts zeigen kann.
        $this->connection->insert('tl_page', ['id' => 3, 'type' => 'root', 'title' => 'Shop CH', 'language' => 'de', 'published' => 1, 'consho_shop' => 1, 'consho_currency' => 'CHF']);
        $this->project();

        // Unverändert: nur der Euro-Startpunkt hat etwas zu melden.
        self::assertSame(
            [['rootId' => 1, 'rootTitle' => 'Shop DE', 'productId' => 2, 'title' => 'Kräutersalz']],
            $this->coverage()->unlisted(self::NOW),
        );

        // Und im Bericht steht es ausdrücklich: sichtbar ja, bepreist nein.
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
     * Contao speichert Seitentitel kodiert („Shop &#40;DE&#41;"). Die Konsole
     * zeigte das roh (Spec C, offene Punkte) — wie die Produkttitel schon
     * vorher dekodiert.
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

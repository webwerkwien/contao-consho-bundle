<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Migration\ListPriceMarketMigration;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTaxClasses;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTree;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalMarketRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Pricing\SqliteProjectionWriter;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * After the update to C-1a all list price rows carry the country '' — no
 * market. Without a rebuild every list would be empty.
 */
class ListPriceMarketMigrationTest extends TestCase
{
    use CatalogSchema;
    use TaxFixture;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertRootPage(1, 'de', 1);
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 1]);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000']);
    }

    public function testBeforeTheSchemaUpdateItDoesNotRun(): void
    {
        // First pass of contao:migrate: the column does not exist yet.
        $this->insertShop(['id' => 1, 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->connection->executeStatement('DROP TABLE tl_consho_product_list_price');
        $this->connection->executeStatement('CREATE TABLE tl_consho_product_list_price (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, pid INTEGER NOT NULL DEFAULT 0, currency TEXT NOT NULL DEFAULT \'\', net NUMERIC NOT NULL DEFAULT 0, gross NUMERIC NOT NULL DEFAULT 0)');

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testOhneDieWaehrungsspalteAmStartpunktLaeuftSieNichtAn(): void
    {
        // The same gap as in VisibilityProjectionMigration, only one schema
        // change earlier: `country` came with C-1a, `consho_currency` only
        // with C-1c. If C-1a was created but the migration aborted, the first
        // pass after upgrading finds rows with country '' without
        // consho_currency existing yet — and both rebuilds in run() query
        // that column.
        $this->insertShop(['id' => 1, 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => '', 'net' => '10.0000', 'gross' => '12.0000']);

        $this->connection->executeStatement('DROP TABLE tl_page');
        $this->connection->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                type TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL DEFAULT '',
                language TEXT NOT NULL DEFAULT '',
                consho_shop INTEGER NOT NULL DEFAULT 0,
                published TEXT NOT NULL DEFAULT '1'
            )",
        );
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'language' => 'de', 'consho_shop' => 1]);

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testRowsFromBeforeTheHomeCountryAreRebuiltOnce(): void
    {
        $this->insertShop(['id' => 1, 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'gross' => '12.0000']);

        $migration = $this->migration();

        self::assertTrue($migration->shouldRun());
        self::assertTrue($migration->run()->isSuccessful());

        self::assertSame(
            [['country' => 'AT', 'gross' => '12.0000']],
            array_map(
                static fn (array $row): array => ['country' => (string) $row['country'], 'gross' => \sprintf('%.4F', $row['gross'])],
                $this->connection->fetchAllAssociative('SELECT country, gross FROM tl_consho_product_list_price'),
            ),
        );
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility WHERE variant = 0'));
        self::assertNotNull((new ProjectionState($this->connection))->get(ProjectionState::LIST_PRICE_TAX_BOUNDARIES));
        self::assertFalse($migration->shouldRun());
    }

    public function testAShopWithoutAHomeCountryDoesNotKeepTheMigrationPending(): void
    {
        // contao:migrate runs as long as a migration is due. Without a home country
        // there is no market — the old rows go, new ones are not created.
        $this->insertShop(['id' => 1, 'currency' => 'EUR', 'fallbackLanguage' => 'de']);
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'gross' => '12.0000']);

        $migration = $this->migration();
        $migration->run();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_list_price'));
        self::assertFalse($migration->shouldRun());
    }

    public function testRunAbortsLoudlyWhenTheOldUniqueKeyStillExists(): void
    {
        // M-1: contao:migrate drops the old key regularly; it only remains next
        // to the country column after a partially completed schema change.
        // If INSERT ... ON DUPLICATE KEY hit it instead of (pid, currency,
        // country), the projection would be silently emptied and the countries
        // mixed — that is why run() aborts loudly here instead of rebuilding.
        $this->insertShop(['id' => 1, 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);

        $this->connection->executeStatement('DROP TABLE tl_consho_product_list_price');
        $this->connection->executeStatement(
            'CREATE TABLE tl_consho_product_list_price (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tstamp INTEGER NOT NULL DEFAULT 0,
                pid INTEGER NOT NULL DEFAULT 0,
                currency TEXT NOT NULL DEFAULT \'\',
                country TEXT NOT NULL DEFAULT \'\',
                net NUMERIC NOT NULL DEFAULT 0.0000,
                gross NUMERIC NOT NULL DEFAULT 0.0000
            )',
        );
        // A named index, not the inline `UNIQUE (...)` table clause:
        // Doctrine's SQLite introspection does not report such an autoindex
        // via listTableIndexes() (unlike MySQL's SHOW INDEX, which sees it
        // like any named index) — with the table clause the detection would
        // stay unobservable in the test; the behaviour in MySQL itself is not
        // affected by this.
        $this->connection->executeStatement('CREATE UNIQUE INDEX old_pid_currency ON tl_consho_product_list_price (pid, currency)');
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => '', 'net' => '10.0000', 'gross' => '12.0000']);

        try {
            $this->migration()->run();
            self::fail('run() hätte abbrechen müssen.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('(pid, currency)', $e->getMessage());
        }

        // Aborted before anything was touched: the old row is still there.
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_product_list_price WHERE country = ''"));
    }

    public function testAProjectionWrittenWithCountriesIsLeftAlone(): void
    {
        $this->insertShop(['id' => 1, 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);

        self::assertFalse($this->migration()->shouldRun());
    }

    private function migration(): ListPriceMarketMigration
    {
        $prices = new DbalPriceRepository($this->connection);
        $resolver = new ArticlePriceResolver(new PriceCalculator($prices), $prices, new BasePriceCalculator(), $this->grossCalculator(), new DbalArticleTaxClasses($this->connection));
        $state = new ProjectionState($this->connection);

        return new ListPriceMarketMigration(
            $this->connection,
            new ListPriceProjector($prices, $resolver, new SqliteProjectionWriter($this->connection), new DbalArticleTree($this->connection), new DbalMarketRepository($this->connection)),
            new VisibilityProjector($this->connection, new VisibilityRows(new VisibilityCriteria()), new ShopLanguages($this->connection), $state),
            $state,
        );
    }
}

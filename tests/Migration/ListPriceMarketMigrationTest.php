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
 * Nach dem Deploy von C-1a tragen alle Listenpreiszeilen das Land '' — kein
 * Markt. Ohne Neubau wäre jede Liste leer.
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
        // Erster Durchlauf von contao:migrate: die Spalte gibt es noch nicht.
        $this->insertShop(['id' => 1, 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->connection->executeStatement('DROP TABLE tl_consho_product_list_price');
        $this->connection->executeStatement('CREATE TABLE tl_consho_product_list_price (id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0, pid INTEGER NOT NULL DEFAULT 0, currency TEXT NOT NULL DEFAULT \'\', net NUMERIC NOT NULL DEFAULT 0, gross NUMERIC NOT NULL DEFAULT 0)');

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testOhneDieWaehrungsspalteAmStartpunktLaeuftSieNichtAn(): void
    {
        // Dieselbe Lücke wie bei VisibilityProjectionMigration, nur eine
        // Schemaänderung früher: `country` kam mit C-1a, `consho_currency`
        // erst mit C-1c. Wurde C-1a angelegt, die Migration aber abgebrochen,
        // stehen im ersten Durchlauf nach dem Aufrüsten Zeilen mit country ''
        // da, ohne dass es consho_currency schon gäbe — und beide Neubauten in
        // run() fragen diese Spalte.
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
        // contao:migrate läuft, solange eine Migration fällig ist. Ohne Heimatland
        // gibt es keinen Markt — die alten Zeilen gehen, neue entstehen nicht.
        $this->insertShop(['id' => 1, 'currency' => 'EUR', 'fallbackLanguage' => 'de']);
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'gross' => '12.0000']);

        $migration = $this->migration();
        $migration->run();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_list_price'));
        self::assertFalse($migration->shouldRun());
    }

    public function testRunAbortsLoudlyWhenTheOldUniqueKeyStillExists(): void
    {
        // M-1: contao:migrate löscht den alten Schlüssel regulär, neben der
        // Spalte country bleibt er nur nach einer teilweise durchgelaufenen
        // Schemaänderung stehen. Träfe INSERT ... ON DUPLICATE KEY ihn statt (pid, currency,
        // country), würde die Projektion still geleert und die Länder
        // vermischt — deshalb bricht run() hier laut ab, statt neu zu bauen.
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
        // Ein benannter Index, nicht der inline-`UNIQUE (...)`-Tabellenzusatz:
        // Doctrines SQLite-Introspektion meldet einen solchen Autoindex nicht
        // über listTableIndexes() zurück (anders als MySQLs SHOW INDEX, das
        // ihn wie jeden benannten Index sieht) — mit dem Tabellenzusatz bliebe
        // die Erkennung im Test unbeobachtbar, das Verhalten in MySQL selbst
        // ist davon nicht betroffen.
        $this->connection->executeStatement('CREATE UNIQUE INDEX old_pid_currency ON tl_consho_product_list_price (pid, currency)');
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => '', 'net' => '10.0000', 'gross' => '12.0000']);

        try {
            $this->migration()->run();
            self::fail('run() hätte abbrechen müssen.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('(pid, currency)', $e->getMessage());
        }

        // Abgebrochen, bevor etwas angefasst wurde: die alte Zeile steht noch da.
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

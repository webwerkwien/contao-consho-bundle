<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dev;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\When;
use Webwerkwien\ContaoConshoBundle\Command\DevBenchmarkCommand;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Command\DevSeedCommand;
use Webwerkwien\ContaoConshoBundle\Cron\VisibilityBoundaryCron;
use Webwerkwien\ContaoConshoBundle\Dev\CatalogBenchmark;
use Webwerkwien\ContaoConshoBundle\Dev\CatalogSeedPlan;
use Webwerkwien\ContaoConshoBundle\Dev\CatalogSeedWriter;
use Webwerkwien\ContaoConshoBundle\Dev\ExplainAnalyzer;
use Webwerkwien\ContaoConshoBundle\Dev\QueryRecorder;
use Webwerkwien\ContaoConshoBundle\Dev\Timing;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

class CatalogBenchmarkTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_790_000_000;

    public function testEverySituationIsMeasured(): void
    {
        $recorder = new QueryRecorder();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($recorder)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->seed($connection);

        $reported = [];
        $results = (new CatalogBenchmark($connection, $recorder))->run(1, 'fr', 'EUR', 'de', 2, 5, static function (string $name) use (&$reported): void {
            $reported[] = $name;
        }, 120);

        self::assertSame(array_keys(CatalogBenchmark::situations(true)), array_keys($results));

        // Every situation reported at once — otherwise a large catalogue showed nothing for ten minutes.
        self::assertSame(array_keys($results), $reported);

        foreach ($results as $name => $result) {
            self::assertNull($result['error'], $name);
            self::assertNotNull($result['selection'], $name);
            self::assertNotNull($result['counts'], $name);
            self::assertGreaterThan(0, $result['selection']['median'], $name);
            self::assertGreaterThanOrEqual($result['selection']['median'], $result['selection']['max'], $name);
            self::assertGreaterThan(0, $result['counts']['median'], $name);
            self::assertNull($result['explain'], $name);
            // find + count, plus the category and one count query per selected group — like FilterPanelBuilder.
            self::assertSame(3 + \count(CatalogBenchmark::situations(true)[$name]['groups']), $result['queries'], $name);
            // EXPLAIN only on MariaDB/MySQL.
            self::assertNull($result['fullScans'], $name);
            self::assertNull($result['catalogueScans'], $name);
        }
    }

    public function testAFailingSituationIsReportedAndTheNextOneRuns(): void
    {
        // Like a query that fails on max_statement_time: reported, not aborted.
        $recorder = new QueryRecorder();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($recorder)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->seed($connection);
        // The table ProductQuery reads since B-10 — it no longer queries the list prices.
        $connection->executeStatement('DROP TABLE tl_consho_product_visibility');

        $reported = [];
        $results = (new CatalogBenchmark($connection, $recorder))->run(1, 'fr', 'EUR', 'de', 2, 5, static function (string $name) use (&$reported): void {
            $reported[] = $name;
        });

        self::assertCount(\count(CatalogBenchmark::situations(true)), $reported);

        foreach ($results as $name => $result) {
            self::assertNotNull($result['error'], $name);
            self::assertNull($result['selection'], $name);
            self::assertStringContainsString('tl_consho_product_visibility', (string) $result['error'], $name);

            // DBAL only records the query itself on execution. SQLite already fails
            // here on preparing; an abort by max_statement_time on MariaDB comes
            // after execution and stays recorded — that needs a real MariaDB to check.
        }
    }

    public function testMaintenanceTimesRefreshCronAndAFullRebuild(): void
    {
        // B-10: what the projection costs in operation.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->seed($connection);
        $connection->executeStatement('DELETE FROM tl_consho_product_visibility');

        $state = new ProjectionState($connection);
        $projector = new VisibilityProjector($connection, new VisibilityRows(new VisibilityCriteria()), new ShopLanguages($connection), $state);
        $productId = (int) $connection->fetchOne('SELECT MIN(id) FROM tl_consho_product WHERE pid = 0 AND id >= ?', [CatalogSeedPlan::PRODUCT_OFFSET]);

        $result = (new CatalogBenchmark($connection, new QueryRecorder()))->maintenance($projector, new VisibilityBoundaryCron($connection, $projector, $state), $productId, 3, self::NOW + 60);

        self::assertGreaterThan(0, $result['refresh']['median']);
        self::assertGreaterThan(0, $result['cron']['median']);
        self::assertGreaterThan(0, $result['rebuild']);

        // The rebuild really took place: complete, marker set.
        self::assertSame([], $projector->verify(self::NOW + 60));
        self::assertSame(self::NOW + 60, $state->get(ProjectionState::VISIBILITY_BOUNDARIES));
    }

    public function testTheSeededCatalogueIsWhatTheListingFinds(): void
    {
        // Without hits the command would measure empty results — fast and worthless.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->seed($connection);

        $bio = (int) $connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_property WHERE optionId = ?', [CatalogSeedPlan::frequentOptionId()]);

        self::assertGreaterThan(20, $bio);
    }

    public function testWithoutLanguageThereIsNoTranslationSituation(): void
    {
        self::assertArrayNotHasKey('translation language, Bio', CatalogBenchmark::situations(false));
    }

    public function testTheRecorderKeepsSqlAndParameters(): void
    {
        $recorder = new QueryRecorder();
        $recorder->debug('Executing statement: {sql}', ['sql' => 'SELECT ?', 'params' => [1 => 5], 'types' => [1 => 1]]);
        $recorder->debug('Beginning transaction');

        self::assertSame([['sql' => 'SELECT ?', 'params' => [5], 'types' => [1]]], $recorder->queries());

        $recorder->reset();
        self::assertSame([], $recorder->queries());
    }

    public function testMedianAndWorstRun(): void
    {
        self::assertSame(['median' => 3.0, 'max' => 9.0], Timing::summarize([9.0, 1.0, 3.0]));
        self::assertSame(['median' => 2.5, 'max' => 4.0], Timing::summarize([4.0, 1.0, 2.0, 3.0]));
        self::assertSame(['median' => 0.0, 'max' => 0.0], Timing::summarize([]));
    }

    public function testFindsFullTableScansInExplain(): void
    {
        $rows = [
            ['id' => 1, 'select_type' => 'PRIMARY', 'table' => 'p', 'type' => 'ALL', 'rows' => 104000],
            ['id' => 2, 'select_type' => 'DEPENDENT SUBQUERY', 'table' => 'pp', 'type' => 'ref', 'rows' => 3],
            ['ID' => 3, 'TABLE' => 'ps', 'TYPE' => 'all', 'ROWS' => 12],
        ];

        self::assertSame(['p (≈104000 rows)', 'ps (≈12 rows)'], ExplainAnalyzer::fullScans($rows));
    }

    public function testOnlyScansThatGrowWithTheCatalogueMissTheTarget(): void
    {
        // B-10, 5.7: EXPLAIN names aliases, not tables — the measure is the
        // size. Half the catalogue or more reads the catalogue; twelve groups do not.
        $rows = [
            ['table' => 'p', 'type' => 'ALL', 'rows' => 104000],
            ['table' => 'm', 'type' => 'ALL', 'rows' => 50000],
            ['table' => 'child', 'type' => 'ALL', 'rows' => 49999],
            ['table' => 'pp', 'type' => 'ref', 'rows' => 90000],
            ['table' => 'g', 'type' => 'ALL', 'rows' => 12],
        ];

        self::assertSame(['p (≈104000 rows)', 'm (≈50000 rows)'], ExplainAnalyzer::catalogueScans($rows, 100000));
        self::assertSame([], ExplainAnalyzer::catalogueScans($rows, 0), 'Ohne bekannte Größe keine Aussage.');
    }

    public function testBothCommandsExistOnlyInTheDevEnvironment(): void
    {
        foreach ([DevSeedCommand::class, DevBenchmarkCommand::class] as $class) {
            $attributes = (new \ReflectionClass($class))->getAttributes(When::class);

            self::assertCount(1, $attributes, $class);
            self::assertSame('dev', $attributes[0]->newInstance()->env, $class);
        }
    }

    private function seed(Connection $connection): void
    {
        $this->createCatalogSchema($connection);
        $this->createRootPageTable($connection);
        $this->insertShop(['id' => 1, 'title' => 'Shop', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);
        $this->insertRootPage(2, 'fr', 1);

        $plan = new CatalogSeedPlan(120, 20260913, 1, 'EUR', 'CHF', 'fr', self::NOW);
        $writer = new CatalogSeedWriter($connection);
        $writer->writeGroups($plan);
        $writer->write($plan);

        // The projection like consho:price:rebuild, simplified: the lowest single price per article and currency.
        // Gross from net with the 20 % the seed assumes — since C-1b the price row has no gross any more.
        $connection->executeStatement(
            'INSERT INTO tl_consho_product_list_price (pid, currency, country, net, gross)
             SELECT pid, currency, \'AT\', MIN(net), ROUND(MIN(net) * 1.2, 4) FROM tl_consho_price WHERE minQuantity <= 1 GROUP BY pid, currency',
        );

        // And from that the visibility projection that ProductQuery reads since B-10.
        (new VisibilityProjector(
            $connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($connection),
            new ProjectionState($connection),
        ))->rebuild(self::NOW);
    }
}

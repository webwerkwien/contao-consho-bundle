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

        // Jede Lage sofort gemeldet — auf c5 kam nach zehn Minuten nichts.
        self::assertSame(array_keys($results), $reported);

        foreach ($results as $name => $result) {
            self::assertNull($result['error'], $name);
            self::assertNotNull($result['selection'], $name);
            self::assertNotNull($result['counts'], $name);
            self::assertGreaterThan(0, $result['selection']['median'], $name);
            self::assertGreaterThanOrEqual($result['selection']['median'], $result['selection']['max'], $name);
            self::assertGreaterThan(0, $result['counts']['median'], $name);
            self::assertNull($result['explain'], $name);
            // find + count, dazu die Kategorie und je gewählter Gruppe eine Zahlenabfrage — wie FilterPanelBuilder.
            self::assertSame(3 + \count(CatalogBenchmark::situations(true)[$name]['groups']), $result['queries'], $name);
            // EXPLAIN nur auf MariaDB/MySQL.
            self::assertNull($result['fullScans'], $name);
            self::assertNull($result['catalogueScans'], $name);
        }
    }

    public function testAFailingSituationIsReportedAndTheNextOneRuns(): void
    {
        // Wie eine Abfrage, die an max_statement_time scheitert: gemeldet, nicht abgebrochen.
        $recorder = new QueryRecorder();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($recorder)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->seed($connection);
        // Die Tabelle, die ProductQuery seit B-10 liest — die Listenpreise fragt sie nicht mehr.
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

            // Die Abfrage selbst schneidet DBAL erst beim Ausführen mit. SQLite scheitert
            // hier schon beim Vorbereiten; ein Abbruch durch max_statement_time auf MariaDB
            // kommt nach dem Ausführen und bleibt mitgeschnitten — das prüft die Abnahme.
        }
    }

    public function testMaintenanceTimesRefreshCronAndAFullRebuild(): void
    {
        // B-10: was die Projektion im Betrieb kostet.
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

        // Der Neubau hat wirklich stattgefunden: vollständig, Merker gesetzt.
        self::assertSame([], $projector->verify(self::NOW + 60));
        self::assertSame(self::NOW + 60, $state->get(ProjectionState::VISIBILITY_BOUNDARIES));
    }

    public function testTheSeededCatalogueIsWhatTheListingFinds(): void
    {
        // Ohne Treffer mäße der Befehl leere Ergebnisse — schnell und wertlos.
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
        // B-10, 5.7: EXPLAIN nennt Aliasse, nicht Tabellen — gemessen wird an der
        // Größe. Halber Katalog oder mehr liest den Katalog; zwölf Gruppen nicht.
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
        $this->insertShop(['id' => 1, 'title' => 'Shop', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);
        $this->insertRootPage(2, 'fr', 1);

        $plan = new CatalogSeedPlan(120, 20260913, 1, 'EUR', 'CHF', 'fr', self::NOW);
        $writer = new CatalogSeedWriter($connection);
        $writer->writeGroups($plan);
        $writer->write($plan);

        // Die Projektion wie consho:price:rebuild, vereinfacht: der kleinste Einzelpreis je Artikel und Währung.
        // Brutto aus netto mit den 20 %, von denen der Seed ausgeht — die Preiszeile hat seit C-1b kein Brutto mehr.
        $connection->executeStatement(
            'INSERT INTO tl_consho_product_list_price (pid, currency, country, net, gross)
             SELECT pid, currency, \'AT\', MIN(net), ROUND(MIN(net) * 1.2, 4) FROM tl_consho_price WHERE minQuantity <= 1 GROUP BY pid, currency',
        );

        // Und daraus die Projektion der Sichtbarkeit, die ProductQuery seit B-10 liest.
        (new VisibilityProjector(
            $connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($connection),
            new ProjectionState($connection),
        ))->rebuild(self::NOW);
    }
}

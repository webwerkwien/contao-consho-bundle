<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Dev;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSort;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Cron\VisibilityBoundaryCron;

/**
 * Times ProductQuery on a seeded catalogue (plan B-9, again after B-10).
 * Development only.
 *
 * The queries, not the page (decision 3): template, images and network say
 * nothing about whether filtering carries.
 *
 * Per situation two numbers, because the listing's switch turns exactly one of
 * them off:
 *
 * | selection | find() for page 1 (24) + count() — what every listing asks |
 * | counts    | countsFor() as FilterPanelBuilder asks: the category, once for |
 * |           | the untouched groups, once per chosen group                    |
 *
 * One recorded warm-up call, then $runs measured ones; median and worst
 * (Timing). EXPLAIN only on MariaDB/MySQL — SQLite's plan is a different
 * language. Since B-10 the listing reads the visibility projection, and a scan
 * that grows with the catalogue is reported separately (catalogueScans): the
 * projection exists to remove exactly that.
 *
 * maintenance() times what the projection costs to keep: a refresh after a
 * save, the boundary cron's query, a full rebuild.
 *
 * ── Safe on a shared server ───────────────────────────────────────────────
 *
 * Measured on the test server on 2026-09-13: without the query cache a single
 * listing query ran for more than 30 seconds at 5,000 products, and the run
 * held the shared MariaDB for ten minutes before it was stopped — printing
 * nothing, because the results came at the end. So:
 *
 * - every situation is handed to $onResult as soon as it is done;
 * - $maxSeconds caps every statement (max_statement_time); a situation that
 *   hits it is reported as aborted, with the EXPLAIN of what it attempted, and
 *   the next one runs.
 *
 * Built by the command with a connection of its own (recordingConnection()),
 * not taken from the container.
 */
final class CatalogBenchmark
{
    public function __construct(
        private readonly Connection $connection,
        private readonly QueryRecorder $recorder,
    ) {
    }

    public static function recordingConnection(Connection $base, QueryRecorder $recorder): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($recorder)]);

        return DriverManager::getConnection($base->getParams(), $configuration);
    }

    /**
     * @return array<string, array{groups: array<int, list<int>>, sort: string, language: bool}>
     */
    public static function situations(bool $withLanguage): array
    {
        $six = [];

        foreach (CatalogSeedPlan::sixOptionIds() as $optionId) {
            $six[CatalogSeedPlan::groupIdOf($optionId)][] = $optionId;
        }

        $bio = [CatalogSeedPlan::groupIdOf(CatalogSeedPlan::frequentOptionId()) => [CatalogSeedPlan::frequentOptionId()]];

        $situations = [
            'no filter, page 1' => ['groups' => [], 'sort' => ProductSort::TITLE, 'language' => false],
            'frequent value (Bio, ~60 %)' => ['groups' => $bio, 'sort' => ProductSort::TITLE, 'language' => false],
            'six values over five groups' => ['groups' => $six, 'sort' => ProductSort::TITLE, 'language' => false],
            'variant axis' => ['groups' => [CatalogSeedPlan::groupIdOf(CatalogSeedPlan::variantAxisOptionId()) => [CatalogSeedPlan::variantAxisOptionId()]], 'sort' => ProductSort::TITLE, 'language' => false],
            'no filter, by price' => ['groups' => [], 'sort' => ProductSort::PRICE_ASC, 'language' => false],
        ];

        if ($withLanguage) {
            $situations['translation language, Bio'] = ['groups' => $bio, 'sort' => ProductSort::TITLE, 'language' => true];
        }

        return $situations;
    }

    /**
     * No moment: ProductQuery reads the projection, calculated at the time of
     * its last refresh (B-10). The currency is back since C-1c task 5b, not as
     * a calculation but as part of the projection's key.
     *
     * @param (\Closure(string, array<string, mixed>): void)|null $onResult     called after each situation
     * @param int                                                 $catalogueSize main products in the catalogue, for catalogueScans; 0 = unknown
     *
     * @return array<string, array{selection: array{median: float, max: float}|null, counts: array{median: float, max: float}|null, queries: int, fullScans: list<string>|null, catalogueScans: list<string>|null, explain: list<array{sql: string, rows: list<array<string, mixed>>}>|null, error: string|null}>
     */
    public function run(int $shopId, string $language, string $currency, string $fallback, int $runs, int $maxSeconds = 0, \Closure|null $onResult = null, int $catalogueSize = 0): array
    {
        $this->prepareSession($maxSeconds);

        $products = new ProductQuery($this->connection);
        $results = [];

        foreach (self::situations('' !== $language && $language !== $fallback) as $name => $situation) {
            $lang = $situation['language'] ? $language : $fallback;
            $groups = $situation['groups'];

            $selection = static fn () => [
                $products->find($shopId, $groups, $lang, $currency, $fallback, $situation['sort'], false, 0, 24),
                $products->count($shopId, $groups, $lang, $currency),
            ];

            $counts = static function () use ($products, $shopId, $groups, $lang, $currency): void {
                $products->countsFor($shopId, $groups, null, $lang, $currency);

                foreach (array_keys($groups) as $groupId) {
                    $products->countsFor($shopId, $groups, $groupId, $lang, $currency);
                }
            };

            $queries = [];
            $error = null;
            $selectionTimes = null;
            $countTimes = null;

            try {
                // Warm-up, and one recorded call each: exactly the queries of
                // one listing request, for EXPLAIN and for the count.
                $this->recorder->reset();
                $selection();
                $queries = $this->recorder->queries();

                $this->recorder->reset();
                $counts();
                $queries = [...$queries, ...$this->recorder->queries()];

                $selectionTimes = $this->time($selection, $runs);
                $countTimes = $this->time($counts, $runs);
            } catch (\Throwable $exception) {
                // What was attempted when it failed — the query to EXPLAIN.
                $queries = [...$queries, ...$this->recorder->queries()];
                $error = mb_substr($exception->getMessage(), 0, 200);
            }

            $explain = $this->explain($queries);

            $results[$name] = [
                'selection' => null === $selectionTimes ? null : Timing::summarize($selectionTimes),
                'counts' => null === $countTimes ? null : Timing::summarize($countTimes),
                'queries' => \count($queries),
                'fullScans' => null === $explain ? null : self::collect($explain, static fn (array $rows): array => ExplainAnalyzer::fullScans($rows)),
                'catalogueScans' => null === $explain ? null : self::collect($explain, static fn (array $rows): array => ExplainAnalyzer::catalogueScans($rows, $catalogueSize)),
                'explain' => $explain,
                'error' => $error,
            ];

            if (null !== $onResult) {
                $onResult($name, $results[$name]);
            }
        }

        return $results;
    }

    /**
     * What keeping the projection costs (B-10): the refresh a save of one
     * product triggers, the boundary cron's query on a minute without a
     * boundary, and one full rebuild.
     *
     * The rebuild runs once, not $runs times — at 100,000 products it is the
     * long one. It runs without the statement cap: it is many statements, none
     * of them a listing query. It sets the boundary marker to $now.
     *
     * @return array{refresh: array{median: float, max: float}, cron: array{median: float, max: float}, rebuild: float}
     */
    public function maintenance(VisibilityProjector $projector, VisibilityBoundaryCron $cron, int $productId, int $runs, int $now): array
    {
        $refresh = $this->time(static fn () => $projector->refresh([$productId], $now), $runs);
        $due = $this->time(static fn () => $cron->productIdsDue($now - 60, $now), $runs);

        $start = hrtime(true);
        $projector->rebuild($now);

        return [
            'refresh' => Timing::summarize($refresh),
            'cron' => Timing::summarize($due),
            'rebuild' => (hrtime(true) - $start) / 1e6,
        ];
    }

    /**
     * @param list<array{sql: string, rows: list<array<string, mixed>>}> $explain
     * @param \Closure(list<array<string, mixed>>): list<string>          $analyse
     *
     * @return list<string>
     */
    private static function collect(array $explain, \Closure $analyse): array
    {
        $found = [];

        foreach ($explain as $query) {
            $found = [...$found, ...$analyse($query['rows'])];
        }

        return array_values(array_unique($found));
    }

    /**
     * 🔴 Measured on the test server on 2026-09-13: MariaDB's query cache was ON
     * (256 MB). The first benchmark run reported 0.2–0.8 ms for queries whose
     * EXPLAIN showed full scans — every run after the warm-up was a cache hit
     * on identical SQL. Switched off for this session only; other websites on
     * a shared server keep their cache.
     *
     * The statement cap is MariaDB's max_statement_time (seconds) or MySQL's
     * max_execution_time (milliseconds, SELECT only).
     */
    private function prepareSession(int $maxSeconds): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $this->tryStatement('SET SESSION query_cache_type = OFF');

        if ($maxSeconds > 0 && !$this->tryStatement(\sprintf('SET SESSION max_statement_time = %d', $maxSeconds))) {
            $this->tryStatement(\sprintf('SET SESSION max_execution_time = %d', $maxSeconds * 1000));
        }

        $this->recorder->reset();
    }

    private function tryStatement(string $sql): bool
    {
        try {
            $this->connection->executeStatement($sql);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<float> milliseconds
     */
    private function time(\Closure $call, int $runs): array
    {
        $times = [];

        for ($i = 0; $i < $runs; ++$i) {
            $start = hrtime(true);
            $call();
            $times[] = (hrtime(true) - $start) / 1e6;
        }

        return $times;
    }

    /**
     * @param list<array{sql: string, params: list<mixed>, types: list<mixed>}> $queries
     *
     * @return list<array{sql: string, rows: list<array<string, mixed>>}>|null null where EXPLAIN is not MariaDB/MySQL's
     */
    private function explain(array $queries): array|null
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return null;
        }

        $explain = [];

        foreach ($queries as $query) {
            try {
                $rows = $this->connection->fetchAllAssociative('EXPLAIN '.$query['sql'], $query['params'], $query['types']);
            } catch (\Throwable) {
                continue;
            }

            $explain[] = ['sql' => $query['sql'], 'rows' => $rows];
        }

        $this->recorder->reset();

        return $explain;
    }
}

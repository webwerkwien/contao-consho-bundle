<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Cron\VisibilityBoundaryCron;
use Webwerkwien\ContaoConshoBundle\Dev\CatalogBenchmark;
use Webwerkwien\ContaoConshoBundle\Dev\CatalogSeedPlan;
use Webwerkwien\ContaoConshoBundle\Dev\QueryRecorder;
use Webwerkwien\ContaoConshoBundle\Shop\ShopCurrencies;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Measures the listing queries on a seeded catalogue (plans B-9 and B-10).
 *
 * Development only. Prints what a number needs to be a measurement (B-9,
 * decision 5): date, database version, catalogue size — the hardware has to be
 * added by hand, a jail cannot see it.
 *
 * Every situation is printed the moment it is done, and --max-seconds caps
 * every statement: a run without either once held a shared MariaDB for ten
 * minutes and printed nothing (see CatalogBenchmark).
 *
 * A scan that grows with the catalogue is marked TARGET MISSED: the visibility
 * projection (specification 5.7) exists to remove it, whatever the stopwatch
 * says. --maintenance adds what the projection costs to keep; its rebuild runs
 * without the statement cap, so not on a shared server.
 *
 * --without-covering-indexes drops the two indexes from specification 5.4 for
 * the run and puts them back afterwards, even when the run fails.
 */
#[When(env: 'dev')]
#[AsCommand(
    name: 'consho:dev:benchmark',
    description: 'Development only: times the listing queries on a seeded catalogue',
)]
final class DevBenchmarkCommand extends Command
{
    /**
     * name => [table, columns] as the DCA declares them.
     */
    private const COVERING_INDEXES = [
        'optionid_pid' => ['tl_consho_product_property', 'optionId, pid'],
        'shop_pid' => ['tl_consho_product_shop', 'shop, pid'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ShopLanguages $languages,
        private readonly VisibilityProjector $visibility,
        private readonly ShopCurrencies $currencies,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Shop configuration to measure', '1')
            ->addOption('runs', null, InputOption::VALUE_REQUIRED, 'Measured runs per situation', '10')
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Upper limit per statement; 0 for none', '20')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Print the full EXPLAIN of every query')
            ->addOption('without-covering-indexes', null, InputOption::VALUE_NONE, 'Drop optionid_pid and shop_pid for this run, restore them afterwards')
            ->addOption('maintenance', null, InputOption::VALUE_NONE, 'Also time refresh, boundary cron and a full rebuild of the visibility projection (no statement cap)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $shopId = (int) $input->getOption('shop');

        // Two questions, two queries — see DevSeedCommand for why they stopped
        // being one: an empty currency no longer means "no such shop".
        if (false === $this->connection->fetchOne('SELECT id FROM tl_consho_shop WHERE id = ?', [$shopId])) {
            $io->error(\sprintf('There is no shop configuration %d.', $shopId));

            return Command::FAILURE;
        }

        $currency = $this->currencies->of($shopId)[0] ?? '';

        if ('' === $currency) {
            $io->error(\sprintf('Shop configuration %d accepts no currency, so the listings have nothing to show.', $shopId));

            return Command::FAILURE;
        }

        $fallback = $this->languages->fallbackOf($shopId);
        $language = $this->languages->translatedOf($shopId)[0] ?? '';
        $mysql = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $withoutIndexes = (bool) $input->getOption('without-covering-indexes');
        $maxSeconds = max(0, (int) $input->getOption('max-seconds'));
        $explain = (bool) $input->getOption('explain');
        $runs = max(1, (int) $input->getOption('runs'));
        $mainProducts = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product WHERE pid = 0');

        $io->title('Consho catalogue benchmark');
        $io->definitionList(
            ['Date' => date('Y-m-d H:i:s T')],
            ['Database' => (string) $this->connection->fetchOne($mysql ? 'SELECT VERSION()' : 'SELECT sqlite_version()')],
            ['Buffer pool' => $mysql ? \sprintf('%d MB', (int) $this->connection->fetchOne('SELECT @@innodb_buffer_pool_size') / 1048576) : 'n/a'],
            ['Query cache' => $mysql ? $this->queryCache() : 'n/a'],
            ['Statement limit' => 0 === $maxSeconds ? 'none' : \sprintf('%d s', $maxSeconds)],
            ['Main products' => \sprintf('%d, of which seeded %d', $mainProducts, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product WHERE pid = 0 AND id >= ?', [CatalogSeedPlan::PRODUCT_OFFSET]))],
            ['Rows' => \sprintf('properties %d, variant options %d, prices %d', (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_property'), (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_option'), (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_price'))],
            ['Visibility projection' => \sprintf('%d rows', (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility'))],
            ['Shop' => \sprintf('%d, %s, fallback "%s", translation "%s"', $shopId, $currency, $fallback, $language)],
            ['Covering indexes' => $withoutIndexes ? 'DROPPED for this run' : 'present'],
            ['Hardware' => 'add by hand'],
        );

        if ($withoutIndexes) {
            foreach (self::COVERING_INDEXES as $name => [$table]) {
                $this->connection->executeStatement(\sprintf('DROP INDEX %s ON %s', $name, $table));
            }
        }

        $rows = [];

        try {
            $recorder = new QueryRecorder();
            $benchmark = new CatalogBenchmark(CatalogBenchmark::recordingConnection($this->connection, $recorder), $recorder);
            $benchmark->run(
                $shopId,
                $language,
                $currency,
                $fallback,
                $runs,
                $maxSeconds,
                static function (string $name, array $result) use ($io, $explain, &$rows): void {
                    $row = self::row($name, $result);
                    $rows[] = $row;
                    $io->writeln(\sprintf('<info>%s</info>  selection %s / %s  counts %s / %s  scans: %s%s', ...$row));

                    if ($explain && \is_array($result['explain'])) {
                        foreach ($result['explain'] as $query) {
                            $io->text(mb_substr(preg_replace('/\s+/', ' ', $query['sql']) ?? '', 0, 300));
                            $io->table(['id', 'select_type', 'table', 'type', 'key', 'rows', 'Extra'], array_map(
                                static fn (array $r): array => array_map(static fn ($k) => (string) ($r[$k] ?? ''), ['id', 'select_type', 'table', 'type', 'key', 'rows', 'Extra']),
                                $query['rows'],
                            ));
                        }
                    }
                },
                $mainProducts,
            );
        } finally {
            if ($withoutIndexes) {
                foreach (self::COVERING_INDEXES as $name => [$table, $columns]) {
                    $this->connection->executeStatement(\sprintf('CREATE INDEX %s ON %s (%s)', $name, $table, $columns));
                }
            }
        }

        $io->table(['Situation', 'Selection median', 'max', 'Counts median', 'max', 'Full scans', 'Note'], $rows);

        if ($input->getOption('maintenance')) {
            $productId = (int) $this->connection->fetchOne('SELECT MIN(id) FROM tl_consho_product WHERE pid = 0 AND id >= ?', [CatalogSeedPlan::PRODUCT_OFFSET]);
            $cron = new VisibilityBoundaryCron($this->connection, $this->visibility, new ProjectionState($this->connection));
            $maintenance = (new CatalogBenchmark($this->connection, new QueryRecorder()))->maintenance($this->visibility, $cron, $productId, $runs, time());

            $io->definitionList(
                ['Refresh one product' => \sprintf('%.1f ms / %.1f ms (product %d)', $maintenance['refresh']['median'], $maintenance['refresh']['max'], $productId)],
                ['Boundary cron query' => \sprintf('%.1f ms / %.1f ms', $maintenance['cron']['median'], $maintenance['cron']['max'])],
                ['Full rebuild' => \sprintf('%.1f s', $maintenance['rebuild'] / 1000)],
            );
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private static function row(string $name, array $result): array
    {
        $ms = static fn (mixed $timing, string $key): string => \is_array($timing) ? \sprintf('%.1f ms', $timing[$key]) : '—';
        $scans = $result['fullScans'];
        $catalogue = $result['catalogueScans'] ?? null;

        $note = null === $result['error'] ? '' : '  ABORTED: '.$result['error'];

        if (\is_array($catalogue) && [] !== $catalogue) {
            $note .= '  TARGET MISSED (5.7): '.implode(', ', $catalogue);
        }

        return [
            $name,
            $ms($result['selection'], 'median'),
            $ms($result['selection'], 'max'),
            $ms($result['counts'], 'median'),
            $ms($result['counts'], 'max'),
            null === $scans ? 'n/a' : ([] === $scans ? 'none' : implode(', ', (array) $scans)),
            $note,
        ];
    }

    private function queryCache(): string
    {
        try {
            $global = (string) $this->connection->fetchOne('SELECT @@global.query_cache_type');
        } catch (\Throwable) {
            return 'none (MySQL 8)';
        }

        return \sprintf('%s globally, OFF for the measuring session', $global);
    }
}

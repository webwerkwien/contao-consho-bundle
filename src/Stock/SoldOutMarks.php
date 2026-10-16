<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Keeps tl_consho_sold_out in step with Availability (block Verfügbarkeit, V1).
 *
 * Asks Availability and writes what it answers — never decides itself what
 * "derzeit nicht verfügbar" means. A row is the state StockState::Unavailable;
 * "auf Bestellung" is not sold out (V2), and an article without kept stock
 * never is (V7).
 *
 * ── Who calls it ─────────────────────────────────────────────────────────
 *
 * Where a state change is already noticed: StockBooking (every booking, the
 * receipt of a purchase list included), StockAllocation (a key pool at the
 * purchase click), StockReturn (codes an order no longer waits for), and
 * CatalogRefreshListener for every saved product — stock switches, "sell
 * without stock", codes pasted into a pool, a new variant.
 *
 * The hourly rebuild (SoldOutCron) is the net under all of them, in the spirit
 * of StockNoticeCron: stock comes back by many roads, and a trigger in each
 * would be the one forgotten in the next — a product type switched to "Datei",
 * a code deleted from the pool.
 */
class SoldOutMarks
{
    private const BLOCK = 500;

    /**
     * Articles collected while deferred() runs; null outside of it.
     *
     * @var list<int>|null
     */
    private array|null $deferred = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Availability $availability,
    ) {
    }

    /**
     * Runs $work and marks what it asked for afterwards, once and in
     * ascending article order (review Verfügbarkeit, 2).
     *
     * A purchase books line by line, in the order of the basket; marking each
     * line at once took the locks on tl_consho_sold_out in that order, while
     * the hourly rebuild takes them ascending. Two articles selling out in the
     * same moment as the cron could then deadlock, and InnoDB may pick the
     * purchase as the victim. Collected and sorted, both go the same way.
     *
     * Nothing is marked when $work throws — the transaction around it rolls
     * back, and so does what would have been marked.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function deferred(callable $work): mixed
    {
        // Already deferring: the outer call marks.
        if (null !== $this->deferred) {
            return $work();
        }

        $this->deferred = [];

        try {
            $result = $work();
            $articles = $this->collected();
        } finally {
            $this->deferred = null;
        }

        if ([] !== $articles) {
            $this->refresh($articles);
        }

        return $result;
    }

    /**
     * What refresh() collected while $work ran. A method, not the property
     * read inline: the callback fills it behind the analyser's back.
     *
     * @return list<int>
     */
    private function collected(): array
    {
        return $this->deferred ?? [];
    }

    /**
     * @param list<int> $articleIds articles, main products or ids that no longer exist
     *
     * @return bool whether a row was written or removed; false while deferred
     */
    public function refresh(array $articleIds, int|null $now = null): bool
    {
        if (null !== $this->deferred) {
            $this->deferred = [...$this->deferred, ...$articleIds];

            return false;
        }

        $changed = false;
        $articleIds = array_values(array_unique(array_filter(array_map(intval(...), $articleIds), static fn (int $id): bool => $id > 0)));
        // Ascending, like rebuild() — the same lock order everywhere. Within a
        // block soldOutAmong() sorts already; this orders the blocks.
        sort($articleIds);

        foreach (array_chunk($articleIds, self::BLOCK) as $ids) {
            $wanted = $this->soldOutAmong($ids);
            $stored = $this->storedAmong($ids);

            $gone = array_values(array_diff($stored, $wanted));
            $new = array_values(array_diff($wanted, $stored));

            if ([] !== $gone) {
                $this->connection->executeStatement(
                    'DELETE FROM tl_consho_sold_out WHERE article IN (?)',
                    [$gone],
                    [ArrayParameterType::INTEGER],
                );
            }

            foreach ($new as $article) {
                try {
                    $this->connection->insert('tl_consho_sold_out', ['tstamp' => $now ?? time(), 'article' => $article]);
                } catch (UniqueConstraintViolationException) {
                    // Marked by a concurrent request in between — the row we
                    // wanted is there. Not swallowed silently by accident: a
                    // purchase or a cancellation calls this inside its own
                    // transaction, and a duplicate mark must not take it down.
                    // MySQL rolls back the statement, not the transaction.
                }
            }

            $changed = $changed || [] !== $gone || [] !== $new;
        }

        return $changed;
    }

    /**
     * Every article anew, and rows of articles that are gone.
     *
     * @return bool whether a row was written or removed
     */
    public function rebuild(int|null $now = null): bool
    {
        $changed = $this->refresh($this->allIds(), $now);

        return $this->prune() || $changed;
    }

    /**
     * Rows of articles that are gone. Deleting a main product takes its
     * variants along as child records, and nobody reports them one by one
     * (review Verfügbarkeit, 3). Cheap: the table holds only what is sold out.
     *
     * @return bool whether a row was removed
     */
    public function prune(): bool
    {
        return $this->connection->executeStatement('DELETE FROM tl_consho_sold_out WHERE article NOT IN (SELECT id FROM tl_consho_product)') > 0;
    }

    /**
     * Where the table differs from what Availability says. Never writes — a
     * verify that repairs hides the cause one is looking for
     * (VisibilityProjector::verify()).
     *
     * @return array{missing: list<int>, surplus: list<int>} articles sold out without a row, rows of articles that are not
     */
    public function verify(): array
    {
        $missing = [];
        $surplus = [];

        foreach (array_chunk($this->allIds(), self::BLOCK) as $ids) {
            $wanted = $this->soldOutAmong($ids);
            $stored = $this->storedAmong($ids);

            $missing = [...$missing, ...array_diff($wanted, $stored)];
            $surplus = [...$surplus, ...array_diff($stored, $wanted)];
        }

        $orphans = array_map(intval(...), $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out WHERE article NOT IN (SELECT id FROM tl_consho_product)'));

        $surplus = array_values(array_unique([...$surplus, ...$orphans]));
        sort($missing);
        sort($surplus);

        return ['missing' => $missing, 'surplus' => $surplus];
    }

    /**
     * The articles among $ids that are sold out. A main product with
     * variants is never one of them (V3), and an id that does not exist
     * is not either.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function soldOutAmong(array $ids): array
    {
        $sellable = array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT a.id FROM tl_consho_product a
              WHERE a.id IN (?) AND NOT EXISTS (SELECT 1 FROM tl_consho_product c WHERE c.pid = a.id)',
            [$ids],
            [ArrayParameterType::INTEGER],
        ));

        if ([] === $sellable) {
            return [];
        }

        $soldOut = [];

        foreach ($this->availability->of($sellable) as $article => $availability) {
            if (StockState::Unavailable === $availability->state) {
                $soldOut[] = $article;
            }
        }

        sort($soldOut);

        return $soldOut;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function storedAmong(array $ids): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT article FROM tl_consho_sold_out WHERE article IN (?) ORDER BY article',
            [$ids],
            [ArrayParameterType::INTEGER],
        ));
    }

    /**
     * @return list<int>
     */
    private function allIds(): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_product ORDER BY id'));
    }
}

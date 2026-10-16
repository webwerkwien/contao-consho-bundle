<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;

/**
 * Gives the existing visibility rows the currency they were built in (C-1c,
 * task 5b).
 *
 * contao:migrate adds `currency` as '' to every existing row, and '' is no
 * currency: every listing asks for the start point's currency and would find
 * nothing at all. Before C-1c the currency followed from the shop, so for an
 * installation that is upgrading it is known — it is the one currency its
 * start points now carry, put there by RootCurrencyMigration.
 *
 * ── It fills in where the answer is certain, and rebuilds where it is not ──
 *
 * One currency for the shop's start points in every language it has to fill:
 * an UPDATE per language, which is cheap and exact. Anything else — two
 * currencies for one language (the DACH case this task exists for), no start
 * point at all, or rows that a newer projector run already wrote — and the
 * whole projection is recalculated instead of guessed. A wrong currency on a
 * price is worse than a slow migration: it is a figure that looks right.
 *
 * It is one or the other, never both: rebuild() writes every row of every shop
 * anew, so an UPDATE before it would be undone by it.
 *
 * ── Why the criterion looks the way it does ───────────────────────────────
 *
 * "A row with currency ''" alone would keep this migration pending for ever on
 * an installation whose start points have no currency either: those rows
 * legitimately carry '', because their target does — VisibilityProjector skips
 * a start point without a language, not one without a currency. And
 * contao:migrate runs migrations for as long as one of them is pending (B-10,
 * VisibilityProjectionMigration).
 *
 * So the criterion leaves out every (shop, language) pair that has a start
 * point without a currency. What stays is the state this migration actually
 * removes. For a pair with one start point in euro and a second with no
 * currency at all, that means it does nothing: its rows match the ''-target,
 * the euro rows are simply missing, and consho:visibility:verify says so until
 * the next rebuild. The start point itself is named on the back end start page
 * by RootWithoutCurrency (DbalShopScopeIssueSource, C-1c task 7).
 */
final class VisibilityCurrencyMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VisibilityProjector $projector,
    ) {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        /*
         * contao:migrate runs in two passes with the schema update between
         * them; in the first pass the new column does not exist yet. Without
         * this guard that pass fails on an unknown column instead of simply
         * having nothing to do. Same reasoning as RootCurrencyMigration.
         */
        if (!$schema->tablesExist(['tl_consho_product_visibility', 'tl_page'])) {
            return false;
        }

        if (!\array_key_exists('currency', $schema->listTableColumns('tl_consho_product_visibility'))) {
            return false;
        }

        // rebuild() reads the currency off the start points, so the column has
        // to be there before run() may touch anything.
        if (!\array_key_exists('consho_currency', $schema->listTableColumns('tl_page'))) {
            return false;
        }

        return [] !== $this->work();
    }

    public function run(): MigrationResult
    {
        $work = $this->work();

        /*
         * Decide first, then act — the two ways do not share the work between
         * them. rebuild() writes EVERY row of every shop anew, so an UPDATE
         * run before it is work thrown away, and on a large catalogue that is
         * not free. It would also make the report a lie: "so many filled in,
         * the rest rebuilt" would describe a split that never happened,
         * because "the rest" is all of it.
         */
        foreach ($work as $item) {
            if (1 !== \count($item['currencies']) || $this->hasRowsIn($item['shop'], $item['language'], $item['currencies'][0])) {
                $this->projector->rebuild();

                return $this->createResult(
                    true,
                    \sprintf(
                        'Rebuilt the visibility projection per currency: %d row(s).',
                        (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility'),
                    ),
                );
            }
        }

        $updated = 0;

        foreach ($work as $item) {
            $updated += (int) $this->connection->executeStatement(
                "UPDATE tl_consho_product_visibility SET currency = ? WHERE shop = ? AND language = ? AND currency = ''",
                [$item['currencies'][0], $item['shop'], $item['language']],
            );
        }

        return $this->createResult(true, \sprintf('Gave the visibility projection its currency: %d row(s).', $updated));
    }

    /**
     * What there is to do: every shop and language whose rows still have no
     * currency, with the currencies its start points carry.
     *
     * A pair one of whose start points has NO currency is left out: its rows
     * belong to that start point's target, which carries '' as well, and
     * treating them as work would keep contao:migrate pending for ever. Both
     * shouldRun() and run() ask this one method, so the criterion and the work
     * cannot drift apart.
     *
     * @return list<array{shop: int, language: string, currencies: list<string>}>
     */
    private function work(): array
    {
        $work = [];

        foreach ($this->connection->fetchAllAssociative(
            "SELECT DISTINCT shop, language FROM tl_consho_product_visibility
              WHERE currency = ''
              ORDER BY shop, language",
        ) as $row) {
            $shopId = (int) $row['shop'];
            $language = (string) $row['language'];
            $currencies = $this->currenciesOf($shopId, $language);

            if (\in_array('', $currencies, true)) {
                continue;
            }

            $work[] = ['shop' => $shopId, 'language' => $language, 'currencies' => $currencies];
        }

        return $work;
    }

    /**
     * The currencies this shop is reached in in that language, the empty one
     * included — work() needs to see it.
     *
     * Read here and not through ShopLanguages: that one answers per shop and
     * caches, while this asks per language and runs once.
     *
     * ⚠️ Which rows count as start points must stay what
     * ShopLanguages::audiencesOf() says — `type = 'root' AND consho_shop = ?`,
     * and nothing dropped. The termination of this migration hangs on it: the
     * projector builds its targets from that method, and a pair this one
     * thinks is unambiguous while the projector writes '' for it would be
     * filled, emptied and found again on the next pass.
     *
     * No ORDER BY: the caller reads count() and, on exactly one, [0].
     *
     * @return list<string>
     */
    private function currenciesOf(int $shopId, string $language): array
    {
        return array_map(
            strval(...),
            $this->connection->fetchFirstColumn(
                "SELECT DISTINCT consho_currency FROM tl_page
                  WHERE type = 'root' AND consho_shop = ? AND language = ?",
                [$shopId, $language],
            ),
        );
    }

    /**
     * Whether the target already holds rows — then a newer projector run has
     * written them, the rows without a currency are leftovers, and an UPDATE
     * would run into the unique key instead of saying so.
     */
    private function hasRowsIn(int $shopId, string $language, string $currency): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_product_visibility WHERE shop = ? AND language = ? AND currency = ?',
            [$shopId, $language, $currency],
        );
    }
}

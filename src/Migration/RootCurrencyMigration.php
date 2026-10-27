<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Shop\CurrencySelection;
use Webwerkwien\ContaoConshoBundle\Shop\ShopCurrencies;

/**
 * Carries the currency from the shop to its root pages (C-1c).
 *
 * Until now a shop had exactly one currency and every page below it sold in
 * that one. Since C-1c the shop says which currencies it CAN accept and each
 * root page says which one applies there — the currency is a property of the
 * audience, like the language, not a business rule of the seller. See
 * Block A, section 4.0.
 *
 * This migration makes an existing installation behave exactly as before:
 * every shop keeps its one currency as its only allowed one, and every root
 * page inherits it.
 *
 * ── What it deliberately does NOT do ─────────────────────────────────────
 *
 * It never guesses. A root page whose shop was deleted, and a shop whose
 * currency column is empty, are left alone. A wrong currency that looks
 * plausible is worse than an empty one that says so.
 *
 * Both gaps are named on the back end start page since task 7:
 * RootWithoutCurrency (DbalShopScopeIssueSource) for the root page left empty,
 * CurrencyMissing (DbalCatalogCompletenessIssueSource) for the shop that ends
 * up selling in nothing at all.
 *
 * ⚠️ With one exception, and it is the first case above: a root page whose
 * shop was DELETED is reported by neither. Without a shop there is nothing
 * that could accept or refuse a currency, and the message would name a shop
 * that does not exist — such a page falls under "start page without a shop
 * configuration", which switches the shop functions off (block A, section 7).
 * Written down so the silence stays a decision rather than an oversight.
 *
 * It leaves `tl_consho_shop.currency` in place; the DCA no longer defines
 * it, so `contao:migrate --with-deletes` removes it after this migration has
 * run. A database without the column skips the migration (shouldRun()).
 */
final class RootCurrencyMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ShopCurrencies $currencies,
    ) {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        /*
         * contao:migrate runs in two passes with the schema update between
         * them; in the first pass the new columns do not exist yet. Without
         * this guard that pass fails on an unknown column instead of simply
         * having nothing to do. Same reasoning as ListPriceMarketMigration.
         */
        if (!$schema->tablesExist(['tl_consho_shop', 'tl_page'])) {
            return false;
        }

        $shop = $schema->listTableColumns('tl_consho_shop');

        if (!\array_key_exists('currency', $shop) || !\array_key_exists('currencies', $shop)) {
            return false;
        }

        if (!\array_key_exists('consho_currency', $schema->listTableColumns('tl_page'))) {
            return false;
        }

        // The criterion is a state of the data, not a "has run" marker: work
        // left is a shop that still has to hand its currency over, or a root
        // page that has not received one.
        return [] !== $this->shopsToFill() || [] !== $this->rootsToFill();
    }

    public function run(): MigrationResult
    {
        $shops = 0;

        foreach ($this->shopsToFill() as $row) {
            $this->connection->update(
                'tl_consho_shop',
                ['currencies' => serialize([(string) $row['currency']])],
                ['id' => (int) $row['id']],
            );

            ++$shops;
        }

        // The service caches per request and has just been made wrong by the
        // loop above.
        $this->currencies->reset();

        $roots = 0;

        foreach ($this->rootsToFill() as $row) {
            /*
             * resolve() rather than the shop's currency column: after the loop
             * above every shop has exactly one allowed currency, so resolve()
             * returns precisely that one — and it is the single place where
             * "which currency applies here" is decided. RootCurrencyListener
             * asks the same method, so mask and migration cannot drift apart.
             */
            $currency = $this->currencies->resolve((int) $row['consho_shop'], '');

            if (null === $currency) {
                continue;
            }

            $this->connection->update('tl_page', ['consho_currency' => $currency], ['id' => (int) $row['id']]);

            ++$roots;
        }

        return $this->createResult(
            true,
            \sprintf('Moved the currency to the root pages: %d shop(s), %d root page(s).', $shops, $roots),
        );
    }

    /**
     * Shops that still carry their currency only in the old column.
     *
     * @return list<array{id: int|string, currency: string}>
     */
    private function shopsToFill(): array
    {
        /** @var list<array{id: int|string, currency: string}> */
        return $this->connection->fetchAllAssociative(
            "SELECT id, currency FROM tl_consho_shop
             WHERE currency <> '' AND (currencies IS NULL OR currencies = '')
             ORDER BY id",
        );
    }

    /**
     * Root pages with a shop but no currency yet — and only those this
     * migration can actually fill.
     *
     * `type = 'root'` is part of the condition, not decoration: the column
     * exists on every page, and a leftover value on a page that used to be a
     * root must not be turned into a selling signal.
     *
     * ── 🔴 Why the shop is joined in, and not checked in run() ──────────────
     *
     * Whatever this list contains, run() has to resolve. What it hands back
     * unchanged makes shouldRun() true again, and contao:migrate re-runs a
     * migration until it says it is done — after 19 rounds it gives up with
     * "The migrations were stopped after 19 iterations as a precaution" and
     * exits 1, while this one reported success with "0 root page(s)" every
     * single time. The failure points at Contao, not at here.
     *
     * Three states used to do exactly that, and all three are ones the class
     * doc above calls deliberate: a root page whose shop was deleted, a shop
     * with an empty old column, and a shop that already carries more than one
     * currency — there is nothing to inherit then, the operator has to choose.
     * They are still left alone; they are just no longer counted as work.
     *
     * ⚠️ The deleted shop is caught twice: the INNER JOIN drops the row, and
     * the filter below would drop it anyway (no list, no old column). Turning
     * the join into a LEFT JOIN therefore reddens no test — measured. The join
     * stays because it says what the query means and spares the filter rows it
     * cannot use; do not go looking for the test that covers it.
     *
     * The decision is read through CurrencySelection, the same parser the mask
     * and ShopCurrencies use, rather than picked apart in SQL: the column is a
     * serialised blob, and a LIKE over it would be a second spelling of the
     * rule that could drift from the first.
     *
     * ⚠️ It cannot ask ShopCurrencies::resolve() — the shops have no
     * `currencies` yet when shouldRun() first looks, so resolve() would answer
     * "none" for every one of them and the migration would never run at all.
     * Hence both columns: the new list where it exists, the old one otherwise.
     *
     * VisibilityCurrencyMigration and VisibilityProjectionMigration both carry
     * this reasoning in their own heads. This one did not, and that is the
     * whole story of the bug.
     *
     * @return list<array{id: int|string, consho_shop: int|string}>
     */
    private function rootsToFill(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.id, p.consho_shop, s.currency, s.currencies FROM tl_page p
               JOIN tl_consho_shop s ON s.id = p.consho_shop
              WHERE p.type = 'root' AND p.consho_shop > 0 AND p.consho_currency = ''
              ORDER BY p.id",
        );

        $fillable = array_filter(
            $rows,
            static function (array $row): bool {
                $chosen = CurrencySelection::fromBlob($row['currencies'])->valid;

                return [] === $chosen
                    ? '' !== (string) $row['currency']
                    : 1 === \count($chosen);
            },
        );

        /** @var list<array{id: int|string, consho_shop: int|string}> */
        return array_values(array_map(
            static fn (array $row): array => ['id' => $row['id'], 'consho_shop' => $row['consho_shop']],
            $fillable,
        ));
    }
}

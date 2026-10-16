<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;

/**
 * Rebuilds the list price projection once after C-1a gives it a country.
 *
 * contao:migrate adds `country` as '' to every existing row, and '' is no
 * market: VisibilityRows would find no price for any shop, and every listing
 * would be empty until somebody ran consho:price:rebuild.
 *
 * The criterion is a row with country '', not "table empty": a rebuild deletes
 * every such row — a market never has an empty country — so this runs exactly
 * once, also for a shop that has no home country yet (then no rows at all,
 * and HomeCountryMissing says why).
 *
 * ListPriceProjector::rebuild() stores the fingerprint of the shops' markets
 * itself, so the first save of a page afterwards does not rebuild again. The
 * marker of the tax rate boundaries starts at this moment: everything before
 * is in the rebuild (ListPriceBoundaryCron).
 */
final class ListPriceMarketMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ListPriceProjector $prices,
        private readonly VisibilityProjector $visibility,
        private readonly ProjectionState $state,
    ) {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        // The schema update runs between two migration passes; in the first
        // pass the column may not exist yet.
        if (!$schema->tablesExist(['tl_consho_product_list_price', 'tl_consho_shop', 'tl_consho_product_visibility', 'tl_consho_projection_state'])) {
            return false;
        }

        // listTableColumns() lower-cases the names; `country` is lower case anyway.
        if (!\array_key_exists('country', $schema->listTableColumns('tl_consho_product_list_price'))) {
            return false;
        }

        /*
         * `country` came with C-1a, tl_page.consho_currency only with C-1c —
         * so the column above does not vouch for this one. An installation
         * whose C-1a schema change went through while its migration was
         * interrupted still has rows with country '' when C-1c arrives, and
         * both rebuilds in run() read the currency off the start pages.
         */
        if (!\array_key_exists('consho_currency', $schema->listTableColumns('tl_page'))) {
            return false;
        }

        return false !== $this->connection->fetchOne("SELECT 1 FROM tl_consho_product_list_price WHERE country = ''");
    }

    public function run(): MigrationResult
    {
        // Loud, not silent: contao:migrate drops the old UNIQUE (pid, currency)
        // like any other schema change — CommandCompiler only protects tables
        // and columns. It stays behind next to the new `country` column only
        // when the schema update ran partly (a failed DROP INDEX, a change by
        // hand). Then INSERT ... ON DUPLICATE KEY (DbalProjectionWriter)
        // targets it instead of the new (pid, currency, country) key — the
        // projection is quietly emptied and different countries' rows collide
        // into one. A rebuild here would look fine and hide exactly that.
        $this->assertOldUniqueKeyIsGone();

        $now = time();

        $this->prices->rebuild($now);
        $this->visibility->rebuild($now);
        $this->state->set(ProjectionState::LIST_PRICE_TAX_BOUNDARIES, $now);

        $rows = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_list_price');

        return $this->createResult(true, \sprintf('Rebuilt the list price projection per home country: %d row(s).', $rows));
    }

    /**
     * @throws \RuntimeException when the old unique key is still there
     */
    private function assertOldUniqueKeyIsGone(): void
    {
        $indexes = $this->connection->createSchemaManager()->listTableIndexes('tl_consho_product_list_price');

        foreach ($indexes as $index) {
            if ($index->isPrimary() || !$index->isUnique()) {
                continue;
            }

            $columns = array_map(static fn (string $column): string => strtolower($column), $index->getColumns());
            sort($columns);

            if (['currency', 'pid'] === $columns) {
                throw new \RuntimeException('The old unique key (pid, currency) on tl_consho_product_list_price still exists; drop it (contao:migrate lists it as a schema change), then this migration runs by itself.');
            }
        }
    }
}

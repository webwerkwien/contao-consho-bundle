<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;

/**
 * Builds the visibility projection once after it arrives (B-10).
 *
 * contao:migrate creates tl_consho_product_visibility empty — and from that
 * moment every listing reads it. Without this, every listing of a live shop
 * would be empty until somebody ran consho:visibility:rebuild.
 *
 * 🔴 The criterion is the missing marker, NOT the empty table. contao:migrate
 * runs migrations for as long as one of them is pending. A catalogue whose
 * published products all lack a price stays empty after a rebuild, and "table
 * empty" would keep this migration pending forever. rebuild() sets the marker
 * whatever it wrote.
 */
final class VisibilityProjectionMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VisibilityProjector $projector,
    ) {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        // The schema update runs between two migration passes; in the first
        // pass the tables may not exist yet.
        if (!$schema->tablesExist(['tl_consho_product', 'tl_consho_product_visibility', 'tl_consho_projection_state'])) {
            return false;
        }

        /*
         * Nor may tl_page.consho_currency, which rebuild() has needed since
         * C-1c: it asks ShopLanguages for the currency of every start page.
         * The window is narrow — the visibility table already there and
         * the marker unset means a previous contao:migrate was interrupted —
         * but without this guard that pass fails on an unknown column instead
         * of simply having nothing to do. Same guard, same reason, as
         * RootCurrencyMigration and ListPriceMarketMigration.
         */
        if (!\array_key_exists('consho_currency', $schema->listTableColumns('tl_page'))) {
            return false;
        }

        if (false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_projection_state WHERE name = ?', [ProjectionState::VISIBILITY_BOUNDARIES])) {
            return false;
        }

        // A new installation without products has nothing to build; the
        // boundary cron sets the marker on its first run.
        return false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_product WHERE pid = 0 AND published = 1');
    }

    public function run(): MigrationResult
    {
        $this->projector->rebuild();

        $rows = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility');

        return $this->createResult(true, \sprintf('Built the visibility projection: %d row(s).', $rows));
    }
}

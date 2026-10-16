<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Stock\SoldOutMarks;

/**
 * Builds tl_consho_sold_out once after it arrives (block Verfügbarkeit, V1).
 *
 * Without it "Nur verfügbare" would show every article until the first run of
 * SoldOutCron. The criterion is the marker, not the empty table — the lesson
 * of VisibilityProjectionMigration: a shop with nothing sold out stays empty,
 * and "table empty" would keep this migration pending for ever.
 */
final class SoldOutMarksMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SoldOutMarks $marks,
        private readonly ProjectionState $state,
    ) {
    }

    public function shouldRun(): bool
    {
        // Every table Availability reads — the schema update runs between two
        // migration passes, and in the first one they may not exist yet.
        if (!$this->connection->createSchemaManager()->tablesExist([
            'tl_consho_sold_out', 'tl_consho_projection_state', 'tl_consho_product', 'tl_consho_product_type', 'tl_consho_stock', 'tl_consho_key',
            'tl_consho_order', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item',
        ])) {
            return false;
        }

        return null === $this->state->get(ProjectionState::SOLD_OUT_BUILT);
    }

    public function run(): MigrationResult
    {
        $this->marks->rebuild();
        $this->state->set(ProjectionState::SOLD_OUT_BUILT, time());

        return $this->createResult(true, \sprintf(
            'Marked %d article(s) as sold out.',
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_sold_out'),
        ));
    }
}

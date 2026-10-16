<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;

/**
 * Reports the articles of a tax class whose rates changed, so their display
 * gross is recalculated at the end of the request (C25).
 *
 * The list price projection stores gross, and gross follows from the rate of
 * the article's class in the shop's home country. Nothing about a product
 * changes when a rate is entered, corrected or removed — so without this the
 * listing would go on showing the old gross, or none.
 *
 * Only reports, like PriceRefreshTriggerListener, for the same reasons: ondelete
 * runs while the row is still there, and the queue collects each product once.
 *
 * ⚠️ Saving the standard rate reports every product of that class, and all of
 * them are recalculated inside the back end's response. The same deliberate
 * trade-off as a changed shop (VisibilityProjector::syncShops()): rates change
 * a few times a decade, and a stale gross is the worse failure.
 *
 * A deleted tax class takes its rates along as child records without their own
 * ondelete — hence the callbacks on tl_consho_tax_class.
 *
 * Moving a rate to another class is not a case either: tl_consho_tax_rate is
 * notSortable, so neither the operation nor the select mode offers it.
 */
class TaxRateRefreshTriggerListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogRefreshQueue $queue,
    ) {
    }

    #[AsCallback(table: 'tl_consho_tax_rate', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $this->reportClassOfRate((int) $dc->id);
    }

    #[AsCallback(table: 'tl_consho_tax_rate', target: 'config.oncopy')]
    public function onCopy(int|string $insertId, DataContainer $dc): void
    {
        $this->reportClassOfRate((int) $insertId);
    }

    #[AsCallback(table: 'tl_consho_tax_rate', target: 'config.ondelete')]
    public function onDelete(DataContainer $dc, int|string $undoId): void
    {
        $this->reportClassOfRate((int) $dc->id);
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_tax_rate', target: 'config.onundo')]
    public function onUndo(string $table, array $row, DataContainer $dc): void
    {
        $this->reportClass((int) ($row['pid'] ?? 0));
    }

    /**
     * The id from the parameter, not from $data — see
     * PriceRefreshTriggerListener::onRestoreVersion().
     *
     * @param array<string, mixed> $data
     */
    #[AsCallback(table: 'tl_consho_tax_rate', target: 'config.onrestore_version')]
    public function onRestoreVersion(string $table, int $id, int $version, array $data): void
    {
        $this->reportClassOfRate($id);
    }

    #[AsCallback(table: 'tl_consho_tax_class', target: 'config.ondelete')]
    public function onDeleteClass(DataContainer $dc, int|string $undoId): void
    {
        $this->reportClass((int) $dc->id);
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_tax_class', target: 'config.onundo')]
    public function onUndoClass(string $table, array $row, DataContainer $dc): void
    {
        $this->reportClass((int) ($row['id'] ?? 0));
    }

    private function reportClassOfRate(int $rateId): void
    {
        $classId = $this->connection->fetchOne('SELECT pid FROM tl_consho_tax_rate WHERE id = ?', [$rateId]);

        if (false !== $classId) {
            $this->reportClass((int) $classId);
        }
    }

    private function reportClass(int $classId): void
    {
        if ($classId < 1) {
            return;
        }

        foreach ($this->connection->fetchFirstColumn('SELECT id FROM tl_consho_product WHERE taxClass = ? ORDER BY id', [$classId]) as $productId) {
            $this->queue->add((int) $productId);
        }
    }
}

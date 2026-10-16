<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;

/**
 * Reports product changes for both catalogue projections: the list prices
 * (A-4b) and the visibility (B-10). The projectors widen each id — the list
 * prices to the product's variants, the visibility to its main product and to
 * the main products found in the stored rows.
 *
 * - delete: DC_Table deletes the prices as child records WITHOUT calling their
 *   own ondelete — only the product's. A main product reports its variants
 *   as well, see onDelete().
 * - copy: a copied variant without prices of its own inherits, and no price
 *   row is copied that could report it.
 * - undo: a restored variant that inherits gets its row back; restored price
 *   rows report themselves.
 * - submit: everything the product mask saves — publishing, the display
 *   period, shops, translations and safety texts, a new parent. Also the
 *   toggle in the list and "edit multiple": DC_Table calls submit() for all of
 *   them (Contao 5.7).
 * - restore version: a restored version can change publishing or the display
 *   period.
 */
class ProductRefreshTriggerListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogRefreshQueue $queue,
    ) {
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $this->queue->add((int) $dc->id);
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.oncopy')]
    public function onCopy(int|string $insertId, DataContainer $dc): void
    {
        $this->queue->add((int) $insertId);
    }

    /**
     * Reports the variants too, while they still exist: VariantDeleteListener
     * deletes them in a later ondelete (lower priority), so by the end of the
     * request the projector can no longer widen the main product to them, and
     * a variant's list price row would stay behind (found at the acceptance on
     * 2026-10-14). The deleted ids themselves stand for themselves in the
     * projector and lose their rows.
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.ondelete')]
    public function onDelete(DataContainer $dc, int|string $undoId): void
    {
        $this->queue->add((int) $dc->id);

        foreach ($this->connection->fetchFirstColumn('SELECT id FROM tl_consho_product WHERE pid = ?', [(int) $dc->id]) as $variant) {
            $this->queue->add((int) $variant);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.onundo')]
    public function onUndo(string $table, array $row, DataContainer $dc): void
    {
        $this->queue->add((int) ($row['id'] ?? 0));
    }

    /**
     * 🔴 The id comes from the parameter, NOT from $data: Versions::restore()
     * removes id, pid, ptable and sorting from $data before it calls this
     * callback — see PriceRefreshTriggerListener::onRestoreVersion().
     *
     * @param array<string, mixed> $data
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.onrestore_version')]
    public function onRestoreVersion(string $table, int $id, int $version, array $data): void
    {
        $this->queue->add($id);
    }
}

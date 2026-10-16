<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;

/**
 * Reports every change to a price row, so its product's list price — and
 * after it its visibility and "from" price (B-10) — is recalculated at the end
 * of the request (A-4b).
 *
 * Only reports. Order in DC_Table (Contao 5.7): ondelete runs before the row
 * is deleted, and copyChildren() calls oncopy once per copied row, after
 * inserting it. Calculated here, a deleted price would still count and a
 * product with ten prices would be calculated ten times.
 *
 * Several methods rather than one __invoke: the five callbacks have five
 * signatures, and they share the one purpose — as in TranslationVersionListener.
 *
 * Moving a price to another product is not a case: tl_consho_price is
 * notSortable, so neither the operation nor the select mode offers it.
 */
class PriceRefreshTriggerListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogRefreshQueue $queue,
    ) {
    }

    #[AsCallback(table: 'tl_consho_price', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $this->reportProductOf((int) $dc->id);
    }

    #[AsCallback(table: 'tl_consho_price', target: 'config.oncopy')]
    public function onCopy(int|string $insertId, DataContainer $dc): void
    {
        // The new row's pid: copied along with a product, it is the copy.
        $this->reportProductOf((int) $insertId);
    }

    #[AsCallback(table: 'tl_consho_price', target: 'config.ondelete')]
    public function onDelete(DataContainer $dc, int|string $undoId): void
    {
        // The row is still there — see the class comment.
        $this->reportProductOf((int) $dc->id);
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_price', target: 'config.onundo')]
    public function onUndo(string $table, array $row, DataContainer $dc): void
    {
        $this->queue->add((int) ($row['pid'] ?? 0));
    }

    /**
     * 🔴 The product comes from the row, NOT from $data. Versions::restore()
     * removes id, pid, ptable and sorting from $data before it calls this
     * callback (Contao 5.7, Versions.php, "Do not overwrite ID, PID…"). Reading
     * $data['pid'] reported nothing — seen on the test server on 2026-09-13, after a
     * unit test had passed with a $data the core never hands over.
     *
     * @param array<string, mixed> $data
     */
    #[AsCallback(table: 'tl_consho_price', target: 'config.onrestore_version')]
    public function onRestoreVersion(string $table, int $id, int $version, array $data): void
    {
        $this->reportProductOf($id);
    }

    private function reportProductOf(int $priceId): void
    {
        $productId = $this->connection->fetchOne('SELECT pid FROM tl_consho_price WHERE id = ?', [$priceId]);

        if (false !== $productId) {
            $this->queue->add((int) $productId);
        }
    }
}

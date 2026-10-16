<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;

/**
 * Reports changes to shops and root pages — the ones that decide which
 * languages, default language and currency the visibility projection is built
 * for (B-10).
 *
 * Only a request for a comparison. CatalogRefreshListener compares each shop's
 * fingerprint at the end of the request and rebuilds a shop only when it
 * differs: renaming a shop or saving an ordinary page costs one comparison.
 *
 * tl_page without a check of the page type: in onsubmit the type before
 * saving is no longer readable, and a page that stops being a root page has to
 * be noticed as well.
 *
 * Several methods rather than one __invoke: the callbacks have different
 * signatures and share the one purpose — as in PriceRefreshTriggerListener.
 */
class ShopSyncTriggerListener
{
    public function __construct(private readonly CatalogRefreshQueue $queue)
    {
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_page', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $this->queue->requestShopSync();
    }

    #[AsCallback(table: 'tl_page', target: 'config.oncopy')]
    public function onCopy(int|string $insertId, DataContainer $dc): void
    {
        $this->queue->requestShopSync();
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'config.ondelete')]
    #[AsCallback(table: 'tl_page', target: 'config.ondelete')]
    public function onDelete(DataContainer $dc, int|string $undoId): void
    {
        // The row is still there; the comparison runs after the delete.
        $this->queue->requestShopSync();
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_shop', target: 'config.onundo')]
    #[AsCallback(table: 'tl_page', target: 'config.onundo')]
    public function onUndo(string $table, array $row, DataContainer $dc): void
    {
        $this->queue->requestShopSync();
    }

    /**
     * @param array<string, mixed> $data
     */
    #[AsCallback(table: 'tl_consho_shop', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_page', target: 'config.onrestore_version')]
    public function onRestoreVersion(string $table, int $id, int $version, array $data): void
    {
        $this->queue->requestShopSync();
    }
}

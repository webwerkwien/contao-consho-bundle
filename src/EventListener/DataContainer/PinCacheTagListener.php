<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalPinRepository;

/**
 * A pin restored from the recycle bin invalidates the page of its listing (B-7).
 *
 * The page carries `contao.db.tl_content.<listing>` — the listing tags itself
 * (AbstractContentElementController::tagResponse()). A pin is never rendered in
 * the front end, so its own tag is on no page at all.
 *
 * Saving, toggling, cutting and deleting a pin need nothing from Consho:
 * DC_Table's constructor writes findPtable() into `config.ptable` — the row's
 * ptable when a record is edited, the `ptable` query parameter when one is
 * created in the children view — so DataContainer::addPtableTags() already
 * adds the listing's tag. Read in Contao 5.7 (DC_Table.php:229, 374),
 * after an earlier version of this class had added it a second time.
 *
 * The recycle bin is the exception B-6 found: DC_Table::undo() invalidates the
 * tags of tl_undo, never those of the restored record.
 */
final class PinCacheTagListener
{
    public function __construct(private readonly CacheTagManager|null $tagManager = null)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_content', target: 'config.onundo')]
    public function onUndo(string $table, array $row, DataContainer $dc): void
    {
        if (DbalPinRepository::TYPE !== ($row['type'] ?? null) || 'tl_content' !== ($row['ptable'] ?? null) || (int) ($row['pid'] ?? 0) < 1) {
            return;
        }

        $this->tagManager?->invalidateTags(['contao.db.tl_content.'.(int) $row['pid']]);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Webwerkwien\ContaoConshoBundle\Seo\ProductAddressState;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;

/**
 * Records the old address when saving a product in the mask moves it — a
 * changed alias, translated alias, sku, manufacturer or product type, alone or
 * several in one save.
 *
 * ── Why not a save callback per field ─────────────────────────────────────
 *
 * Contao 5.7, DC_Table: every field's save callback runs in save(), which only
 * collects the value in arrSubmit. submit() then runs onbeforesubmit, the ONE
 * UPDATE, and onsubmit — in that order. So during the save callbacks the
 * database still holds every old value, and an old address rendered from it
 * there is complete.
 *
 * Two things still break a callback per field:
 *
 * 1. The NEW address is not known yet. The callback for the alias does not
 *    know the manufacturer that comes later in the palette, and the new path
 *    it compares with and clears a row on would be half new — possibly a real
 *    earlier address of the product, whose row would go.
 * 2. Translations are written in onsubmit (TranslationFieldsListener::store()),
 *    AFTER the UPDATE. Recorded there against the database, a translated
 *    alias changing together with the manufacturer files an address that
 *    never existed: new manufacturer, old translation.
 *
 * So the state is taken once before anything is written, and compared once
 * after all of it is.
 *
 * ── Where the state before is taken ───────────────────────────────────────
 *
 * - onbeforesubmit, when the record's columns change: it runs right before
 *   the UPDATE, and only when there is one.
 * - onsubmit with priority 1, otherwise: without an UPDATE the columns are
 *   still the old ones, and the translations are written only by the onsubmit
 *   callback with priority 0. Contao puts priority > 0 in front of the other
 *   callbacks and ≤ 0 behind them, in descending order
 *   (DataContainerCallbackListener::addCallbacks()).
 *
 * The comparison runs in onsubmit with priority -1, after the translations.
 *
 * ── Edit multiple ─────────────────────────────────────────────────────────
 *
 * editAll() and overrideAll() call the same submit() once per record, with
 * $dc->id set to that record — the snapshot is kept per id. onsubmit is not
 * called after a field error (noReload), and neither is anything recorded.
 *
 * ── Limit ─────────────────────────────────────────────────────────────────
 *
 * ProductShopsListener writes the shop assignment in its save callback, before
 * onbeforesubmit. A product added to a shop in the same save as its
 * manufacturer changes gets a row for an address it never had in that shop.
 * It leads to the product itself and is only asked on a 404.
 */
class ProductAddressListener
{
    /**
     * @var array<int, ProductAddressState>
     */
    private array $before = [];

    public function __construct(private readonly RedirectRecorder $recorder)
    {
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.onbeforesubmit')]
    public function rememberBeforeUpdate(array $values, DataContainer $dc): array
    {
        $id = (int) $dc->id;
        $state = $id > 0 ? $this->recorder->productAddressState($id) : null;

        if (null !== $state) {
            $this->before[$id] = $state;
        }

        return $values;
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onsubmit', priority: 1)]
    public function rememberBeforeTranslations(DataContainer $dc): void
    {
        $id = (int) $dc->id;

        if ($id < 1 || isset($this->before[$id])) {
            return;
        }

        $state = $this->recorder->productAddressState($id);

        if (null !== $state) {
            $this->before[$id] = $state;
        }
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onsubmit', priority: -1)]
    public function recordMovedAddresses(DataContainer $dc): void
    {
        $id = (int) $dc->id;
        $before = $this->before[$id] ?? null;
        unset($this->before[$id]);

        if (null === $before) {
            return;
        }

        $this->recorder->recordProductAddress($id, $before, time());
    }
}

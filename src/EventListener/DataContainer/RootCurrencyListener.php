<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\DC_Table;
use Webwerkwien\ContaoConshoBundle\Shop\ShopCurrencies;

/**
 * The currency that applies below a start page (C-1c, spec 4.0).
 *
 * The counterpart of tl_consho_shop.currencies: the shop says which currencies
 * it CAN accept — a business rule hanging off the bank account, the payment
 * method and the shipping rates — and the start page picks the one that applies
 * to ITS audience. A currency is a way in, like the language and the domain,
 * not a property of the seller.
 *
 * Which is why the field is not simply always there:
 *
 * - no shop, or a shop with a single currency → nothing to choose, no field;
 * - two or more → a field offering exactly those.
 *
 * That decision falls per RECORD, not per installation, and that is the
 * difference to the price mask (CoupledPriceInputListener::onLoad()): a price
 * row belongs to no shop, so there the answer is the same for every row in the
 * table and an onload_callback may write it into the global DCA. Here the start
 * pages of shop A (one currency) and shop B (three) sit in the same table and
 * must not see the same mask — so the palette is adjusted through
 * `onpalette_callback`, which is handed the resolved palette of ONE record
 * (PaletteBuilder::getPalette()) and leaves the global DCA alone. DC_Table
 * calls it for the single edit mask and once per record in "edit multiple"
 * alike.
 *
 * ⚠️ `consho_shop` has no `submitOnChange`, on purpose. An auto-submit would
 * set tstamp = time() (DC_Table::submit()) and so end the tstamp = 0 state
 * reviseTable() uses to clean up abandoned new records — the reason the price
 * mask lost its auto-submit on 2026-09-15. The price of leaving it out is
 * mild: after picking a shop the editor saves once, and from then on the
 * currencies match.
 *
 * ── Two rules, not one, and the difference is deliberate ──────────────────
 *
 * save() REFUSES a currency the shop cannot accept; onBeforeSubmit() CORRECTS
 * one silently. Which of the two applies hangs on how many currencies the shop
 * had when the mask was built — something nobody can see from the record.
 *
 * That is not an inconsistency but the point: save() only ever runs where the
 * field is in the palette, i.e. where an editor is looking at a widget and
 * would read the message. Where the field is hidden there is no widget, no
 * message anyone would see, and a refusal would only block the save of a page
 * whose currency the editor was never offered a say in. Loud where it can be
 * heard, quiet where it cannot.
 */
class RootCurrencyListener
{
    public function __construct(private readonly ShopCurrencies $currencies)
    {
    }

    /**
     * Hides the field where there is nothing to choose.
     *
     * The stored record is enough here, although the shop may have been changed
     * in the very form being built: `getPalette()` runs before DC_Table walks
     * the fields, so `arrSubmit` is still empty and `getActiveRecord()` would
     * return the same thing. A shop changed now therefore only takes effect on
     * the next request — the accepted consequence of having no auto-submit.
     *
     * No `if (!$dc->id) return $palette;` here, although tl_files::adjustPalettes()
     * opens with exactly that. It needs the guard because it reads the FILE
     * SYSTEM by $dc->id and has nothing to fall back on; this reads a record,
     * and a missing record simply means no shop — which resolves to a hidden
     * field, the same direction as every other branch in this class. The guard
     * would have meant the opposite: show the field where the least is known.
     */
    #[AsCallback(table: 'tl_page', target: 'config.onpalette')]
    public function adjustPalette(string $palette, DataContainer $dc): string
    {
        $shopId = (int) (($dc->getCurrentRecord() ?? [])['consho_shop'] ?? 0);

        // `$shopId > 0` is logically redundant — of(0) is empty and fails the
        // count anyway — but it keeps the query away from every ordinary page
        // of the site structure, which is the same reason onBeforeSubmit()
        // leaves early.
        if ($shopId > 0 && \count($this->currencies->of($shopId)) > 1) {
            return $palette;
        }

        // A no-op on every palette that does not carry the field, which is all
        // of them except "root" and "rootfallback".
        return PaletteManipulator::create()
            ->removeField('consho_currency')
            ->applyToString($palette)
        ;
    }

    /**
     * Exactly the currencies of this start page's shop, sorted.
     *
     * Plain codes, like the price mask (CoupledPriceInputListener) and unlike
     * the shop mask: this list holds a handful of entries an operator has
     * chosen themselves, not 290 to search through.
     *
     * `getActiveRecord()`, not `getCurrentRecord()`: on a record whose shop was
     * just picked, the stored one is still the old one — the new value lives in
     * `arrSubmit` until the end of the request. That merge only works because
     * `consho_shop` stands BEFORE `consho_currency` in the palette and has
     * therefore already been processed (DC_Table::edit()). The field order is
     * functional, not cosmetic.
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_page', target: 'fields.consho_currency.options')]
    public function options(DC_Table|null $dc = null): array
    {
        $currencies = $this->currencies->of($this->shopOf($dc));

        return array_combine($currencies, $currencies);
    }

    /**
     * Refuses a currency this start page's shop cannot accept.
     *
     * This is the actual lock, not the option list: Contao collects a stored
     * value outside the options in `unknownOption`, offers it back and accepts
     * it on save, expressly "so it is not lost when saving the record"
     * (SelectMenu). So narrowing the shop's currencies does not by itself
     * detach a start page still pointing at one of the dropped ones — this
     * does, and names it. Same hole, same answer as
     * CoupledPriceInputListener::saveCurrency().
     *
     * ⚠️ And a second reason it has to exist: "edit multiple → override" never
     * asks for a palette at all. DC_Table::overrideAll() builds its field list
     * from the selected fields and sets `alwaysSave`, without calling
     * getPalette() — so the hiding above does not apply there, and a currency
     * can be written to any page, including one with no shop. That case comes
     * out right only because this callback runs per record.
     *
     * Empty passes: an empty mandatory value never reaches save(), so that
     * branch only matters if the field ever loses its mandatory flag.
     *
     * Unlike saveCurrency() this DOES normalise, and the difference is earned:
     * the migration of task 3 writes this column directly, never through a
     * mask. ShopCurrencies upper-cases the shop side for that exact reason
     * (ShopCurrenciesTest pins it), so a migrated "eur" would otherwise be
     * refused here as unknown although the shop accepts EUR.
     */
    #[AsCallback(table: 'tl_page', target: 'fields.consho_currency.save')]
    public function save(mixed $value, DC_Table|null $dc = null): string
    {
        $currency = strtoupper(trim((string) $value));

        if ('' === $currency) {
            return $currency;
        }

        $shopId = $this->shopOf($dc);

        if (\in_array($currency, $this->currencies->of($shopId), true)) {
            return $currency;
        }

        // Named separately, because through overrideAll this can land on a page
        // that has no shop at all — where "the shop of this start page" would
        // claim one that does not exist and send the operator looking for it.
        $title = $this->currencies->titleOf($shopId);

        throw new \RuntimeException(
            '' === $title
                ? \sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['rootCurrencyWithoutShop'], $currency)
                : \sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['rootCurrencyNotAllowed'], $currency, $title),
        );
    }

    /**
     * Keeps the stored currency answerable by the stored shop.
     *
     * Needed because the save_callback above does NOT run when the field is out
     * of the palette — and out of the palette is the normal case, a shop with
     * one currency. Without this a start page would keep an empty currency
     * forever, or an old one after its shop changed.
     *
     * ⚠️ It only runs when SOMETHING CHANGED. DC_Table::submit() calls the
     * onbeforesubmit_callback behind `if (!$this->noReload && !empty($arrValues))`,
     * and $arrValues is arrSubmit, which stays empty when no field differs from
     * what is stored. So this repairs a start page on its next real save, not
     * on opening and closing the mask — "save everything once" only fixes the
     * records something was actually changed on.
     *
     * The rule itself lives on ShopCurrencies::resolve(), beside the currencies
     * it resolves against, because the migration of task 3 and the finding of
     * task 7 need the same one.
     *
     * `getCurrentRecord()` here rather than `getActiveRecord()`, and that is
     * not the same slip it would be in a field callback: `submit()` moves
     * `arrSubmit` into its own `$arrValues` and empties it before calling this,
     * so the active record holds nothing extra. `$values` IS the submitted
     * half, and valueOf() merges the two exactly as getActiveRecord() would.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    #[AsCallback(table: 'tl_page', target: 'config.onbeforesubmit')]
    public function onBeforeSubmit(array $values, DC_Table $dc): array
    {
        $record = $dc->getCurrentRecord() ?? [];
        $stored = (string) (self::valueOf($values, $record, 'consho_currency') ?? '');
        $shopId = (int) (self::valueOf($values, $record, 'consho_shop') ?? 0);

        // Every page of the site structure passes through here, and all but the
        // start pages carry no shop. Answered without asking the database, and
        // without writing a key into a save that has nothing to do with money.
        if ($shopId < 1) {
            if ('' !== $stored) {
                $values['consho_currency'] = '';
            }

            return $values;
        }

        $resolved = $this->currencies->resolve($shopId, $stored);

        if ($resolved !== $stored) {
            $values['consho_currency'] = $resolved ?? '';
        }

        return $values;
    }

    /**
     * The shop of the record a FIELD callback is working on.
     *
     * `getActiveRecord()`, and here the choice is load-bearing rather than
     * equivalent: options() and save() run inside DC_Table's field loop, where
     * a shop just picked is already in `arrSubmit` but not yet in the stored
     * row. getCurrentRecord() would answer with the shop of the last save and
     * offer — or accept — the wrong currencies for a whole request.
     *
     * (adjustPalette() and onBeforeSubmit() read the stored record instead,
     * each for its own measured reason; see their docblocks. The difference
     * belongs to the callback, not to the class.)
     */
    private function shopOf(DC_Table|null $dc): int
    {
        return (int) (($dc?->getActiveRecord() ?? [])['consho_shop'] ?? 0);
    }

    /**
     * A submitted value wins even when it is null — as in
     * CoupledPriceInputListener, where the same merge is spelled out.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $record
     */
    private static function valueOf(array $values, array $record, string $field): mixed
    {
        return \array_key_exists($field, $values) ? $values[$field] : ($record[$field] ?? null);
    }
}

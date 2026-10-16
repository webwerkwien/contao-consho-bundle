<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Intl\Exception\MissingResourceException;
use Webwerkwien\ContaoConshoBundle\Shop\CurrencySelection;

/**
 * The currencies a shop can accept (C-1c, spec 4.0).
 *
 * The full ISO 4217 list, not a filtered one — and that is not a contradiction
 * with the price mask, which offers only the currencies actually sold
 * (CoupledPriceInputListener::currencyOptions()). This field DETERMINES what is
 * possible, the price mask FOLLOWS from it. A filtered list would have nothing
 * left to filter from: the shop is where the answer is written down.
 */
class ShopCurrenciesListener
{
    /**
     * ISO 4217 code => name in the back end language, sorted by name.
     *
     * The code rides along in the label on purpose. `chosen` searches the
     * LABEL, and an operator types "USD", not "US-Dollar" — with the name
     * alone that search would come up empty among 290-odd entries.
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_shop', target: 'fields.currencies.options')]
    public function options(DataContainer|null $dc = null): array
    {
        $locale = $this->locale();
        $options = [];

        foreach (Currencies::getCurrencyCodes() as $code) {
            $options[$code] = \sprintf('%s (%s)', Currencies::getName($code, $locale), $code);
        }

        asort($options);

        return $options;
    }

    /**
     * Refuses a selection that names no usable currency.
     *
     * What `mandatory` does and does not cover here — read off Contao 5.7 on
     * 2026-09-21, because the answer is not the obvious one:
     *
     * `Widget::validator()` recurses into an array and RETURNS before the
     * mandatory branch, which therefore only ever sees scalars — an empty array
     * would pass it untouched. It never gets that far, though:
     * `SelectMenu::generate()` puts `<input type="hidden" name="currencies"
     * value="">` in front of a multiple select, so an empty selection arrives as
     * the scalar '' and does hit the mandatory branch. Without that hidden field
     * nothing would happen at all: `DataContainer::row()` validates only
     * `if (Input::post($this->strInputName) !== null)`, and a multiple select
     * with nothing ticked posts no key of its own. So `mandatory` does hold in
     * the mask — on a detail of the widget's markup, not on the flag itself.
     *
     * What it does NOT cover is a stored value outside the options: Contao
     * collects those in `unknownOption` and offers them back
     * (`Widget::getAttributesFromDca()`), so saving the record keeps them. That
     * is this callback's real work — the same hole
     * `CoupledPriceInputListener::saveCurrency()` closes for the price mask. The
     * empty check rides along and is what survives if the flag is ever dropped.
     */
    #[AsCallback(table: 'tl_consho_shop', target: 'fields.currencies.save')]
    public function save(mixed $value, DataContainer|null $dc = null): string
    {
        // The same parser ShopCurrencies reads with, so the mask and the front
        // end can only ever differ in the reaction: refused here, dropped there.
        $selection = CurrencySelection::fromBlob($value);

        if ([] !== $selection->invalid) {
            throw new \RuntimeException(
                \sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['shopCurrencyUnknown'], $selection->invalid[0]),
            );
        }

        if ([] === $selection->valid) {
            throw new \RuntimeException($GLOBALS['TL_LANG']['CONSHO']['errors']['shopCurrenciesEmpty']);
        }

        // Serialised, not an array: by this point DC_Table has already turned
        // the widget's array into a string, and the column is a blob.
        return serialize($selection->valid);
    }

    /**
     * The back end language, in the spelling Symfony's Intl data uses.
     *
     * ⚠️ The hyphen is not cosmetic, measured on 2026-09-21. Contao hands out a
     * language TAG: `ContaoFramework::setDefaultLanguage()` and `PageRegular`
     * both write `LocaleUtil::formatAsLanguageTag()`, which is a
     * `str_replace('_', '-', …)`. Symfony's data directory is named the other
     * way round, so `de-CH` misses `de_CH` on the direct read and only
     * `Locale::getFallback()` rescues it — via `locale_parse()`, which does
     * understand hyphens, and lands on plain `de`. The name then comes from
     * German, not Swiss German. For 291 of the 294 codes that is the same
     * string, which is why it looks inert; for BYN, BYR and STN it is not
     * ("Weissrussischer Rubel" against "Weißrussischer Rubel" — Swiss German
     * has no ß). ShopCurrenciesListenerTest pins that difference.
     *
     * The probe is the second half: anything shaped like a language falls back
     * to its parent and finally to the root bundle, so `de_CH_x` and even `xx`
     * answer. What does NOT fall back is a value that is no locale at all —
     * `12345` and path-like strings make the reader throw.
     * `$GLOBALS['TL_LANGUAGE']` should never hold one, which is the point: one
     * probe here is cheaper than the whole shop mask dying on a stray value.
     */
    private function locale(): string
    {
        $locale = str_replace('-', '_', (string) ($GLOBALS['TL_LANGUAGE'] ?? 'en'));

        try {
            Currencies::getName('EUR', $locale);
        } catch (MissingResourceException) {
            return 'en';
        }

        return $locale;
    }
}

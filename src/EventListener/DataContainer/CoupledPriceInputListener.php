<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Pricing\HomeCountries;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceEntry;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceInput;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceInputMode;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceInputProblem;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceInputRefused;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;

/**
 * The coupled price mask (C22, spec 5.3): currency, country, "gross | net",
 * amount — and only net is stored. Shared by product prices and shipping
 * amounts (C-2); a subclass names its table and where the tax class comes from.
 *
 * Price rows belong to no shop (A7), so the country a gross amount refers to
 * comes from the shops that sell in the row's currency: their markets (C25).
 * With one home country per currency — the normal case — the country field is
 * not shown at all.
 *
 * Several methods rather than one __invoke: the callbacks have different
 * signatures and share the one purpose, as in PriceRefreshTriggerListener.
 *
 * ⚠️ No #[AsCallback] in this class, ever. Symfony collects the attribute
 * through getMethods(), inherited methods included: an #[AsCallback] on a
 * method here would apply to every subclass that does not override that
 * method — on that attribute's table, i.e. possibly the wrong one. With no
 * attributes here, a forgotten override registers nothing instead, which
 * fails loudly (a missing callback) rather than quietly (a callback on the
 * wrong table). Each subclass overrides the public methods only to attach
 * its own attribute.
 */
abstract class CoupledPriceInputListener
{
    /**
     * The rules themselves (CW-2). Built here from the same two services
     * rather than injected: it holds no state of its own, and the mask's
     * tests keep constructing the listener as before — they are the proof
     * that the mask did not change.
     */
    private readonly PriceInput $input;

    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly HomeCountries $homeCountries,
        private readonly CountryProvider $countries,
        protected readonly Connection $connection,
        private readonly PriceEntry $entry,
        private readonly RequestStack $requests,
        private readonly \Closure|null $clock = null,
    ) {
        $this->input = new PriceInput($homeCountries, $entry);
    }

    /**
     * Presets the currency when there is only one, and hides the country when
     * no currency offers a choice of home countries.
     *
     * Decided for the table, not per record: a mask whose fields change from
     * one row of the same product to the next is harder to read than one extra
     * field in the rare case of two home countries.
     */
    public function onLoad(DataContainer|null $dc = null): void
    {
        $currencies = $this->homeCountries->currencies();

        // Always set, also to '': without it DC_Table::create() falls back to
        // the column default EUR — preselected even where no shop sells in euro.
        $GLOBALS['TL_DCA'][$this->table()]['fields']['currency']['default'] = 1 === \count($currencies) ? $currencies[0] : '';

        if ($this->homeCountries->offersChoice()) {
            return;
        }

        PaletteManipulator::create()
            ->removeField('inputCountry')
            ->applyToPalette('default', $this->table())
        ;
    }

    /**
     * Only currencies some shop sells in (C22). The old text field took "EURO"
     * or "XYZ", and a price row in a currency without a shop is shown nowhere.
     *
     * @return array<string, string>
     */
    public function currencyOptions(): array
    {
        $currencies = $this->homeCountries->currencies();

        return array_combine($currencies, $currencies);
    }

    /**
     * Rejects a currency no shop sells in.
     *
     * The options alone do not: Contao offers a stored value outside them as
     * "(unknown)" and accepts it (Widget::getAttributesFromDca()). save() runs
     * this callback before its "changed?" check, so an old row in such a
     * currency is caught too, not only a new choice. Empty passes: an empty
     * mandatory value never reaches save(), so that branch only matters if
     * the field loses its mandatory flag.
     */
    public function saveCurrency(mixed $value, DataContainer|null $dc = null): string
    {
        $currency = (string) $value;

        try {
            $this->input->assertCurrency($currency);
        } catch (PriceInputRefused $refused) {
            throw new \RuntimeException($this->message($refused));
        }

        return $currency;
    }

    /**
     * Every home country. Without an auto-submit the list cannot follow a
     * currency just changed in the form, so onBeforeSubmit() checks on save
     * that the country belongs to the currency.
     *
     * @return array<string, string> ISO code => country name
     */
    public function countryOptions(DataContainer|null $dc = null): array
    {
        $names = $this->countries->all($GLOBALS['TL_LANGUAGE'] ?? 'en');
        $options = [];

        foreach ($this->homeCountries->all() as $code) {
            $options[$code] = $names[$code] ?? $code;
        }

        return $options;
    }

    /**
     * The amount is not stored: the mask shows it from net, with the rate at
     * the price's start, today at the earliest (decision 2026-09-15,
     * conversionTime()). So it shows the gross the front end shows while the
     * price is valid — also after a rate change.
     */
    public function loadAmount(mixed $value, DataContainer $dc): string
    {
        $record = $dc->getCurrentRecord();

        if (null === $record) {
            return '';
        }

        $net = (string) ($record['net'] ?? '0');

        // A new row — never saved, net 0 — shows an empty amount: "0.0000"
        // would look like a price. A duplicated single price also has tstamp 0
        // (DC_Table::copy()) but a real net, and shows it.
        if (0 === (int) ($record['tstamp'] ?? 0) && BigDecimal::of($net)->isZero()) {
            return '';
        }

        $mode = PriceInputMode::tryFrom((string) ($record['inputMode'] ?? '')) ?? PriceInputMode::Net;

        if (PriceInputMode::Net === $mode) {
            return $this->entry->toAmount($mode, $net, 0, '', $this->conversionTime($record), (string) ($record['currency'] ?? ''));
        }

        $country = $this->homeCountries->resolve((string) ($record['currency'] ?? ''), (string) ($record['inputCountry'] ?? ''));
        $taxClass = $this->taxClassOf($record);

        if (null === $country || $taxClass < 1) {
            return '';
        }

        try {
            return $this->entry->toAmount($mode, $net, $taxClass, $country, $this->conversionTime($record), (string) ($record['currency'] ?? ''));
        } catch (TaxRateNotFound) {
            // Empty, not the net: saved unchanged, it would be read as gross.
            return '';
        }
    }

    /**
     * A row without a mode — restored from a version made before C-1b —
     * opens as net: that is what its amount shows (loadAmount()).
     */
    public function loadInputMode(mixed $value): string
    {
        return (PriceInputMode::tryFrom((string) $value) ?? PriceInputMode::Net)->value;
    }

    /**
     * Turns the typed amount into net before DC_Table writes the row.
     *
     * DC_Table hands over only changed fields, so amount, inputMode and
     * inputCountry carry alwaysSave in the DCA. `amount` has no column and
     * leaves the values in every case — submit() writes every key it gets.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function onBeforeSubmit(array $values, DataContainer $dc): array
    {
        $request = $this->requests->getCurrentRequest();
        $auto = 'auto' === $request?->request->get('SUBMIT_TYPE');
        $record = $dc->getCurrentRecord() ?? [];

        // No field in this mask auto-submits since 2026-09-15 (a saved price
        // must not go live from a bare currency change) — this guard stays for
        // a field that might get submitOnChange later, or another bundle's DCA
        // change. submit() has set tstamp = time() already: with tstamp 0
        // "back" still carries `revise` and reviseTable() deletes it,
        // loadAmount() still shows an empty amount, and the price readers skip
        // it (docs/block-c-warenkorb-checkout.md section 5.3).
        if ($auto && 0 === (int) ($record['tstamp'] ?? 0)) {
            $values['tstamp'] = 0;
        }

        // The stored country always belongs to the stored currency — kept for
        // an auto-submit too, should one ever reach here again.
        if (\array_key_exists('currency', $values) || \array_key_exists('inputCountry', $values)) {
            $values['inputCountry'] = $this->homeCountries->resolve((string) self::valueOf($values, $record, 'currency'), (string) self::valueOf($values, $record, 'inputCountry')) ?? '';
        }

        if (!$auto) {
            $this->checkBeforeConversion($values, $record);
        }

        if (!\array_key_exists('amount', $values)) {
            // A new row saved for real without an amount would become a live
            // price of 0 — e.g. for a user group that may edit the currency but
            // not the amount. An auto-submit keeps the row new (tstamp 0 above).
            if (!$auto && 0 === (int) ($record['tstamp'] ?? 0) && BigDecimal::of((string) ($record['net'] ?? '0'))->isZero()) {
                throw new \RuntimeException($GLOBALS['TL_LANG']['CONSHO']['errors']['priceAmountMissing']);
            }

            return $values;
        }

        // "Edit multiple → override" offers the amount without "gross | net": one
        // typed number would become net on net rows and gross on gross rows.
        if (!\array_key_exists('inputMode', $values) && 'overrideAll' === $request?->query->get('act')) {
            throw new \RuntimeException($GLOBALS['TL_LANG']['CONSHO']['errors']['priceOverrideNeedsMode']);
        }

        $amount = trim((string) $values['amount']);
        unset($values['amount']);

        // No auto-submit reaches here since 2026-09-15 — kept for a field that
        // might get submitOnChange later.
        if ('' === $amount || $auto) {
            return $values;
        }

        $mode = PriceInputMode::tryFrom((string) self::valueOf($values, $record, 'inputMode')) ?? PriceInputMode::Net;

        try {
            $result = $this->input->convert(
                $amount,
                $mode,
                (string) self::valueOf($values, $record, 'currency'),
                (string) self::valueOf($values, $record, 'inputCountry'),
                // Only a gross amount needs the class; a net one spares the query.
                PriceInputMode::Gross === $mode ? $this->taxClassOf($record) : 0,
                (int) self::valueOf($values, $record, 'validFrom'),
                $this->now(),
            );
        } catch (PriceInputRefused $refused) {
            throw new \RuntimeException($this->message($refused));
        }

        $values['inputMode'] = $result->mode->value;
        $values['inputCountry'] = $result->country ?? '';
        $values['net'] = $result->net;

        return $values;
    }

    /**
     * Keeps a never-saved row from going live through a copy.
     *
     * DC_Table::copyChildren() stamps every copied child row with time() —
     * also a row whose tstamp is 0, i.e. one opened with "new" and abandoned.
     * The copy would become a live price of net 0. copyAll does the same
     * through copy(true). A single act=copy leaves tstamp 0 (DC_Table::copy())
     * and is left alone: it is a draft that only counts once saved.
     *
     * $dc->id is the source row in both paths: copy() passes itself,
     * copyChildren() sets the id of the copied row.
     *
     * Not deleted: copyAll() (DC_Table::copyAll()) pastes every following
     * selected row after the previous copy — it sets pid=<previous copy id>
     * & mode=1, and getNewPosition()'s pid-only branch (no "sorting" column
     * here) looks up that previous copy's pid with
     * `SELECT pid FROM … WHERE id=<previous copy id>`. If that row no longer
     * exists, the lookup finds nothing, $this->set['pid'] is left untouched,
     * and the next copy keeps the pid it was created with — the SOURCE's —
     * landing it (and every later copy chained off it) back in the source
     * product or shipping method instead of the target. Resetting the copy's
     * tstamp to 0 keeps its row alive as an id to paste after, while the
     * tstamp > 0 rule (loadAmount(), afterCopy() checks, front-end readers)
     * still hides it exactly like the abandoned draft it copies.
     */
    public function onCopy(int|string $insertId, DataContainer $dc): void
    {
        $copyStamp = $this->connection->fetchOne('SELECT tstamp FROM '.$this->table().' WHERE id = ?', [(int) $insertId]);

        if (false === $copyStamp) {
            return;
        }

        $sourceStamp = $this->connection->fetchOne('SELECT tstamp FROM '.$this->table().' WHERE id = ?', [(int) $dc->id]);

        if ((int) $copyStamp > 0 && false !== $sourceStamp && 0 === (int) $sourceStamp) {
            $this->connection->update($this->table(), ['tstamp' => 0], ['id' => (int) $insertId]);

            return;
        }

        $this->afterCopy((int) $insertId);
    }

    /**
     * The table whose mask this couples.
     */
    abstract protected function table(): string;

    /**
     * The tax class a gross amount of this row is converted with: the product's
     * for a price, the method's reference class for a shipping amount.
     *
     * @param array<string, mixed> $record
     */
    abstract protected function taxClassOf(array $record): int;

    /**
     * Key in $GLOBALS['TL_LANG']['CONSHO']['errors'] when that class is missing.
     */
    abstract protected function taxClassMissingKey(): string;

    /**
     * A table's own check on a real save, before the amount is converted.
     * Throw a \RuntimeException with a language text to refuse the save.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $record
     */
    protected function checkBeforeConversion(array $values, array $record): void
    {
    }

    /**
     * A table's own step after a copy that was kept (onCopy()).
     */
    protected function afterCopy(int $insertId): void
    {
    }

    /**
     * The mask's language string for a refusal; the tax class one is the
     * table's own (taxClassMissingKey()).
     */
    private function message(PriceInputRefused $refused): string
    {
        $errors = $GLOBALS['TL_LANG']['CONSHO']['errors'];

        return match ($refused->problem) {
            PriceInputProblem::CurrencyWithoutShop => \sprintf($errors['priceCurrencyWithoutShop'], $refused->currency),
            PriceInputProblem::CountryMissing => \sprintf($errors['priceCountryMissing'], $refused->currency, implode(', ', array_map($this->countryName(...), $refused->countries))),
            PriceInputProblem::TaxClassMissing => $errors[$this->taxClassMissingKey()],
            PriceInputProblem::TaxRateMissing => \sprintf($errors['priceTaxRateMissing'], $this->countryName($refused->country), date('Y-m-d', $refused->timestamp)),
            PriceInputProblem::Negative => $errors['priceNegative'],
            PriceInputProblem::TooLarge => $errors['priceTooLarge'],
            PriceInputProblem::InvalidAmount => $errors['priceInvalid'],
        };
    }

    private function countryName(string $code): string
    {
        return $this->countries->all($GLOBALS['TL_LANGUAGE'] ?? 'en')[$code] ?? $code;
    }

    private function now(): int
    {
        return null !== $this->clock ? ($this->clock)() : time();
    }

    /**
     * The same moment as on save (PriceInput::conversionTime()), or an
     * unchanged save would change the net.
     *
     * @param array<string, mixed> $record
     */
    private function conversionTime(array $record): int
    {
        return PriceInput::conversionTime((int) ($record['validFrom'] ?? 0), $this->now());
    }

    /**
     * A submitted value wins even when it is null — a cleared date arrives as
     * null, and `??` would fall back to the stored one.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $record
     */
    protected static function valueOf(array $values, array $record, string $field): mixed
    {
        return \array_key_exists($field, $values) ? $values[$field] : ($record[$field] ?? null);
    }
}

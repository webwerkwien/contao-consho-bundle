<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;

/**
 * The rules of the coupled price mask (C22, spec 5.3) without the mask: a
 * typed amount "gross | net" in, the net to store out — or a refusal.
 *
 * Product prices and shipping amounts both use it (CoupledPriceInputListener),
 * and so does a console command (Connect Stufe 2, CW-2). Nothing here reads
 * the request: what the mask knows from the form — the auto-submit, "edit
 * multiple" — stays in the mask.
 */
class PriceInput
{
    /**
     * net is decimal(12,4): eight integer digits.
     */
    private const MAX_NET = '99999999.9999';

    public function __construct(
        private readonly HomeCountries $homeCountries,
        private readonly PriceEntry $entry,
    ) {
    }

    /**
     * Only currencies some shop sells in: a price row in another currency is
     * shown nowhere. Empty passes; whether a currency is required is the
     * caller's business.
     *
     * @throws PriceInputRefused
     */
    public function assertCurrency(string $currency): void
    {
        if ('' !== $currency && !\in_array($currency, $this->homeCountries->currencies(), true)) {
            throw new PriceInputRefused(PriceInputProblem::CurrencyWithoutShop, currency: $currency);
        }
    }

    /**
     * @param string $country   the country chosen for the row; kept only if it
     *                          is a home country of the currency
     * @param int    $taxClass  the class a gross amount is converted with
     * @param int    $validFrom the price's start, 0 for none
     *
     * @throws PriceInputRefused
     */
    public function convert(string $amount, PriceInputMode $mode, string $currency, string $country, int $taxClass, int $validFrom, int $now): PriceInputResult
    {
        try {
            $typed = BigDecimal::of(trim($amount));
        } catch (NumberFormatException) {
            throw new PriceInputRefused(PriceInputProblem::InvalidAmount);
        }

        // Not through 'minval' => 0 in the DCA: Widget::validator() checks
        // `if ($this->minval && …)`, and 0 means "no limit" there.
        if ($typed->isNegative()) {
            throw new PriceInputRefused(PriceInputProblem::Negative);
        }

        $resolved = $this->homeCountries->resolve($currency, $country);

        if (PriceInputMode::Net === $mode) {
            return new PriceInputResult($this->fitting($this->entry->toNet($mode, (string) $typed, 0, '', $now)), $mode, $resolved);
        }

        if (null === $resolved) {
            throw new PriceInputRefused(PriceInputProblem::CountryMissing, currency: $currency, countries: $this->homeCountries->countriesFor($currency));
        }

        if ($taxClass < 1) {
            throw new PriceInputRefused(PriceInputProblem::TaxClassMissing);
        }

        $timestamp = self::conversionTime($validFrom, $now);

        try {
            $net = $this->entry->toNet($mode, (string) $typed, $taxClass, $resolved, $timestamp);
        } catch (TaxRateNotFound) {
            throw new PriceInputRefused(PriceInputProblem::TaxRateMissing, country: $resolved, timestamp: $timestamp);
        }

        return new PriceInputResult($this->fitting($net), $mode, $resolved);
    }

    /**
     * The moment a gross amount is converted at — on load and on save alike,
     * or an unchanged save would change the net: the price's start, today at
     * the earliest. A price that begins after an announced rate change means
     * the gross from its beginning on.
     */
    public static function conversionTime(int $validFrom, int $now): int
    {
        return max($now, $validFrom);
    }

    /**
     * Checked on the resulting net, not on the typed amount — a gross converts
     * down, and Contao's maxval runs before a decimal comma becomes a dot.
     */
    private function fitting(string $net): string
    {
        if (BigDecimal::of($net)->isGreaterThan(self::MAX_NET)) {
            throw new PriceInputRefused(PriceInputProblem::TooLarge);
        }

        return $net;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;

/**
 * Turns a typed amount into the stored net and back (C22, spec 5.3).
 *
 * Shared by every mask that takes an amount "gross | net": price rows now,
 * shipping amounts in C-2. Net is the anchor; the typed amount itself is not
 * stored, the mask shows it again from net with the rate at the price's
 * start, today at the earliest (CoupledPriceInputListener::conversionTime()).
 */
final class PriceEntry
{
    public function __construct(private readonly GrossCalculator $calculator)
    {
    }

    /**
     * The net to store for a typed amount.
     *
     * Expects a validated decimal string; the caller validates.
     *
     * @throws TaxRateNotFound only for a gross amount
     * @throws \Brick\Math\Exception\NumberFormatException when the amount is not a decimal string
     */
    public function toNet(PriceInputMode $mode, string $amount, int $taxClassId, string $country, int $timestamp): string
    {
        if (PriceInputMode::Net === $mode) {
            return (string) BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp);
        }

        return $this->calculator->net($amount, $taxClassId, $country, $timestamp);
    }

    /**
     * The value shown in the mask for a stored net.
     *
     * Net mode keeps four places. Gross mode shows the currency's minor unit
     * while saving that unchanged gives back the very same net; otherwise —
     * a gross typed with more decimals than the currency has — four places,
     * so an unchanged save never shifts the net.
     *
     * Expects a validated decimal string; the caller validates.
     *
     * @throws TaxRateNotFound only for a gross amount
     * @throws \Brick\Math\Exception\NumberFormatException when the amount is not a decimal string
     */
    public function toAmount(PriceInputMode $mode, string $net, int $taxClassId, string $country, int $timestamp, string $currency): string
    {
        if (PriceInputMode::Net === $mode) {
            return (string) BigDecimal::of($net)->toScale(4, RoundingMode::HalfUp);
        }

        $gross = $this->calculator->gross($net, $taxClassId, $country, $timestamp);
        $rounded = (string) BigDecimal::of($gross)->toScale(Currencies::getFractionDigits($currency), RoundingMode::HalfUp);

        if (BigDecimal::of($this->calculator->net($rounded, $taxClassId, $country, $timestamp))->isEqualTo($net)) {
            return $rounded;
        }

        return $gross;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;

/**
 * The gross amount a visitor sees before a delivery country is known.
 *
 * Net is the anchor (C22): a price row stores net only, and gross follows from
 * the article's tax class and a country. Before checkout that country is the
 * shop's home country (C25) — the only one known, and under the OSS threshold
 * the one whose rate applies anyway.
 *
 * The rate comes from TaxResolver, the one place that knows validity periods.
 * The arithmetic is OrderFactory's for a line of quantity one: tax on eight
 * places, the sum on four — so the listing, the product page and the order
 * cannot differ by a rounding step.
 */
final class GrossCalculator
{
    public function __construct(private readonly TaxResolver $taxes)
    {
    }

    /**
     * @throws TaxRateNotFound
     */
    public function gross(string $net, int $taxClassId, string $country, int $timestamp): string
    {
        $rate = BigDecimal::of($this->taxes->resolveRate($taxClassId, $country, $timestamp));
        $amount = BigDecimal::of($net);
        $tax = $amount->multipliedBy($rate)->dividedBy('100', 8, RoundingMode::HalfUp);

        return (string) $amount->plus($tax)->toScale(4, RoundingMode::HalfUp);
    }

    /**
     * The net amount behind a gross one, for the price mask (C22): whoever
     * types a gross price gets the net that shows that gross again.
     *
     * Net keeps four places. Its rounding error is at most 0.00005, and times
     * (1 + rate / 100) still below 0.0001 for any rate under 100 % — so gross()
     * returns a gross typed to the cent within 0.0001, which rounds half-up to
     * that cent again. A gross with more decimals may not come back as typed;
     * PriceEntry::toAmount() then shows four places, or the minor unit when
     * that saves to the same net.
     *
     * @throws TaxRateNotFound
     */
    public function net(string $gross, int $taxClassId, string $country, int $timestamp): string
    {
        $rate = BigDecimal::of($this->taxes->resolveRate($taxClassId, $country, $timestamp));

        return (string) BigDecimal::of($gross)
            ->multipliedBy('100')
            ->dividedBy($rate->plus('100'), 4, RoundingMode::HalfUp)
        ;
    }
}

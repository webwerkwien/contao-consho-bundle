<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Base price ("Grundpreis") required by price indication rules.
 *
 * price / content * reference — 4.50 EUR for 0.25 l shown as 18.00 per litre.
 *
 * Products without a unit have no base price, and that is the normal case
 * rather than an error: piece goods carry no content. Returning null instead
 * of dividing is what keeps every product page without a unit of measure
 * standing.
 */
final class BasePriceCalculator
{
    public function calculate(string $price, string $purchaseUnit, string $referenceUnit): string|null
    {
        $content = BigDecimal::of($purchaseUnit);
        $reference = BigDecimal::of($referenceUnit);

        if ($content->isZero() || $reference->isZero()) {
            return null;
        }

        /*
         * Divided at eight places before scaling back to four: rounding once
         * at the end keeps 10 / 3 * 1 at 3.3333 rather than letting an early
         * rounding walk into the displayed figure.
         */
        return (string) BigDecimal::of($price)
            ->dividedBy($content, 8, RoundingMode::HalfUp)
            ->multipliedBy($reference)
            ->toScale(4, RoundingMode::HalfUp)
        ;
    }
}

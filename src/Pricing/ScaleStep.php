<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * One line of a scale table: from this quantity on, this price.
 *
 * The price is what PriceCalculator answers at the threshold, not the amount
 * of the row that defines the threshold — two rows at the same threshold can
 * both be valid, and the table must say what the calculator will charge.
 */
final class ScaleStep
{
    /**
     * @param string $minQuantity decimal string, as stored
     * @param string $gross       the display gross of this step
     */
    public function __construct(
        public readonly string $minQuantity,
        public readonly ResolvedPrice $price,
        public readonly string $gross,
    ) {
    }
}

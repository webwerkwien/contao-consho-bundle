<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * The row a customer relationship would pay, and whether it is a promotion
 * (block H, B) — inside PriceCalculator only. Whether it is one cannot be read
 * off the row: it depends on the standing row it beat.
 *
 * @internal
 */
final class PriceOffer
{
    public function __construct(
        public readonly PriceRow $row,
        public readonly bool $promotion,
    ) {
    }
}

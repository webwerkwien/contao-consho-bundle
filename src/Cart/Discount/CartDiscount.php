<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart\Discount;

use Webwerkwien\ContaoConshoBundle\Checkout\ShippingShare;

/**
 * The cart rule that applies, and what it takes off (block H, H-4).
 *
 * `shares` are POSITIVE, one per tax class of the base, net and the amount as
 * the customer reads it (gross, or net for a net audience) — the payment
 * discount's shape, so the checkout adds them the same way, negated once
 * there. Empty for free shipping, which takes the shipping instead (W4).
 *
 * `lines` is the same net split once more over the lines of the base, keyed
 * by their position in the basket (H-5): the purchase freezes it on each
 * order line, so a withdrawal gives back what that line was given (R1).
 */
final class CartDiscount
{
    /**
     * @param list<ShippingShare> $shares
     * @param array<int, string>  $lines  position in CartView::$lines => net, positive
     */
    public function __construct(
        public readonly int $ruleId,
        public readonly string $title,
        public readonly string $code,
        public readonly array $shares,
        public readonly bool $freeShipping = false,
        public readonly array $lines = [],
    ) {
    }

    /**
     * The amount off as the customer reads it — the sum of the shares.
     */
    public function displayed(): string
    {
        $sum = \Brick\Math\BigDecimal::zero();

        foreach ($this->shares as $share) {
            $sum = $sum->plus($share->gross);
        }

        return (string) $sum;
    }
}

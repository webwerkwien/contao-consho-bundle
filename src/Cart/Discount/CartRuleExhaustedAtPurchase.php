<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart\Discount;

/**
 * The last use of a cart rule went to another order between the review page
 * and the purchase click (block H, H-4). The order is not placed — the
 * transaction rolls back — and the customer is told.
 */
final class CartRuleExhaustedAtPurchase extends \RuntimeException
{
    public static function for(int $ruleId): self
    {
        return new self(\sprintf('Cart rule %d has no use left.', $ruleId));
    }
}

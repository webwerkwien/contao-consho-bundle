<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * The chosen payment method was no longer among the shop's published ones when
 * the button was pressed.
 *
 * The checkout offered it a moment earlier, so this means the configuration
 * changed in between — unpublished, removed from the shop, or deleted. The
 * click is refused; an order whose payment method does not exist cannot be
 * paid, and guessing a different one would charge the customer for something
 * they did not choose.
 */
final class PaymentMethodGoneAtPurchase extends \RuntimeException
{
    public static function withId(int $methodId): self
    {
        return new self(\sprintf('Payment method %d is not available to this shop any more; no order was placed.', $methodId));
    }
}

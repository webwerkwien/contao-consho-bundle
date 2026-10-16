<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * An order with a subscription line and no customer account (Abo-3). The
 * checkout demands a login for a subscription (Abo-2, P12); this is the last
 * wall behind it, so the purchase fails rather than leaving a subscription
 * nobody can cancel.
 */
final class SubscriptionWithoutAccount extends \RuntimeException
{
    public static function forOrder(int $orderId): self
    {
        return new self(\sprintf('Order %d has a subscription line but no customer account; no order was placed.', $orderId));
    }
}

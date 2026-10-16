<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * The basket was gone by the time the lock was granted.
 *
 * Rare and not impossible: the cleanup cron removes open baskets after thirty
 * days, and a checkout left open across that boundary would find nothing here.
 * The click is refused rather than placing an order for a basket nobody can
 * point at any more.
 */
final class BasketGoneAtPurchase extends \RuntimeException
{
    public static function withId(int $cartId): self
    {
        return new self(\sprintf('Basket %d no longer exists; no order was placed.', $cartId));
    }
}

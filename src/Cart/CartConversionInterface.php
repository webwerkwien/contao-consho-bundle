<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

/**
 * The two things the purchase click does to a basket (C-7).
 *
 * Deliberately narrow: OrderPlacer must be able to lock a basket and retire
 * it, and nothing else. A dependency on the whole repository would let it add
 * items and change quantities inside the transaction that turns the basket
 * into an order.
 */
interface CartConversionInterface
{
    /**
     * Locks the basket and reports what it already is.
     *
     * @return array{state: string, order: int}|null null when the basket is gone
     */
    public function lockForPurchase(int $cartId): array|null;

    public function markConverted(int $cartId, int $orderId, int $now): void;
}

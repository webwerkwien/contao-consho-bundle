<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

/**
 * The head of a basket, as the storefront needs it.
 *
 * No prices and no lines: the lines are asked for separately, and every amount
 * is calculated on every request (7.2).
 */
final class Cart
{
    public function __construct(
        public readonly int $id,
        public readonly int $memberId,
        public readonly int $shopId,
        public readonly int $updatedAt,
    ) {
    }
}

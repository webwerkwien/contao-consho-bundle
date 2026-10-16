<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

/**
 * One stored line: which article, how much, since when — and since Abo-2 in
 * which plan (0 = one-time sale), and since Abo-7b which subscription it
 * renews (0 = none). Still no price (5.1).
 */
final class CartItemRow
{
    public function __construct(
        public readonly int $id,
        public readonly int $articleId,
        public readonly string $quantity,
        public readonly int $addedAt,
        public readonly int $subscriptionRule = 0,
        public readonly int $renews = 0,
    ) {
    }
}

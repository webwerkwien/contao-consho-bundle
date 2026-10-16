<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart\Discount;

/**
 * The discount that applies, if any, and what became of the typed code.
 * `minimumOrder` carries the amount still to reach for CodeStatus::MinimumOrder.
 */
final class CartDiscountResult
{
    public function __construct(
        public readonly CartDiscount|null $discount,
        public readonly CodeStatus $codeStatus = CodeStatus::None,
        public readonly string $minimumOrder = '',
    ) {
    }
}

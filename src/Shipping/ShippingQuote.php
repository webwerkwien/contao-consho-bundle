<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The net cost a type calculated. Scaled to four places like every stored net.
 */
final class ShippingQuote
{
    public readonly string $net;

    public function __construct(string $net)
    {
        $amount = BigDecimal::of($net);

        if ($amount->isNegative()) {
            throw new \InvalidArgumentException(\sprintf('A shipping amount cannot be negative, got "%s".', $net));
        }

        $this->net = (string) $amount->toScale(4, RoundingMode::HalfUp);
    }
}

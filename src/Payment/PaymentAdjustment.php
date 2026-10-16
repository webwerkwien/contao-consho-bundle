<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A net amount a payment method adds or takes off, taxed like shipping (C4).
 * Always positive: the origin says which way it goes. A handler with nothing
 * to add or take off returns null instead of a zero adjustment.
 */
final class PaymentAdjustment
{
    public readonly string $net;

    public function __construct(string $net, public readonly PaymentAdjustmentOrigin $origin)
    {
        $scaled = BigDecimal::of($net)->toScale(4, RoundingMode::HalfUp);

        if (!$scaled->isPositive()) {
            throw new \InvalidArgumentException(\sprintf('An adjustment is always positive, its origin gives the direction; got "%s".', $net));
        }

        $this->net = (string) $scaled;
    }
}

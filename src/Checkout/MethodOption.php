<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

/**
 * One shipping or payment method as the checkout offers it: what it is called,
 * whether it can serve this basket, and what it costs.
 *
 * Unavailable methods are kept in the list rather than dropped. The visitor
 * who chose one and then changed the country has to see that it is gone —
 * an option that simply disappears looks like a bug, and C2 forbids replacing
 * a choice silently.
 */
final class MethodOption
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly bool $available,
        /**
         * Net amount, four places. Zero for a method that costs nothing,
         * negative for a discount on a payment method (C5).
         */
        public readonly string $net = '0.0000',
        public readonly string $gross = '0.0000',
    ) {
    }
}

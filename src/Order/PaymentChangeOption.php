<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;

/**
 * One payment method as the order page offers it for a change.
 *
 * Unavailable methods stay in the list, as in the checkout (MethodOption): a
 * method the customer remembers from the purchase must not simply be gone.
 */
final class PaymentChangeOption
{
    /**
     * @param list<array<string, string|int>> $lines the payment lines this method would put on the order, ready to write
     */
    public function __construct(
        public readonly PaymentMethod $method,
        public readonly bool $available,
        public readonly bool $current,
        /** Signed gross of those lines in the currency's places; '0' for none. */
        public readonly string $gross = '0',
        public readonly array $lines = [],
    ) {
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * One subscription an order sets up, as a payment handler needs it at the
 * first purchase (Abo-4b, P93): many providers want the terms of the
 * subscription described before they keep the method.
 *
 * The amount is the gross of the purchase line — the price of one period,
 * frozen with the contract (A9). A later change of the tax rate moves the
 * follow-up orders, not this figure.
 *
 * `nextBillingAt` is where a provider's own schedule starts: the first
 * purchase is paid as a product, follow-ups are billed from then on.
 */
final class SubscriptionLineView
{
    public function __construct(
        public readonly string $subscriptionNumber,
        public readonly string $name,
        public readonly string $amount,
        /** Months between two payments; 0: one payment per term. */
        public readonly int $intervalMonths,
        /** The minimum term in months. */
        public readonly int $termMonths,
        /** When the first follow-up order is billed (Unix time); 0: not known. */
        public readonly int $nextBillingAt = 0,
    ) {
    }
}

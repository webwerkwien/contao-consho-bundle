<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * One subscription an order sets up, as a payment handler needs it at the
 * first purchase (Abo-4b, P93): Klarna wants a `subscription` object per
 * line, PayPal the payment plan, before the provider keeps the method.
 *
 * The amount is the gross of the purchase line — the price of one period,
 * frozen with the contract (A9). A later change of the tax rate moves the
 * follow-up orders, not this figure.
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
    ) {
    }
}

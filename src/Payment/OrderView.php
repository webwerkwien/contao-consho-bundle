<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * The part of a committed order a payment handler needs to start a payment.
 * A view, not the model: a handler must not be able to change the order.
 *
 * `recurring` (Abo-4b, P93): the order sets up subscriptions, and a handler
 * that can charge follow-up orders (RecurringPaymentHandlerInterface) should
 * ask its provider to keep the payment method — with the subscription lines,
 * which Klarna and PayPal want at the first purchase already.
 */
final class OrderView
{
    public readonly bool $recurring;

    /**
     * What is left to collect (block Wertgutscheine, WG-5): the grand total
     * less what vouchers and earlier payments paid. The amount to charge —
     * a handler that charged `grandTotal` would take the vouchers' part a
     * second time. The attempt's amount says the same.
     */
    public readonly string $outstanding;

    /**
     * @param list<SubscriptionLineView> $subscriptionLines
     * @param string|null                $outstanding       null: the grand total
     */
    public function __construct(
        public readonly int $id,
        public readonly string $orderNumber,
        public readonly int $shopId,
        public readonly string $currency,
        public readonly string $grandTotal,
        public readonly string $email,
        public readonly string $language,
        public readonly array $subscriptionLines = [],
        string|null $outstanding = null,
    ) {
        $this->recurring = [] !== $subscriptionLines;
        $this->outstanding = $outstanding ?? $grandTotal;
    }
}

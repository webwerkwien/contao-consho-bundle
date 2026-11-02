<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Refund;

/**
 * One refund as a payment handler needs it (block Zahlung Mollie, 4.1). A
 * view, not the row: a handler must not be able to change the books.
 *
 * `paymentReference` is the provider's reference of the payment paid back —
 * what its notification booked (NotificationResult::$reference).
 * `payoutReference` is the provider's reference of this refund, empty before
 * refund() and set when refundState() asks.
 */
final class PaymentRefund
{
    public function __construct(
        /** The negative payment row. */
        public readonly int $id,
        public readonly string $orderNumber,
        public readonly string $currency,
        /** Positive: what goes back. */
        public readonly string $amount,
        public readonly string $paymentReference,
        public readonly string $payoutReference,
        public readonly string $language,
    ) {
    }
}

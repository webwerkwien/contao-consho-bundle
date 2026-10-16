<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * What a change of payment method produced — the documents the mail sends.
 */
final class PaymentChanged
{
    public function __construct(
        public readonly int $orderId,
        /** The cancellation of the invoice that stood, null if there was none. */
        public readonly int|null $cancellationId,
        /** The invoice of the new method, null where it issues later (OnPayment, OnShipment). */
        public readonly int|null $invoiceId,
    ) {
    }
}

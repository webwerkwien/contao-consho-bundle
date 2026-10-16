<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * One try to pay an order (research, conclusion 3), kept apart from the money
 * received in tl_consho_payment: an attempt is no money.
 *
 * The URLs carry the attempt's token (spec 6.3) and are built by the core —
 * a handler hands them to its provider as they are. Stored and written by the
 * core only, under lock (spec 7.4); a handler gets a read-only copy.
 */
final class PaymentAttempt
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly int $methodId,
        public readonly string $methodType,
        public readonly AttemptState $state,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $providerReference,
        public readonly string $returnUrl,
        public readonly string $notifyUrl,
        /**
         * What the browser return said, as handleReturn() verified it (C-11) —
         * for the order page only, never for money. null: no return yet.
         */
        public readonly AttemptState|null $returned = null,
        /** When the attempt was opened; 0 where unknown. Decides when an open one lapses (C-11). */
        public readonly int $createdAt = 0,
        /**
         * Opened by the subscription cron to charge a follow-up order without
         * the customer (Abo-4b, P94) — not by a buyer in the browser.
         */
        public readonly bool $charge = false,
    ) {
    }
}

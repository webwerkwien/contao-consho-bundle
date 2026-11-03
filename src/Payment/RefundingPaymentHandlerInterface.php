<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Webwerkwien\ContaoConshoBundle\Payment\Refund\PaymentRefund;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundResult;

/**
 * A payment handler that can pay money back through its provider (block
 * Zahlung Mollie, R1–R6). Optional, like RecurringPaymentHandlerInterface: a
 * handler that does not implement it cannot, and a refund is booked and paid
 * out by hand as before.
 *
 * ── Booked first, paid out after ─────────────────────────────────────────
 *
 * The core books the refund — the negative payment row, state `requested` —
 * and commits before it calls refund(): a payout at a provider cannot be
 * rolled back, a row can be marked failed. So refund() is called outside any
 * transaction; the row stays booked whatever happens.
 *
 *  - done: the provider has paid the money back
 *  - pending: the provider accepted it and pays later; the hourly cron asks
 *    refundState() with the reference until it is done or failed
 *  - failed: the provider REFUSED; the operator may send it again
 *  - throws: the outcome is unknown — no connection, a timeout after the
 *    request went out, a server error. The row stays `sending` and is never
 *    sent again by itself; after an hour the start page names it, and the
 *    operator checks the provider's account before settling it by hand.
 *
 * ⚠️ Answer `failed` only when the provider said no. A handler that turns a
 * timeout into `failed` invites a second payout. Where the provider supports
 * it, send an idempotency key derived from $refund->id, so a request sent
 * twice pays once.
 *
 * Notifications the provider sends about a refund reach the attempt's notify
 * URL like any other. The state of one of Consho's own refunds comes in only
 * through the cron; a handler may report the payment's refunds with the
 * notification all the same (NotificationResult::withEvents()) — the core
 * skips those it knows by their ID and records the others as made outside
 * Consho (spec 9).
 *
 * Never locked by a licence: paying money back to a customer must not depend
 * on one.
 */
interface RefundingPaymentHandlerInterface extends PaymentHandlerInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function refund(PaymentRefund $refund, array $config): RefundResult;

    /**
     * Asked for a refund the provider answered `pending`, with the reference
     * it gave then ($refund->payoutReference).
     *
     * @param array<string, mixed> $config
     */
    public function refundState(PaymentRefund $refund, array $config): RefundResult;
}

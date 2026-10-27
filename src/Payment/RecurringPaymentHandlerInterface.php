<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * A payment handler that can charge a follow-up order without the customer
 * (Abo-4b, P92). Optional: a handler that does not implement it cannot, and
 * every follow-up order of its subscriptions goes "on account" as before.
 *
 * ── The mandate ──────────────────────────────────────────────────────────
 *
 * Obtained at the first purchase: OrderView::$recurring tells start() that
 * the order contains subscription lines (P93), and the handler asks its
 * provider to keep the payment method — Stripe `setup_future_usage`, a PayPal
 * vault, Klarna `buy_and_tokenize` (Block Abo).
 * The notification that confirms the payment hands the mandate back through
 * NotificationResult::paid(…, mandate); the core keeps it on the
 * subscription together with the method.
 *
 * To the core the mandate is an opaque string of at most 255 characters,
 * built by the handler as it needs it — Stripe, for one, needs two IDs. It is
 * shown nowhere and exported nowhere (P98).
 *
 * ── The charge ───────────────────────────────────────────────────────────
 *
 * Called by the subscription cron right after a follow-up order is created
 * (P94), outside any transaction: a provider call cannot be rolled back. The
 * attempt is opened by the core, with a notify URL, as for start().
 *
 *  - paid: the money is in; the core records it like a notification
 *  - pending: the provider answers later through the notify URL (SEPA takes
 *    days); after 7 days without an answer the order goes "on account" (P96)
 *  - failed: the order goes "on account" at once (P95); the mandate stays,
 *    the next period is charged again
 *
 * A charge that throws counts as failed. Consent to charges without the
 * customer is the module's and the operator's matter, not the core's —
 * Consho sets no legal texts.
 */
interface RecurringPaymentHandlerInterface extends PaymentHandlerInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function charge(PaymentAttempt $attempt, OrderView $order, string $mandate, array $config): ChargeResult;
}

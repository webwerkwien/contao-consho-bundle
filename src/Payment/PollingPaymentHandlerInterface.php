<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * A payment handler that can ask its provider what became of an attempt —
 * the safety net for a notification that never came (spec Zahlung PayPal,
 * PP2 d). Optional, beside PaymentHandlerInterface.
 *
 * The cleanup cron asks before it lets an open attempt lapse. A payment found
 * there is booked exactly like a notified one and marked, so that the finding
 * paymentWithoutWebhook can tell the operator the webhook does not arrive.
 */
interface PollingPaymentHandlerInterface extends PaymentHandlerInterface
{
    /**
     * Same answers and the same duty to verify as handleNotification(): the
     * answer comes from the provider's API, never from stored data.
     *
     * @param array<string, mixed> $config
     */
    public function poll(PaymentAttempt $attempt, array $config): NotificationResult;
}

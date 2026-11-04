<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Symfony\Component\HttpFoundation\Request;

/**
 * A payment handler whose provider sends its notifications to one fixed
 * address per account or app instead of one per payment — PayPal, Stripe
 * (spec Zahlung PayPal, PP1). Optional, beside PaymentHandlerInterface.
 *
 * The core routes POST /_consho/payment/webhook/{method} here. The handler
 * proves the request comes from the provider and names the attempt by the
 * reference the attempt carries; from there on everything runs as for the
 * per-attempt address: handleNotification() with the same request, lock,
 * booking, provider events. A handler may note on the request that it has
 * verified it, instead of asking the provider a second time in
 * handleNotification().
 */
interface WebhookPaymentHandlerInterface extends PaymentHandlerInterface
{
    /**
     * @throws NotificationRejected when the request cannot be shown to come from the provider
     */
    public function webhookReference(Request $request, PaymentMethod $method): WebhookReference;
}

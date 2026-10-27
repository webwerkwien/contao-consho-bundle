<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;

/**
 * A payment handler (spec 6.2, C1): one interface for every kind, the redirect
 * optional — Shopware merged five interfaces into one in 6.7 for exactly this.
 *
 * Fixed key, settings as a ready array, no session, no tables (research,
 * conclusions 1 and 2; C3). The handler never writes tl_consho_payment or the
 * attempt; the core does, under lock and idempotently (spec 7.4).
 *
 * ⚠️ Reserved key in $config: `instructions` — the method's payment notice in
 * the language of the order, put there by the core. A module must not define
 * a virtual field of that name.
 *
 * The literal tag is repeated in PaymentHandlers; a test holds them together.
 */
#[AutoconfigureTag('consho.payment_handler')]
interface PaymentHandlerInterface
{
    public static function getType(): string;

    /** @param array<string, mixed> $config */
    public function isAvailable(PaymentContext $context, array $config): bool;

    /**
     * Optional amount with origin (C5); taxed like shipping (C4).
     *
     * @param array<string, mixed> $config
     */
    public function adjustment(PaymentContext $context, array $config): PaymentAdjustment|null;

    /**
     * When the invoice comes into being (C6).
     *
     * @param array<string, mixed> $config
     */
    public function documentTrigger(array $config): DocumentTrigger;

    /**
     * Called after the order is committed.
     *
     * @param array<string, mixed> $config
     */
    public function start(PaymentAttempt $attempt, OrderView $order, array $config): PaymentStart;

    /**
     * Browser return. Displays a state, never records money.
     *
     * ⚠️ `Succeeded` only when the handler has VERIFIED it — a signature on
     * the return, or a question to the provider. The return address is typed
     * into a browser and can be typed by anyone; the order page says "the
     * provider reports the payment as completed" on this answer alone,
     * and the page says only what it can prove. Anything
     * unverified is `Pending`. `Failed` and `Cancelled` stop no money and
     * need no proof.
     *
     * @param array<string, mixed> $config
     */
    public function handleReturn(Request $request, PaymentAttempt $attempt, array $config): AttemptState;

    /**
     * Provider notification. Verifies authenticity; the core writes the payment row.
     *
     * @param array<string, mixed> $config
     *
     * @throws NotificationRejected when the message cannot be shown to come from the provider
     */
    public function handleNotification(Request $request, PaymentAttempt $attempt, array $config): NotificationResult;

    /**
     * Called when the customer switches away from this attempt.
     *
     * @param array<string, mixed> $config
     */
    public function cancel(PaymentAttempt $attempt, array $config): void;
}

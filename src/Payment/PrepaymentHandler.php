<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;

/**
 * Vorkasse (spec 6.2): always available, no amount, invoice on order (C6).
 *
 * start() is done at once: the customer gets the payment notice (the operator's
 * rich text) and the bank connection (plain details). There is no return and
 * no notification — the operator records the money received in the order mask
 * (block A).
 */
final class PrepaymentHandler implements PaymentHandlerInterface
{
    private const DETAILS = ['accountHolder', 'iban', 'bic', 'bankName'];

    public static function getType(): string
    {
        return 'prepayment';
    }

    public function isAvailable(PaymentContext $context, array $config): bool
    {
        return true;
    }

    public function adjustment(PaymentContext $context, array $config): PaymentAdjustment|null
    {
        return null;
    }

    public function documentTrigger(array $config): DocumentTrigger
    {
        return DocumentTrigger::OnOrder;
    }

    public function start(PaymentAttempt $attempt, OrderView $order, array $config): PaymentStart
    {
        $notice = \is_scalar($config['instructions'] ?? null) ? (string) $config['instructions'] : '';
        $details = [];

        foreach (self::DETAILS as $key) {
            $details[$key] = \is_scalar($config[$key] ?? null) ? (string) $config[$key] : '';
        }

        return PaymentStart::completed($notice, $details);
    }

    public function handleReturn(Request $request, PaymentAttempt $attempt, array $config): AttemptState
    {
        return AttemptState::Pending;
    }

    public function handleNotification(Request $request, PaymentAttempt $attempt, array $config): NotificationResult
    {
        return NotificationResult::ignored();
    }

    public function cancel(PaymentAttempt $attempt, array $config): void
    {
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationResult;
use Webwerkwien\ContaoConshoBundle\Payment\OrderView;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustment;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentContext;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStart;
use Webwerkwien\ContaoConshoBundle\Payment\PollingPaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\WebhookPaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\WebhookReference;

/**
 * A provider with one fixed address that can also be asked.
 */
final class WebhookTestHandler implements WebhookPaymentHandlerInterface, PollingPaymentHandlerInterface
{
    /** @var \Closure(): WebhookReference */
    public \Closure $reference;

    /** @var \Closure(): NotificationResult */
    public \Closure $notification;

    /** @var \Closure(): NotificationResult */
    public \Closure $polled;

    /** @var list<int> attempts cancel() was called for */
    public array $cancelled = [];

    public function __construct()
    {
        $this->reference = static fn () => WebhookReference::ignored('not set');
        $this->notification = static fn () => NotificationResult::ignored();
        $this->polled = static fn () => NotificationResult::ignored();
    }

    public static function getType(): string
    {
        return 'hook';
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
        return DocumentTrigger::OnPayment;
    }

    public function start(PaymentAttempt $attempt, OrderView $order, array $config): PaymentStart
    {
        throw new \LogicException('not used');
    }

    public function handleReturn(Request $request, PaymentAttempt $attempt, array $config): AttemptState
    {
        return AttemptState::Pending;
    }

    public function handleNotification(Request $request, PaymentAttempt $attempt, array $config): NotificationResult
    {
        return ($this->notification)();
    }

    public function cancel(PaymentAttempt $attempt, array $config): void
    {
        $this->cancelled[] = $attempt->id;
    }

    public function webhookReference(Request $request, PaymentMethod $method): WebhookReference
    {
        return ($this->reference)();
    }

    public function poll(PaymentAttempt $attempt, array $config): NotificationResult
    {
        return ($this->polled)();
    }
}

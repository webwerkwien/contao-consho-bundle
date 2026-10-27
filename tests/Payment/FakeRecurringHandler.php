<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\ChargeResult;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationResult;
use Webwerkwien\ContaoConshoBundle\Payment\OrderView;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustment;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentContext;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStart;
use Webwerkwien\ContaoConshoBundle\Payment\RecurringPaymentHandlerInterface;

/**
 * A payment module that can do recurring payments (Abo-4b) — for the tests
 * that do not mean the test provider with its signature. Remembers what it
 * was asked and answers what the test tells it to.
 */
final class FakeRecurringHandler implements RecurringPaymentHandlerInterface
{
    public const TYPE = 'card';

    public NotificationResult $notification;

    public ChargeResult|\Throwable $charge;

    /** @var list<array{PaymentAttempt, OrderView, string}> */
    public array $charges = [];

    /** @var list<OrderView> */
    public array $starts = [];

    /** @var list<int> */
    public array $cancelled = [];

    public function __construct(public DocumentTrigger $trigger = DocumentTrigger::OnPayment)
    {
        $this->notification = NotificationResult::ignored();
        $this->charge = ChargeResult::failed('nicht vorgegeben');
    }

    public static function getType(): string
    {
        return self::TYPE;
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
        return $this->trigger;
    }

    public function start(PaymentAttempt $attempt, OrderView $order, array $config): PaymentStart
    {
        $this->starts[] = $order;

        return PaymentStart::redirect('https://anbieter.test/kasse');
    }

    public function handleReturn(Request $request, PaymentAttempt $attempt, array $config): AttemptState
    {
        return AttemptState::Pending;
    }

    public function handleNotification(Request $request, PaymentAttempt $attempt, array $config): NotificationResult
    {
        return $this->notification;
    }

    public function cancel(PaymentAttempt $attempt, array $config): void
    {
        $this->cancelled[] = $attempt->id;
    }

    public function charge(PaymentAttempt $attempt, OrderView $order, string $mandate, array $config): ChargeResult
    {
        $this->charges[] = [$attempt, $order, $mandate];

        if ($this->charge instanceof \Throwable) {
            throw $this->charge;
        }

        return $this->charge;
    }
}

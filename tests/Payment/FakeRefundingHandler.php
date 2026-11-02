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
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStart;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\PaymentRefund;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundResult;
use Webwerkwien\ContaoConshoBundle\Payment\RefundingPaymentHandlerInterface;

/**
 * A payment module that can pay back (block Zahlung Mollie) — remembers what
 * it was asked and answers what the test tells it to.
 */
final class FakeRefundingHandler implements RefundingPaymentHandlerInterface
{
    public const TYPE = 'card';

    public RefundResult|\Throwable $refund;

    public RefundResult $state;

    /** @var list<PaymentRefund> */
    public array $refunds = [];

    /** @var list<PaymentRefund> */
    public array $asked = [];

    public function __construct()
    {
        $this->refund = RefundResult::failed('nicht vorgegeben');
        $this->state = RefundResult::failed('nicht vorgegeben');
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
        return DocumentTrigger::OnPayment;
    }

    public function start(PaymentAttempt $attempt, OrderView $order, array $config): PaymentStart
    {
        return PaymentStart::redirect('https://anbieter.test/kasse', 'cs_'.$attempt->id);
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

    /**
     * Runs while the provider is being asked — what another process does
     * meanwhile.
     *
     * @var (\Closure(): void)|null
     */
    public \Closure|null $meanwhile = null;

    /** Whether a transaction was open when refund() was called. */
    public bool $inTransaction = false;

    public \Doctrine\DBAL\Connection|null $connection = null;

    public function refund(PaymentRefund $refund, array $config): RefundResult
    {
        $this->refunds[] = $refund;
        $this->inTransaction = $this->inTransaction || (bool) $this->connection?->isTransactionActive();

        if (null !== $this->meanwhile) {
            ($this->meanwhile)();
        }

        if ($this->refund instanceof \Throwable) {
            throw $this->refund;
        }

        return $this->refund;
    }

    public function refundState(PaymentRefund $refund, array $config): RefundResult
    {
        $this->asked[] = $refund;

        return $this->state;
    }
}

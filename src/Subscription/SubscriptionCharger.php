<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\ChargeOutcome;
use Webwerkwien\ContaoConshoBundle\Payment\ChargeResult;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\OrderView;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttemptRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\RecurringPaymentHandlerInterface;

/**
 * Charges a follow-up order with the subscription's mandate (Abo-4b, P94).
 *
 * Called by the subscription cron right after SubscriptionBiller has
 * committed the order, and outside any transaction — a provider call cannot
 * be rolled back.
 *
 *  1. under the order's lock: nothing to do if the order has any attempt —
 *     charged before, or paid by the customer in the browser; otherwise an
 *     attempt marked `charge`, with a token for the notify URL as in
 *     PaymentStarter. Two runs at once open one attempt, not two charges.
 *  2. the module charges, after the commit
 *  3. paid: the money recorded like a notification, under the attempt's lock
 *     and its unique key; then PaymentRecorded — invoice by the module's
 *     trigger and "Rechnung zum Abo" in its charged version (P97)
 *     pending: the attempt waits for the notification (P96 after 7 days),
 *     and the customer is told a charge has started (E7)
 *     failed or thrown: the order goes "on account" (P95)
 *
 * Never throws: the order stands, and what fails here is logged — the cron
 * puts an order that never got its charge "on account" after 7 days (P96).
 */
class SubscriptionCharger
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
        private readonly PaymentMethodLookupInterface $methods,
        private readonly PaymentHandlers $handlers,
        private readonly PaymentAttemptRepositoryInterface $attempts,
        private readonly UrlGeneratorInterface $router,
        private readonly SubscriptionMandates $mandates,
        private readonly PaymentRecorded $recorded,
        private readonly SubscriptionChargeFallback $fallback,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Follow-up orders billed with a mandate that have no attempt at all —
     * a run that died between the billing's commit and the charge. Within
     * the 7 days of P96; after them SubscriptionChargeFallback::lapse() puts
     * them "on account".
     *
     * @return list<int> the orders charged
     */
    public function chargeOutstanding(int $now): array
    {
        $orders = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT o.id FROM tl_consho_order o
               JOIN tl_consho_payment_method m ON m.id = o.paymentMethod
              WHERE o.subscription > 0
                AND o.paymentSettledAt IS NULL
                AND (o.paymentChangedAt IS NULL OR o.paymentChangedAt = 0)
                AND o.cancelledAt IS NULL
                AND o.createdAt > ?
                AND m.type <> ?
                AND NOT EXISTS (SELECT 1 FROM tl_consho_payment_attempt a WHERE a.pid = o.id)
                AND NOT EXISTS (SELECT 1 FROM tl_consho_payment p WHERE p.pid = o.id)
              ORDER BY o.id',
            [$now - SubscriptionChargeFallback::PENDING_DAYS * 86400, InvoicePaymentHandler::getType()],
        ));

        $charged = [];

        foreach ($orders as $orderId) {
            if (null !== $this->charge($orderId, $now)) {
                $charged[] = $orderId;
            }
        }

        return $charged;
    }

    /**
     * @return ChargeOutcome|null null when nothing was charged: no such
     *                            order, or one with an attempt already
     */
    public function charge(int $orderId, int $now): ChargeOutcome|null
    {
        try {
            return $this->chargeOrder($orderId, $now);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Follow-up order %d could not be charged: %s', $orderId, $e->getMessage()), ['exception' => $e]);

            return null;
        }
    }

    private function chargeOrder(int $orderId, int $now): ChargeOutcome|null
    {
        $token = bin2hex(random_bytes(32));

        $this->connection->beginTransaction();

        try {
            $order = $this->orders->lockOrder($orderId);

            // Nothing to charge for an order already being paid another way:
            // a payment recorded by the operator (Abo-4b — the
            // grand total would overpay it), a cancelled order, any attempt.
            if (null === $order
                || '' !== (string) ($order['paymentSettledAt'] ?? '')
                || (int) ($order['cancelledAt'] ?? 0) > 0
                || null !== $this->attempts->latestFor($orderId)
                || [] !== $this->orders->paymentAmounts($orderId)
            ) {
                $this->connection->commit();

                return null;
            }

            $subscription = $this->connection->fetchAssociative(
                'SELECT subscriptionNumber, mandate, mandateMethod FROM tl_consho_subscription WHERE id = ?',
                [(int) ($order['subscription'] ?? 0)],
            );
            $method = $this->methods->byId((int) ($order['paymentMethod'] ?? 0));
            $handler = null !== $method && $this->handlers->has($method->type) ? $this->handlers->get($method->type) : null;
            $mandate = false === $subscription ? '' : trim((string) $subscription['mandate']);

            // The mandate, its method and the order's method must still agree:
            // removed by the anonymisation, or the module gone since billing.
            if (null === $method || !$handler instanceof RecurringPaymentHandlerInterface || '' === $mandate || (int) $subscription['mandateMethod'] !== $method->id) {
                $this->connection->commit();
                $this->logger->warning(\sprintf('Follow-up order %s has no mandate its payment method can charge.', $order['orderNumber'] ?? $orderId));
                $this->fallback->toInvoice($orderId, $now);

                return ChargeOutcome::Failed;
            }

            $amount = (string) BigDecimal::of('' === (string) ($order['grandTotal'] ?? '') ? '0' : $order['grandTotal'])->toScale(4, RoundingMode::HalfUp);
            $currency = (string) ($order['currency'] ?? '');
            $attemptId = $this->attempts->open($orderId, $method, $amount, $currency, hash('sha256', $token), $now, true);

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        $attempt = new PaymentAttempt(
            $attemptId,
            $orderId,
            $method->id,
            $method->type,
            AttemptState::Open,
            $amount,
            $currency,
            '',
            $this->router->generate('consho_payment_return', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->router->generate('consho_payment_notify', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            null,
            $now,
            true,
        );

        $view = new OrderView(
            $orderId,
            (string) ($order['orderNumber'] ?? ''),
            (int) $order['shop'],
            $currency,
            (string) ($order['grandTotal'] ?? ''),
            (string) ($order['email'] ?? ''),
            (string) ($order['language'] ?? ''),
            $this->mandates->linesOf($orderId),
        );

        try {
            $result = $handler->charge($attempt, $view, $mandate, $method->config);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Charging follow-up order %s failed: %s', $view->orderNumber, $e->getMessage()), ['exception' => $e]);
            $result = ChargeResult::failed($e->getMessage());
        }

        match ($result->outcome) {
            ChargeOutcome::Paid => $this->paid($attempt, $result, $method->title, $now),
            ChargeOutcome::Pending => $this->pending($attemptId, $orderId, $result, $now),
            ChargeOutcome::Failed => $this->failed($attempt, $result, $view->orderNumber, $now),
        };

        return $result->outcome;
    }

    /**
     * Like NotificationProcessor: the attempt locked, the payment row, the
     * attempt succeeded — a notification the provider sends for the same
     * charge finds it succeeded and stops, and the unique key on the payment
     * holds behind the lock.
     */
    private function paid(PaymentAttempt $attempt, ChargeResult $result, string $methodTitle, int $now): void
    {
        $this->connection->beginTransaction();

        try {
            $locked = $this->attempts->lock($attempt->id);

            if (null === $locked || AttemptState::Succeeded === $locked->state) {
                $this->connection->commit();

                return;
            }

            // See NotificationProcessor: only a live attempt is a charge.
            $charged = AttemptState::Open === $locked->state || AttemptState::Pending === $locked->state;
            $this->attempts->recordPayment($locked, $result->amount, $result->reference, $methodTitle, $now);
            $this->attempts->setState($locked->id, AttemptState::Succeeded, $now, $result->reference);
            $this->connection->commit();
        } catch (UniqueConstraintViolationException) {
            $this->connection->rollBack();

            return;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        if (!BigDecimal::of($result->amount)->isEqualTo($attempt->amount)) {
            $this->logger->warning(\sprintf('Payment for attempt %d: %s received, %s expected.', $attempt->id, $result->amount, $attempt->amount));
        }

        // After the commit, never undoing it: the money is recorded.
        try {
            $this->recorded->handle($attempt->orderId, true, $now, $charged);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The consequences of the payment of attempt %d failed: %s', $attempt->id, $e->getMessage()), ['exception' => $e]);
        }
    }

    /**
     * The attempt waits for the notification (P96), and the customer learns
     * that a charge has started (E7).
     */
    private function pending(int $attemptId, int $orderId, ChargeResult $result, int $now): void
    {
        $this->attempts->setState($attemptId, AttemptState::Pending, $now, $result->reference);
        $this->fallback->announcePending($orderId);
    }

    private function failed(PaymentAttempt $attempt, ChargeResult $result, string $orderNumber, int $now): void
    {
        $this->attempts->setState($attempt->id, AttemptState::Failed, $now);
        $this->logger->warning(\sprintf('Charging follow-up order %s failed%s.', $orderNumber, '' === $result->reason ? '' : ': '.rtrim($result->reason, '.')));
        $this->fallback->toInvoice($attempt->orderId, $now);
    }
}

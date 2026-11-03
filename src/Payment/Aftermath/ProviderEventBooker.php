<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Aftermath;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\ChargebackRow;
use Webwerkwien\ContaoConshoBundle\Payment\ProviderEvent;
use Webwerkwien\ContaoConshoBundle\Payment\ProviderEventKind;

/**
 * Books what a provider reports after a payment succeeded (spec 9.2, N1–N4).
 *
 * Runs inside the notification's transaction and takes the order lock, the
 * one RefundPayout takes too: a refund being booked and a notification about
 * it must not pass each other. Every event is keyed by the provider's ID, so
 * a notification sent twice — providers resend until they get a 2xx — books
 * nothing twice.
 *
 *  - chargeback   a negative row (ChargebackRow::METHOD) against the payment,
 *                 plus the event with the bank's reason for the finding
 *  - reversed     a positive row (ChargebackRow::REVERSAL), once (N4)
 *  - refund       one of Consho's own (its ID is on a refund row) is skipped;
 *                 a foreign one becomes an open event once money is on its
 *                 way — never a payment row: whether it is new money out or a
 *                 refund already booked by hand is the operator's call (N3)
 *
 * Nothing else follows (N2). A chargeback may be an empty account, a dispute
 * or card fraud, and each wants a different answer — so no status change, no
 * mail, and `paymentSettledAt` stays: it dates the sale for the OSS return
 * and keeps the subscription charger off a settled order.
 */
class ProviderEventBooker
{
    private const MONEY_MOVING = ['processing', 'refunded'];

    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Call inside a transaction.
     *
     * @param list<ProviderEvent> $events
     *
     * @return int how many events changed something
     */
    public function book(int $orderId, int $attemptId, array $events, int $now): int
    {
        if ([] === $events) {
            return 0;
        }

        $order = $this->orders->lockOrder($orderId);

        if (null === $order) {
            return 0;
        }

        $payment = $this->connection->fetchAssociative(
            'SELECT id, amount FROM tl_consho_payment WHERE pid = ? AND attempt = ? AND amount > 0 ORDER BY id LIMIT 1',
            [$orderId, $attemptId],
        );

        if (false === $payment) {
            $this->logger->warning(\sprintf('Order %s: the provider reported %d event(s) for attempt %d, which has no payment.', $order['orderNumber'] ?? $orderId, \count($events), $attemptId));

            return 0;
        }

        $changed = 0;

        foreach ($events as $event) {
            if (strtoupper((string) ($order['currency'] ?? '')) !== $event->currency) {
                $this->logger->warning(\sprintf('Order %s: provider event %s in %s skipped, the order is in %s.', $order['orderNumber'] ?? $orderId, $event->reference, $event->currency, $order['currency'] ?? ''));

                continue;
            }

            $did = match ($event->kind) {
                ProviderEventKind::Chargeback => $this->chargeback($orderId, (int) $payment['id'], $event, $now),
                ProviderEventKind::Refund => $this->refund($orderId, (int) $payment['id'], $event, $now),
            };

            if ($did) {
                ++$changed;
                $this->logger->info(\sprintf('Order %s: %s %s over %s %s recorded.', $order['orderNumber'] ?? $orderId, $event->kind->value, $event->reference, $event->amount, $event->currency));
            }
        }

        return $changed;
    }

    private function chargeback(int $orderId, int $paymentId, ProviderEvent $event, int $now): bool
    {
        $changed = $this->remember($orderId, $paymentId, $event, $now);
        $changed = $this->bookRow($orderId, $paymentId, ChargebackRow::METHOD, BigDecimal::of($event->amount)->negated(), $event, $event->occurredAt ?: $now, $now) || $changed;

        if ($event->reversedAt > 0) {
            $this->connection->update('tl_consho_provider_event', ['reversedAt' => $event->reversedAt, 'tstamp' => $now], ['reference' => $event->reference]);
            $changed = $this->bookRow($orderId, $paymentId, ChargebackRow::REVERSAL, BigDecimal::of($event->amount), $event, $event->reversedAt, $now) || $changed;
        }

        return $changed;
    }

    private function refund(int $orderId, int $paymentId, ProviderEvent $event, int $now): bool
    {
        // Money not on its way yet: a pending refund can still be cancelled,
        // and it may be one of Consho's own whose ID is not stored yet.
        if (!\in_array($event->refundState, self::MONEY_MOVING, true)) {
            return false;
        }

        $own = $this->connection->fetchOne(
            "SELECT id FROM tl_consho_payment WHERE pid = ? AND method = 'refund' AND payoutReference = ?",
            [$orderId, $event->reference],
        );

        if (false !== $own) {
            return false;
        }

        return $this->remember($orderId, $paymentId, $event, $now);
    }

    /**
     * The event, once per provider ID.
     */
    private function remember(int $orderId, int $paymentId, ProviderEvent $event, int $now): bool
    {
        if (false !== $this->connection->fetchOne('SELECT id FROM tl_consho_provider_event WHERE reference = ?', [$event->reference])) {
            return false;
        }

        $this->connection->insert('tl_consho_provider_event', [
            'tstamp' => $now,
            'pid' => $orderId,
            'payment' => $paymentId,
            'kind' => $event->kind->value,
            'reference' => $event->reference,
            'amount' => $event->amount,
            'currency' => $event->currency,
            'reason' => $event->reason,
            'occurredAt' => $event->occurredAt,
            'reversedAt' => $event->reversedAt,
            'state' => 'open',
        ]);

        return true;
    }

    /**
     * A chargeback row or its reversal, once per chargeback.
     */
    private function bookRow(int $orderId, int $paymentId, string $method, BigDecimal $amount, ProviderEvent $event, int $receivedAt, int $now): bool
    {
        $exists = $this->connection->fetchOne(
            'SELECT id FROM tl_consho_payment WHERE pid = ? AND method = ? AND payoutReference = ?',
            [$orderId, $method, $event->reference],
        );

        if (false !== $exists) {
            return false;
        }

        // Locked from the start, like a refund: the provider's record, not
        // the operator's to edit. `attempt` stays empty — it is unique, and
        // the attempt's row is the payment this one answers.
        $this->connection->insert('tl_consho_payment', [
            'pid' => $orderId,
            'tstamp' => $now,
            'receivedAt' => $receivedAt,
            'amount' => (string) $amount->toScale(4, RoundingMode::HalfUp),
            'method' => $method,
            'reference' => mb_substr($event->reason, 0, 255),
            'lockedAt' => $now,
            'refundOf' => $paymentId,
            'payoutReference' => $event->reference,
        ]);

        return true;
    }
}

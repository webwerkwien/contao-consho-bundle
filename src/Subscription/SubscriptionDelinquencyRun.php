<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Digital\PurchaseDelinquency;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;

/**
 * Asks SubscriptionDelinquency and sends what follows (Abo-5, P35, P37): from
 * the cron every hour, from `consho:subscription:run`, and after a payment
 * (PaymentRecorded) for the subscriptions of that order.
 *
 * The cron asks every resting subscription — it may have come back by a
 * cancellation, which no payment announces — and every running one with an
 * order past its term that still owes something — the same narrowing as
 * OverdueInvoiceIssueSource, not `paymentSettledAt`: that is set once, and a
 * refund after it opens the order again (review Abo-5, L2). The grace period
 * and the balance are SubscriptionDelinquency's to judge; this only narrows.
 *
 * Mails after the change has committed; one subscription failing does not
 * stop the others (logged, the next run asks again).
 */
class SubscriptionDelinquencyRun
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionDelinquency $delinquency,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly OrderPageLink $links,
        private readonly OrderEventMail $mail,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // Nachlese E5a: the digital goods of unpaid one-time purchases rest
        // the same way, asked from the same three places (cron, command,
        // payment). Optional for the tests built by hand; the container
        // wires it.
        private readonly PurchaseDelinquency|null $purchases = null,
    ) {
    }

    /**
     * @return list<DelinquencyChange>
     */
    public function run(int $now): array
    {
        $candidates = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT s.id FROM tl_consho_subscription s
              WHERE s.state = 'suspended'
                 OR (s.state = 'ended' AND EXISTS (
                    SELECT 1 FROM tl_consho_entitlement e WHERE e.subscription = s.id AND e.suspendedAt IS NOT NULL))
                 OR (s.state IN ('active', 'paused') AND EXISTS (
                    SELECT 1 FROM tl_consho_order o
                     WHERE (o.id = s.firstOrder OR o.subscription = s.id
                            OR (s.predecessor > 0 AND (o.subscription = s.predecessor
                                OR o.id = (SELECT p.firstOrder FROM tl_consho_subscription p WHERE p.id = s.predecessor))))
                       AND o.paymentDueAt > 0 AND o.paymentDueAt < ? AND o.cancelledAt IS NULL
                       AND o.grandTotal > (SELECT COALESCE(SUM(p.amount), 0) FROM tl_consho_payment p WHERE p.pid = o.id)))
              ORDER BY s.id",
            [$now],
        ));

        $changes = $this->reassess($candidates, $now);

        try {
            $purchases = $this->purchases?->run($now) ?? [];
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The payment state of unpaid purchases could not be checked: %s', $e->getMessage()), ['exception' => $e]);
            $purchases = [];
        }

        // One mail failing does not stop the others (second review, K-7).
        foreach ($purchases as $orderId => $resting) {
            try {
                $this->notifyPurchase($orderId, $resting);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('The mail about the digital goods of order %d was not sent: %s', $orderId, $e->getMessage()), ['exception' => $e]);
            }
        }

        return $changes;
    }

    /**
     * After a payment of an order: the subscriptions it belongs to, at once
     * rather than within the hour.
     *
     * @return list<DelinquencyChange>
     */
    public function afterPayment(int $orderId, int $now): array
    {
        $changes = $this->reassess($this->delinquency->subscriptionsOfOrder($orderId), $now);

        try {
            $resting = $this->purchases?->reassess($orderId, $now);

            if (null !== $resting) {
                $this->notifyPurchase($orderId, $resting);
            }
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The payment state of order %d could not be checked: %s', $orderId, $e->getMessage()), ['exception' => $e]);
        }

        return $changes;
    }

    /**
     * @param list<int> $subscriptionIds
     *
     * @return list<DelinquencyChange>
     */
    private function reassess(array $subscriptionIds, int $now): array
    {
        $changes = [];

        foreach ($subscriptionIds as $subscriptionId) {
            try {
                $change = $this->delinquency->reassess($subscriptionId, $now);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('The payment state of subscription %d could not be checked: %s', $subscriptionId, $e->getMessage()), ['exception' => $e]);

                continue;
            }

            if (null !== $change) {
                $changes[] = $change;
                $this->notify($change);
            }
        }

        return $changes;
    }

    /**
     * Nachlese E5 (Michael, 2026-10-09: "E5 Infomail umsetzen"): the
     * customer hears when the digital goods of a one-time purchase rest, and
     * when they are back. Not when a cancellation lifted the rest — the goods
     * end with the order then, and "available again" would be untrue.
     */
    private function notifyPurchase(int $orderId, bool $resting): void
    {
        try {
            $order = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$orderId]);

            if (false === $order || (!$resting && null !== $order['cancelledAt'] && '' !== (string) $order['cancelledAt'])) {
                return;
            }

            $viewUrl = $this->links->forMail($order);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The mail about the digital goods of order %d was not sent: %s', $orderId, $e->getMessage()), ['exception' => $e]);

            return;
        }

        if (null === $viewUrl) {
            $this->logger->warning(\sprintf('The mail about the digital goods of order %s was not sent: the shop has no order page.', $order['orderNumber'] ?? $orderId));

            return;
        }

        if (!$resting) {
            $this->mail->purchaseResumed($orderId, $viewUrl);

            return;
        }

        $balance = $this->balances->of($orderId);
        $outstanding = null === $balance ? '0' : (string) BigDecimal::max(BigDecimal::zero(), BigDecimal::of($balance->outstanding()));

        $this->mail->purchaseSuspended($orderId, $outstanding, $viewUrl);
    }

    private function notify(DelinquencyChange $change): void
    {
        try {
            $orderId = $change->suspended ? $change->overdueOrders[0] : $this->latestOrder($change->subscriptionId);
            $order = null === $orderId ? false : $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$orderId]);
            $viewUrl = false === $order ? null : $this->links->forMail($order);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The mail about subscription %s was not sent: %s', $change->subscriptionNumber, $e->getMessage()), ['exception' => $e]);

            return;
        }

        if (false === $order || null === $viewUrl) {
            $this->logger->warning(\sprintf('The mail about subscription %s was not sent: the shop has no order page.', $change->subscriptionNumber));

            return;
        }

        if (!$change->suspended) {
            $this->mail->subscriptionResumed((int) $order['id'], $change->subscriptionNumber, $viewUrl);

            return;
        }

        $balance = $this->balances->of((int) $order['id']);
        $outstanding = null === $balance ? '0' : (string) BigDecimal::max(BigDecimal::zero(), BigDecimal::of($balance->outstanding()));

        $this->mail->subscriptionSuspended((int) $order['id'], $change->subscriptionNumber, $outstanding, $viewUrl);
    }

    private function latestOrder(int $subscriptionId): int|null
    {
        $id = $this->connection->fetchOne(
            'SELECT o.id FROM tl_consho_order o JOIN tl_consho_subscription s ON s.id = ?
              WHERE o.id = s.firstOrder OR o.subscription = s.id
              ORDER BY o.createdAt DESC, o.id DESC LIMIT 1',
            [$subscriptionId],
        );

        return false === $id ? null : (int) $id;
    }
}

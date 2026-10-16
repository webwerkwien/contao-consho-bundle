<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;

/**
 * A follow-up order that could not be charged goes "on account" (Abo-4b).
 *
 *  - P95: the charge failed — at once, from SubscriptionCharger or from the
 *    provider's later notification
 *  - P96: the provider has not answered in 7 days — from the hourly cron;
 *    otherwise the order would hang without invoice and payment term, and
 *    the arrears of Abo-5 could never reach it
 *
 * Like "Zahlungsart ändern" (PaymentChange), and without a fee — the price of
 * the contract is frozen (A9): open attempts cancelled, a standing invoice
 * cancelled from its snapshot, the method of the order replaced, and the
 * invoice of the "on account" method issued now, since the delivery is
 * reported already (P27). Then "Rechnung zum Abo" as before.
 *
 * Not `paymentChangedAt`: the customer keeps the one change of method the
 * order page offers. The mandate stays on the subscription — the next period
 * is charged again (P95).
 *
 * Nothing changes for an order that is paid, partly paid, already "on
 * account", or held by its status (a cancelled order gets no invoice).
 *
 * Should the provider's money arrive after all, the notification books it
 * (Z3) and the order reads overpaid — the operator refunds it.
 */
class SubscriptionChargeFallback
{
    /** P96: how long a charge may stay pending. SEPA takes "a few days" at Stripe. */
    public const PENDING_DAYS = 7;

    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
        private readonly SubscriptionBillingMethod $billing,
        private readonly PaymentMethodLookupInterface $methods,
        private readonly PaymentHandlers $handlers,
        private readonly DocumentRepositoryInterface $documentRows,
        private readonly DocumentIssuerInterface $documents,
        private readonly DocumentArchiverInterface $archiver,
        private readonly OrderPageLink $links,
        private readonly OrderEventMail $mail,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param bool $quiet no warning when the shop lacks "on account" — the
     *                    hourly lapse() would repeat it for ever, and the
     *                    finding of P24 says it already (review Abo-4b, S5)
     *
     * @return bool whether the order went "on account" now
     */
    public function toInvoice(int $orderId, int $now, bool $quiet = false): bool
    {
        $this->connection->beginTransaction();

        try {
            $order = $this->orders->lockOrder($orderId);

            if (null === $order || !$this->applies($order)) {
                $this->connection->commit();

                return false;
            }

            $fallback = (string) $this->connection->fetchOne('SELECT fallbackLanguage FROM tl_consho_shop WHERE id = ?', [(int) $order['shop']]);
            $invoice = $this->billing->forShop((int) $order['shop'], (string) ($order['language'] ?? ''), $fallback);

            if (null === $invoice || !$this->handlers->has($invoice->type)) {
                $this->connection->commit();

                if ($quiet) {
                    return false;
                }

                $this->logger->warning(\sprintf('Follow-up order %s could not be charged and stays as it is: the shop has no payment method "on account".', $order['orderNumber'] ?? $orderId));

                return false;
            }

            $attempts = $this->orders->openAttempts($orderId);

            foreach ($attempts as $attempt) {
                $this->orders->cancelAttempt($attempt->id, $now);
            }

            // A module that issues on order or shipment issued one already.
            $standing = $this->documentRows->findIdByOrderAndType($orderId, DocumentType::Invoice);
            $cancellationId = null === $standing ? null : $this->documents->cancel($standing);

            $this->orders->updateOrder($orderId, [
                'tstamp' => $now,
                'paymentMethod' => $invoice->id,
                'paymentMethodTitle' => $invoice->title,
                'paymentInstructions' => \is_scalar($invoice->config['instructions'] ?? null) ? (string) $invoice->config['instructions'] : '',
                // The cancelled invoice's term goes with it; the new one writes its own.
                'paymentDueAt' => 0,
            ]);

            $trigger = $this->handlers->get($invoice->type)->documentTrigger($invoice->config);
            $invoiceId = DocumentTrigger::OnOrder === $trigger || (DocumentTrigger::OnShipment === $trigger && $this->orders->hasReportedDelivery($orderId))
                ? $this->documents->issueOnce($orderId, (int) $order['shop'], DocumentType::Invoice)
                : null;

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        $this->logger->warning(\sprintf('Follow-up order %s could not be charged and went "on account".', $order['orderNumber'] ?? $orderId));

        foreach ([$cancellationId, $invoiceId] as $documentId) {
            if (null !== $documentId) {
                $this->archiver->archive($documentId);
            }
        }

        $this->tellHandlers($attempts);
        $this->notify($orderId);

        return true;
    }

    /**
     * P96: every follow-up order whose charge has not come to anything for
     * PENDING_DAYS. The question is asked of the ORDER, not of an attempt:
     * a charge interrupted before its attempt was opened is found as well.
     * Never one the customer is paying in the browser right now.
     *
     * @return list<int> the orders that went "on account"
     */
    public function lapse(int $now): array
    {
        $candidates = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT o.id FROM tl_consho_order o
               LEFT JOIN tl_consho_payment_method m ON m.id = o.paymentMethod
              WHERE o.subscription > 0
                AND o.paymentSettledAt IS NULL
                AND o.cancelledAt IS NULL
                AND (o.paymentChangedAt IS NULL OR o.paymentChangedAt = 0)
                AND o.createdAt > 0 AND o.createdAt <= ?
                AND (m.id IS NULL OR m.type <> ?)
                AND NOT EXISTS (SELECT 1 FROM tl_consho_payment p WHERE p.pid = o.id)
                AND NOT EXISTS (
                    SELECT 1 FROM tl_consho_payment_attempt a
                     WHERE a.pid = o.id AND a.charge = 0 AND a.state IN ('open', 'pending')
                )
              ORDER BY o.id",
            [$now - self::PENDING_DAYS * 86400, InvoicePaymentHandler::getType()],
        ));

        $lapsed = [];

        foreach ($candidates as $orderId) {
            try {
                if ($this->toInvoice($orderId, $now, true)) {
                    $lapsed[] = $orderId;
                }
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Follow-up order %d could not be put "on account": %s', $orderId, $e->getMessage()), ['exception' => $e]);
            }
        }

        return $lapsed;
    }

    /**
     * @param array<string, string> $order
     */
    private function applies(array $order): bool
    {
        if ((int) ($order['subscription'] ?? 0) < 1 || '' !== (string) ($order['paymentSettledAt'] ?? '')) {
            return false;
        }

        // The customer's one change of method is theirs (review H4): what
        // they chose stays, whoever calls this.
        if ((int) ($order['paymentChangedAt'] ?? 0) > 0 || (int) ($order['cancelledAt'] ?? 0) > 0) {
            return false;
        }

        $status = (int) ($order['orderStatus'] ?? 0);

        if ($status > 0 && $this->orders->statusHolds($status)) {
            return false;
        }

        if ([] !== $this->orders->paymentAmounts((int) $order['id'])) {
            return false;
        }

        $current = $this->methods->byId((int) ($order['paymentMethod'] ?? 0));

        return null === $current || InvoicePaymentHandler::getType() !== $current->type;
    }

    /**
     * @param list<PaymentAttempt> $attempts
     */
    private function tellHandlers(array $attempts): void
    {
        foreach ($attempts as $attempt) {
            if (!$this->handlers->has($attempt->methodType)) {
                continue;
            }

            try {
                $this->handlers->get($attempt->methodType)->cancel($attempt, $this->methods->byId($attempt->methodId)->config ?? []);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Cancelling payment attempt %d with its provider failed: %s', $attempt->id, $e->getMessage()), ['exception' => $e]);
            }
        }
    }

    /**
     * "Abbuchung gestartet" (E7; Michael, 2026-10-08: "E-Punkte passen"):
     * a charge the provider reports as pending. Until the money or the
     * fallback arrives — up to PENDING_DAYS later — the customer would see a
     * booking without explanation. Amount, period and method; the invoice
     * follows with the payment. Here because this class already holds what
     * the mail needs. Never throws: the attempt is recorded either way.
     */
    public function announcePending(int $orderId): void
    {
        try {
            $order = $this->connection->fetchAssociative(
                'SELECT o.*, s.subscriptionNumber FROM tl_consho_order o JOIN tl_consho_subscription s ON s.id = o.subscription WHERE o.id = ?',
                [$orderId],
            );
            $viewUrl = false === $order ? null : $this->links->forMail($order);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The pending charge mail of follow-up order %d was not sent: %s', $orderId, $e->getMessage()), ['exception' => $e]);

            return;
        }

        if (false === $order || null === $viewUrl) {
            $this->logger->warning(\sprintf('The pending charge mail of follow-up order %d was not sent: the shop has no order page.', $orderId));

            return;
        }

        $this->mail->subscriptionChargePending($orderId, (string) $order['subscriptionNumber'], (int) $order['servicePeriodFrom'], (int) $order['servicePeriodUntil'], $viewUrl);
    }

    /**
     * "Rechnung zum Abo" as for any follow-up "on account" (P95). Never
     * throws — the order has changed, whatever its mail does.
     */
    private function notify(int $orderId): void
    {
        try {
            $order = $this->connection->fetchAssociative(
                'SELECT o.*, s.subscriptionNumber FROM tl_consho_order o JOIN tl_consho_subscription s ON s.id = o.subscription WHERE o.id = ?',
                [$orderId],
            );
            $viewUrl = false === $order ? null : $this->links->forMail($order);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The invoice mail of follow-up order %d was not sent: %s', $orderId, $e->getMessage()), ['exception' => $e]);

            return;
        }

        if (false === $order || null === $viewUrl) {
            $this->logger->warning(\sprintf('The invoice mail of follow-up order %d was not sent: the shop has no order page.', $orderId));

            return;
        }

        $this->mail->subscriptionInvoice($orderId, (string) $order['subscriptionNumber'], (int) $order['servicePeriodFrom'], (int) $order['servicePeriodUntil'], $viewUrl);
    }
}

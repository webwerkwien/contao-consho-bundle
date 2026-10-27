<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalRelease;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquencyRun;

/**
 * What follows once a payment is recorded (D-1, spec D 5.4).
 *
 * Called from both places money enters an order: the provider's notification
 * (online) and the back end (the operator types it). Until D-1 neither had
 * any consequence — an order paid online never got its invoice.
 *
 * When the payment balance comes out settled for the FIRST time
 * (paymentSettledAt), and only for an order that owes something:
 *
 *  1. the invoice, if the order's payment method issues it on payment (Z1)
 *  2. the automatic status change (spec D 5.3)
 *  3. every payment of the order is locked (A2) — together they produced it
 *  4. after the commit: the invoice PDF to the archive, the mail "Zahlung
 *     erhalten" with the invoice if it was issued now, and the operator's
 *     copy for an online payment (Z3)
 *
 * A part payment changes nothing and stays editable. A refund after the
 * settlement does not fire again: paymentSettledAt is set once.
 *
 * A follow-up order charged with the subscription's mandate (Abo-4b, P97)
 * gets "Rechnung zum Abo" in its charged version instead of "Zahlung
 * erhalten" — the customer was not there to pay, and the mail that says a
 * period has begun is the one they expect.
 *
 * ── The back-end window (C-10) ───────────────────────────
 *
 * DC_Table stores the payment before any callback can lock the order. The
 * lock here makes the CONSEQUENCES consistent with a concurrent payment
 * method change; the row itself may land a moment before the change commits.
 * The window is the time between clicking save and the callback — narrow,
 * and documented rather than closed (spec D, open points).
 */
class PaymentRecorded
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly PaymentMethodLookupInterface $methods,
        private readonly PaymentHandlers $handlers,
        private readonly DocumentIssuerInterface $documents,
        private readonly DocumentArchiverInterface $archiver,
        private readonly OrderStatusAdvancer $statuses,
        private readonly OrderPageLink $links,
        private readonly OrderEventMail $mail,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly DigitalRelease|null $releases = null,
        private readonly SubscriptionDelinquencyRun|null $subscriptions = null,
    ) {
    }

    /**
     * @param bool $online  whether the money came through a provider's
     *                      notification — then the operator gets a copy
     * @param bool $charged charged without the customer (Abo-4b, P94)
     */
    public function handle(int $orderId, bool $online, int $now, bool $charged = false): void
    {
        $settled = false;
        $invoiceId = null;

        $this->connection->beginTransaction();

        try {
            $order = $this->orders->lockOrder($orderId);
            $balance = null === $order ? null : $this->balances->of($orderId);

            if (null !== $order && null !== $balance
                && '' === (string) ($order['paymentSettledAt'] ?? '')
                && BigDecimal::of($balance->claim)->isPositive()
                && $balance->paymentSettled()
            ) {
                $settled = true;
                $this->connection->update('tl_consho_order', ['paymentSettledAt' => $now], ['id' => $orderId]);

                if ($this->issuesOnPayment((int) $order['paymentMethod']) && !$this->documents->hasDocument($orderId, DocumentType::Invoice)) {
                    $invoiceId = $this->documents->issueOnce($orderId, (int) $order['shop'], DocumentType::Invoice);
                }

                $this->statuses->advance($orderId, OrderEvent::PaymentSettled, $now);

                $this->connection->executeStatement(
                    'UPDATE tl_consho_payment SET lockedAt = ? WHERE pid = ? AND lockedAt IS NULL',
                    [$now, $orderId],
                );
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        if (!$settled) {
            return;
        }

        if (null !== $invoiceId) {
            $this->archiver->archive($invoiceId);
        }

        /*
         * Digital lines that waited for the money (F-4, F10) — before the
         * mail, so "Zahlung erhalten" can say they are ready. The cron picks
         * up what fails here.
         */
        if (null !== $this->releases) {
            try {
                $this->releases->releaseDue($orderId, $now);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Digital lines of order %s could not be released: %s', $order['orderNumber'] ?? $orderId, $e->getMessage()));
            }
        }

        $viewUrl = $this->links->forMail($order);

        if (null === $viewUrl) {
            // Without an order page the mail would link nowhere — the same
            // reason the checkout refuses to sell without one.
            $this->logger->warning(\sprintf('The "payment received" mail of order %s was not sent: the shop has no order page.', $order['orderNumber'] ?? $orderId));
        } elseif ($charged && (int) ($order['subscription'] ?? 0) > 0) {
            // P97. The subscription's number is all the mail needs besides the order.
            $number = (string) $this->connection->fetchOne('SELECT subscriptionNumber FROM tl_consho_subscription WHERE id = ?', [(int) $order['subscription']]);
            $this->mail->subscriptionInvoice($orderId, $number, (int) ($order['servicePeriodFrom'] ?? 0), (int) ($order['servicePeriodUntil'] ?? 0), $viewUrl, $now);
        } else {
            $this->mail->paymentReceived($orderId, $invoiceId, $viewUrl, $online);
        }

        /*
         * A resting subscription comes back with the payment that settles it
         * (Abo-5, P35) — now, not within the hour. AFTER "Zahlung erhalten":
         * the customer reads the payment first, then that the access is back
         * (Abo-5). The run never throws for a subscription; the
         * cron asks again what fails here.
         */
        if (null !== $this->subscriptions) {
            try {
                $this->subscriptions->afterPayment($orderId, $now);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('The subscriptions of order %s could not be checked: %s', $order['orderNumber'] ?? $orderId, $e->getMessage()));
            }
        }
    }

    private function issuesOnPayment(int $methodId): bool
    {
        $method = $this->methods->byId($methodId);

        if (null === $method || !$this->handlers->has($method->type)) {
            return false;
        }

        return DocumentTrigger::OnPayment === $this->handlers->get($method->type)->documentTrigger($method->config);
    }
}

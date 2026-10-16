<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;

/**
 * The digital goods of an unpaid one-time purchase rest (Nachlese E5a;
 * Michael, 2026-10-09: "E5 Weg C").
 *
 * "Auf Rechnung" releases digital goods with the order (F10): the goods go
 * first, the money after. Until now nothing followed when the money never
 * came. Now, as Abo-5 does for subscriptions: once the payment term plus the
 * grace period is over and the balance still owes something, the order's
 * entitlements rest (`suspendedAt`); with the payment — or a cancellation, a
 * refund that settles it — they come back. Nothing is counted: every call
 * works the state out afresh from the order.
 *
 * Only entitlements of no subscription: a subscription's rest is
 * SubscriptionDelinquency's, with its own frozen grace period. The grace
 * period here is the payment method's (`graceDays`, empty = 14 days), read
 * when asked — unlike a subscription's it is part of no contract the customer
 * was shown.
 *
 * What was downloaded stays downloaded; resting stops further downloads, key
 * deliveries and what a module reads through EntitlementQuery. The customer is
 * told both ways (Michael, 2026-10-09: "E5 Infomail umsetzen"), by
 * SubscriptionDelinquencyRun, which already mails for subscriptions; this
 * class only says what changed.
 */
class PurchaseDelinquency
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly PaymentMethodLookupInterface $methods,
        private readonly SubscriptionCalendar $calendar,
    ) {
    }

    /**
     * Every order that may have to rest or come back.
     *
     * @return array<int, bool> order id => true: now resting, false: back
     */
    public function run(int $now): array
    {
        $orders = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT DISTINCT o.id FROM tl_consho_order o
               JOIN tl_consho_entitlement e ON e.pid = o.id AND e.subscription = 0
              WHERE e.suspendedAt IS NOT NULL
                 OR (o.paymentDueAt > 0 AND o.paymentDueAt < ? AND o.cancelledAt IS NULL
                     AND o.grandTotal > (SELECT COALESCE(SUM(p.amount), 0) FROM tl_consho_payment p WHERE p.pid = o.id))
              ORDER BY o.id',
            [$now],
        ));

        $changes = [];

        foreach ($orders as $orderId) {
            $change = $this->reassess($orderId, $now);

            if (null !== $change) {
                $changes[$orderId] = $change;
            }
        }

        return $changes;
    }

    /**
     * Whether the ORDER changed, not a row: an entitlement released late into
     * a resting order rests too, but the order rested before — no second mail.
     *
     * @return bool|null true: now resting, false: back, null: unchanged
     */
    public function reassess(int $orderId, int $now): bool|null
    {
        $resting = $this->overdue($orderId, $now);
        $rested = false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_entitlement WHERE pid = ? AND subscription = 0 AND suspendedAt IS NOT NULL LIMIT 1', [$orderId]);

        $changed = (int) $this->connection->executeStatement(
            $resting
                ? 'UPDATE tl_consho_entitlement SET suspendedAt = ?, tstamp = ? WHERE pid = ? AND subscription = 0 AND suspendedAt IS NULL'
                : 'UPDATE tl_consho_entitlement SET suspendedAt = NULL, tstamp = ? WHERE pid = ? AND subscription = 0 AND suspendedAt IS NOT NULL',
            $resting ? [$now, $now, $orderId] : [$now, $orderId],
        );

        return 0 === $changed || $rested === $resting ? null : $resting;
    }

    /**
     * An entitlement released into an order that already rests rests too —
     * without deciding anything: the order's state does not change, so there
     * is nothing to mail (DigitalRelease, second review S-1).
     */
    public function joinRest(int $orderId, int $now): void
    {
        $rests = false !== $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_entitlement WHERE pid = ? AND subscription = 0 AND suspendedAt IS NOT NULL LIMIT 1',
            [$orderId],
        );

        if ($rests) {
            $this->connection->executeStatement(
                'UPDATE tl_consho_entitlement SET suspendedAt = ?, tstamp = ? WHERE pid = ? AND subscription = 0 AND suspendedAt IS NULL',
                [$now, $now, $orderId],
            );
        }
    }

    private function overdue(int $orderId, int $now): bool
    {
        $order = $this->connection->fetchAssociative(
            'SELECT paymentDueAt, paymentMethod, cancelledAt, subscription FROM tl_consho_order WHERE id = ?',
            [$orderId],
        );

        // A follow-up order belongs to its subscription's own assessment.
        if (false === $order || null !== $order['cancelledAt'] || (int) $order['subscription'] > 0) {
            return false;
        }

        $dueAt = (int) $order['paymentDueAt'];

        if ($dueAt < 1) {
            return false;
        }

        $method = $this->methods->byId((int) $order['paymentMethod']);
        $grace = InvoicePaymentHandler::graceDays(null === $method ? [] : $method->config);

        if ($this->calendar->addDays($dueAt, $grace) >= $now) {
            return false;
        }

        $balance = $this->balances->of($orderId);

        return null !== $balance && BigDecimal::of($balance->outstanding())->isPositive();
    }
}

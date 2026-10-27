<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;

/**
 * Whether a subscription rests for an unpaid invoice (Abo-5; A6, P33–P36).
 *
 * An order of the subscription — the purchase as much as a follow-up (P34) —
 * is overdue when its payment term plus the frozen grace period is over, it
 * is not cancelled, and its balance still owes something. Then the
 * subscription is `suspended` and every entitlement of it rests
 * (`suspendedAt`); with no order overdue any more it is `active` again and
 * the entitlements come back. Nothing is counted: every call works the state
 * out afresh from the orders (P35), so the payment that settles it, a
 * cancellation by the operator and the hourly run all reach the same answer.
 *
 * Billing goes on while it rests (P36), and the core never cancels (A6). There
 * is no way to lift the rest by hand (P39) — the payment does it.
 *
 * Under the subscription's lock, in a transaction of its own, like the biller:
 * the payment and the cron must not decide past each other. A resting
 * subscription whose entitlement was released only now (a key pool refilled
 * late) is caught on the next call — the rest is synced, not only switched.
 *
 * A cancelled subscription keeps its state until its end (Abo-7, P56): the
 * cancellation is its `endsAt`, and until then it is assessed like any other.
 *
 * An `ended` subscription is not suspended any more, but what rested when it
 * ended comes free once nothing is overdue (P64, Abo-6) — without a
 * mail: "active again" would be untrue. It never starts to rest once ended.
 */
class SubscriptionDelinquency
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionLockInterface $lock,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly SubscriptionCalendar $calendar,
    ) {
    }

    public function reassess(int $subscriptionId, int $now): DelinquencyChange|null
    {
        $level = $this->connection->getTransactionNestingLevel();
        $this->connection->beginTransaction();

        try {
            $change = $this->reassessLocked($subscriptionId, $now);
            $this->connection->commit();
        } catch (\Throwable $e) {
            while ($this->connection->getTransactionNestingLevel() > $level) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        return $change;
    }

    /**
     * Whether an order of the subscription is past its payment term and still
     * open — without the grace period: "Lizenz verlängern" is not offered
     * while anything is overdue at all (Abo-7b, P66), not only once the
     * subscription rests.
     *
     * @param array<string, mixed> $subscription a row of tl_consho_subscription
     */
    public function hasOverdue(array $subscription, int $now): bool
    {
        return [] !== $this->overdueOrders((int) $subscription['id'], (int) $subscription['firstOrder'], 0, $now);
    }

    /**
     * The subscriptions of an order — for the payment that may settle it.
     *
     * @return list<int>
     */
    public function subscriptionsOfOrder(int $orderId): array
    {
        // And the renewal that carries its licence on (Abo-7b): a payment of
        // the old subscription may free the licence the new one now holds.
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT s.id FROM tl_consho_subscription s
               JOIN tl_consho_order o ON o.id = ?
              WHERE s.id = o.subscription OR s.firstOrder = o.id
                 OR s.predecessor IN (SELECT p.id FROM tl_consho_subscription p WHERE p.id = o.subscription OR p.firstOrder = o.id)
              ORDER BY s.id',
            [$orderId],
        ));
    }

    private function reassessLocked(int $subscriptionId, int $now): DelinquencyChange|null
    {
        $subscription = $this->lock->lock($subscriptionId);

        if (null === $subscription) {
            return null;
        }

        /*
         * A renewal and its predecessor share one licence (Abo-7b): whoever
         * holds it speaks, the other stays silent — otherwise every overdue
         * invoice of the old contract came twice, under two numbers.
         *
         * The renewed one without the licence: it has moved on, the renewal
         * rests in its place. A state left `suspended` from before the move is
         * set back without a mail — nothing of it rests any more.
         */
        $holds = false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_entitlement WHERE subscription = ? LIMIT 1', [$subscriptionId]);

        if ((int) ($subscription['renewedBy'] ?? 0) > 0 && !$holds) {
            if ('suspended' === (string) $subscription['state']) {
                $this->connection->update('tl_consho_subscription', ['state' => 'active', 'tstamp' => $now], ['id' => $subscriptionId]);
            }

            return null;
        }

        // The renewal before its release, while the predecessor still holds
        // the licence: that one answers for it.
        $predecessor = (int) ($subscription['predecessor'] ?? 0);

        if ($predecessor > 0 && !$holds
            && false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_entitlement WHERE subscription = ? LIMIT 1', [$predecessor])
        ) {
            return null;
        }

        if ('ended' === (string) $subscription['state']) {
            $this->releaseEnded($subscription, $now);

            return null;
        }

        // Paused too (P108): an invoice from before the pause stays due, and
        // its arrears run on.
        if (!\in_array((string) $subscription['state'], ['active', 'suspended', 'paused'], true)) {
            return null;
        }

        $overdue = $this->overdueOrders($subscriptionId, (int) $subscription['firstOrder'], (int) $subscription['graceDays'], $now);
        $number = (string) $subscription['subscriptionNumber'];

        if ([] !== $overdue) {
            // Synced every time: an entitlement released while resting rests too.
            $this->connection->executeStatement(
                'UPDATE tl_consho_entitlement SET suspendedAt = ?, tstamp = ? WHERE subscription = ? AND suspendedAt IS NULL',
                [$now, $now, $subscriptionId],
            );

            if ('suspended' === (string) $subscription['state']) {
                return null;
            }

            $this->connection->update('tl_consho_subscription', ['state' => 'suspended', 'tstamp' => $now], ['id' => $subscriptionId]);

            return new DelinquencyChange($subscriptionId, $number, true, $overdue);
        }

        $this->connection->executeStatement(
            'UPDATE tl_consho_entitlement SET suspendedAt = NULL, tstamp = ? WHERE subscription = ? AND suspendedAt IS NOT NULL',
            [$now, $subscriptionId],
        );

        // Nothing rests: an active or paused one stays as it is — only the
        // resting one comes back.
        if ('active' === (string) $subscription['state'] || 'paused' === (string) $subscription['state']) {
            return null;
        }

        // Paid while its pause runs (Abo pausieren): back to paused,
        // without "wieder aktiv" — the access rests for the pause anyway.
        $pauseFrom = (int) ($subscription['pauseFrom'] ?? 0);

        if ($pauseFrom > 0 && $pauseFrom <= $now && (int) ($subscription['pauseUntil'] ?? 0) > $now) {
            $this->connection->update('tl_consho_subscription', ['state' => 'paused', 'tstamp' => $now], ['id' => $subscriptionId]);

            return null;
        }

        $this->connection->update('tl_consho_subscription', ['state' => 'active', 'tstamp' => $now], ['id' => $subscriptionId]);

        return new DelinquencyChange($subscriptionId, $number, false);
    }

    /**
     * P64: what rested when the subscription ended, free once nothing is
     * overdue.
     *
     * @param array<string, mixed> $subscription
     */
    private function releaseEnded(array $subscription, int $now): void
    {
        $id = (int) $subscription['id'];

        if ([] !== $this->overdueOrders($id, (int) $subscription['firstOrder'], (int) $subscription['graceDays'], $now)) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE tl_consho_entitlement SET suspendedAt = NULL, tstamp = ? WHERE subscription = ? AND suspendedAt IS NOT NULL',
            [$now, $id],
        );
    }

    /**
     * SQL narrows to orders past their term and not cancelled; the grace
     * period is added in calendar days, and the balance decides — it knows
     * part payments, withdrawals and refunds, a plain sum does not.
     *
     * @return list<int>
     */
    private function overdueOrders(int $subscriptionId, int $firstOrder, int $graceDays, int $now): array
    {
        /*
         * And the orders of the subscription this one renewed (Abo-7b, P69):
         * its licence moved over, so an invoice of the old contract still
         * open makes it rest here — otherwise "Lizenz verlängern" would be a
         * way out of an unpaid invoice. One step back only: an invoice of the
         * predecessor's own predecessor that falls overdue after this renewal
         * was bought is not counted here (Abo-7b; open point).
         */
        $candidates = $this->connection->fetchAllKeyValue(
            'SELECT id, paymentDueAt FROM tl_consho_order
              WHERE (id = ? OR subscription = ?
                     OR subscription IN (SELECT p.id FROM tl_consho_subscription p WHERE p.renewedBy = ?)
                     OR id IN (SELECT p.firstOrder FROM tl_consho_subscription p WHERE p.renewedBy = ?))
                AND paymentDueAt > 0 AND paymentDueAt < ? AND cancelledAt IS NULL
              ORDER BY paymentDueAt, id',
            [$firstOrder, $subscriptionId, $subscriptionId, $subscriptionId, $now],
        );

        $overdue = [];

        foreach ($candidates as $orderId => $dueAt) {
            if ($this->calendar->addDays((int) $dueAt, max(0, $graceDays)) >= $now) {
                continue;
            }

            $balance = $this->balances->of((int) $orderId);

            if (null !== $balance && BigDecimal::of($balance->outstanding())->isPositive()) {
                $overdue[] = (int) $orderId;
            }
        }

        return $overdue;
    }
}

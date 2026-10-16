<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Webwerkwien\ContaoConshoBundle\Digital\EntitlementRevocations;

/**
 * A subscription whose purchase is cancelled ends with it (Abo-Nachlese, P72;
 * Michael, 2026-10-01: „ja, so umsetzen") — otherwise it billed, month after
 * month, for something never bought. A purchase can only be cancelled before
 * anything was delivered (OrderStatusListener refuses it after), so there is
 * no entitlement of its own to take back.
 *
 * A renewal gives the licence back as well (Abo-7b; review S1). The purchase
 * claimed the old subscription (`renewedBy`) and, once released, moved its
 * entitlement onto the new order (DigitalRelease::takeOver()). The old
 * subscription is renewable again, the entitlement goes back to its line and
 * its paid period. It may come back resting — for the renewal's own unpaid
 * invoice, which is cancelled now (second review, S-1): the old subscription
 * is asked again at once (SubscriptionDelinquency::reassess()), as no payment
 * follows a cancelled order.
 *
 * Called through OrderCancellation (Nachlese K6) by both writers of
 * `tl_consho_order.cancelledAt` — the status set in the back end
 * (OrderStatusListener) and an automatic status change (ContaoOrderStatusWriter,
 * used by OrderStatusAdvancer) — in the same transaction as that write
 * (second review, S-3).
 *
 * A subscription renewed in turn ends too, but keeps its licence where it is:
 * once another subscription holds it (A → B → C, B's purchase cancelled), it
 * is left with C — an open point of the spec (second review, L-2).
 *
 * An accepted withdrawal of the purchase takes the same way, line by line
 * (afterWithdrawal(), Abo-8, P78).
 */
class SubscriptionPurchaseCancellation
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionDelinquency $delinquency,
        // Nachlese E6: delivery modules told of revoked entitlements. Optional
        // for the tests built by hand; the container wires it.
        private readonly EntitlementRevocations|null $revocations = null,
    ) {
    }

    public function afterCancellation(int $orderId, int $now): void
    {
        $this->end($this->connection->fetchAllAssociative(
            "SELECT id, predecessor, renewedBy FROM tl_consho_subscription WHERE firstOrder = ? AND state <> 'ended'",
            [$orderId],
        ), $now);
    }

    /**
     * An accepted withdrawal of the purchase (Abo-8, P78; O3): the
     * subscriptions of the withdrawn lines end at once, the same way as on a
     * cancellation. Unlike a cancellation, a withdrawal can come after the
     * release — so where the licence does not go back to a predecessor, the
     * subscription's own entitlement is revoked: for content and service
     * alike, nothing is used after the contract is gone.
     *
     * Only the purchase: a follow-up order opens no withdrawal (A12), and
     * the caller runs inside the acceptance's transaction.
     *
     * @param list<int> $orderItems the order lines accepted as withdrawn
     */
    public function afterWithdrawal(int $orderId, array $orderItems, int $now): void
    {
        if ([] === $orderItems) {
            return;
        }

        $this->end($this->connection->fetchAllAssociative(
            "SELECT id, predecessor, renewedBy FROM tl_consho_subscription WHERE firstOrder = ? AND orderItem IN (?) AND state <> 'ended'",
            [$orderId, $orderItems],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        ), $now, true);
    }

    /**
     * @param list<array<string, mixed>> $subscriptions
     */
    private function end(array $subscriptions, int $now, bool $revoke = false): void
    {
        foreach ($subscriptions as $subscription) {
            // Every subscription of the purchase ends — also one renewed in
            // the meantime (review Abo-Nachlese, S3).
            // The mandate with it, as at every end (Abo-4b, P98).
            $this->connection->update('tl_consho_subscription', ['state' => 'ended', 'endsAt' => $now, 'mandate' => '', 'mandateMethod' => 0, 'tstamp' => $now], ['id' => (int) $subscription['id']]);

            // Held in turn by a renewal (L-2): the licence stays there.
            if ((int) $subscription['renewedBy'] > 0) {
                continue;
            }

            $old = (int) $subscription['predecessor'] < 1 ? false : $this->connection->fetchAssociative(
                'SELECT id, firstOrder, orderItem, periodUntil FROM tl_consho_subscription WHERE id = ?',
                [(int) $subscription['predecessor']],
            );

            if (false === $old) {
                if ($revoke) {
                    $ids = array_map('intval', $this->connection->fetchFirstColumn(
                        'SELECT id FROM tl_consho_entitlement WHERE subscription = ? AND revoked = 0',
                        [(int) $subscription['id']],
                    ));

                    $this->connection->executeStatement(
                        'UPDATE tl_consho_entitlement SET revoked = 1, tstamp = ? WHERE subscription = ?',
                        [$now, (int) $subscription['id']],
                    );

                    $this->revocations?->revoked($ids);
                }

                continue;
            }

            $this->connection->executeStatement(
                'UPDATE tl_consho_entitlement SET pid = ?, orderItem = ?, subscription = ?, validUntil = ?, tstamp = ? WHERE subscription = ?',
                [(int) $old['firstOrder'], (int) $old['orderItem'], (int) $old['id'], (int) $old['periodUntil'], $now, (int) $subscription['id']],
            );

            $this->connection->executeStatement(
                'UPDATE tl_consho_subscription SET renewedBy = 0, tstamp = ? WHERE id = ? AND renewedBy = ?',
                [$now, (int) $old['id'], (int) $subscription['id']],
            );

            $this->delinquency->reassess((int) $old['id'], $now);
        }
    }
}

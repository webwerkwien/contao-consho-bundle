<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Orders holding more money than they claim: money that has to go back
 * (block Geldflüsse, 3.4).
 *
 * A cancelled order claims nothing, so every payment on it is too much; an
 * overpayment is the same case with a claim above 0. Nothing is paid out
 * automatically — the dashboard and a finding remind the operator, who pays
 * back through "Erstatten" at a payment.
 *
 * The balance decides, as everywhere: SQL only narrows the candidates and may
 * return too many. Left out (G1, G2):
 *
 *  - less than 0.01 too much — the rounding of four-place amounts;
 *  - what the provider already paid back on its own (an open foreign refund)
 *    counts as gone: the money is back with the customer;
 *  - an accepted withdrawal not refunded yet lowers the claim at once, and the
 *    tile "Erstattungen" already counts it;
 *  - a chargeback reversed after a new payment has its own, more precise
 *    finding (ProviderEventIssueSource).
 */
final class OverpaidOrders implements ResetInterface
{
    private const MINIMUM = '0.01';

    // RK-4: a complaint that lowers the claim — a decided rescission or price
    // reduction — counts like an accepted withdrawal.
    private const DECIDED_COMPLAINT = "c.decidedAt IS NOT NULL AND c.remedy IN ('rescission', 'reduction') AND c.state <> 'rejected'";

    /** @var array<int, array{shop: int, number: string, excess: string}>|null */
    private array|null $orders = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
    ) {
    }

    /**
     * @return array<int, array{shop: int, number: string, excess: string}> keyed by order id, by shop and id
     */
    public function all(): array
    {
        if (null !== $this->orders) {
            return $this->orders;
        }

        $paid = '(SELECT COALESCE(SUM(p.amount), 0) FROM tl_consho_payment p WHERE p.pid = o.id)';

        // The minimum as a literal: SQLite compares a sum with a bound string
        // as text, and every number is less than any text.

        $candidates = $this->connection->fetchAllAssociative(
            "SELECT o.id, o.shop, o.orderNumber FROM tl_consho_order o
              WHERE $paid >= ".self::MINIMUM."
                AND (o.cancelledAt IS NOT NULL
                     OR $paid > o.grandTotal
                     OR o.orderStatus IN (SELECT s.id FROM tl_consho_order_status s WHERE s.isCancelled = 1)
                     OR EXISTS (SELECT 1 FROM tl_consho_withdrawal w WHERE w.pid = o.id AND w.state = 'accepted')
                     OR EXISTS (SELECT 1 FROM tl_consho_complaint c WHERE c.pid = o.id AND ".self::DECIDED_COMPLAINT."))
                AND NOT EXISTS (SELECT 1 FROM tl_consho_withdrawal w
                                 WHERE w.pid = o.id AND w.state = 'accepted' AND w.refundedAt IS NULL)
                AND NOT EXISTS (SELECT 1 FROM tl_consho_complaint c
                                 WHERE c.pid = o.id AND ".self::DECIDED_COMPLAINT." AND c.refundedAt IS NULL)
                AND NOT EXISTS (SELECT 1 FROM tl_consho_provider_event e
                                 WHERE e.pid = o.id AND e.kind = 'chargeback' AND e.state IN ('open', 'settled') AND e.reversedAt > 0)
              ORDER BY o.shop, o.id",
        );

        $orders = [];

        foreach ($candidates as $row) {
            $balance = $this->balances->of((int) $row['id']);

            if (null === $balance) {
                continue;
            }

            $outside = (string) $this->connection->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) FROM tl_consho_provider_event WHERE pid = ? AND kind = 'refund' AND state = 'open'",
                [(int) $row['id']],
            );

            $excess = BigDecimal::of($balance->outstanding())->negated()->minus($outside);

            if ($excess->isLessThan(self::MINIMUM)) {
                continue;
            }

            $orders[(int) $row['id']] = [
                'shop' => (int) $row['shop'],
                'number' => (string) $row['orderNumber'],
                'excess' => (string) $excess->toScale(4),
            ];
        }

        return $this->orders = $orders;
    }

    /**
     * Kept for the request; a long-running worker must not show the last
     * request's orders.
     */
    public function reset(): void
    {
        $this->orders = null;
    }
}

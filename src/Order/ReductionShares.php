<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;

/**
 * How a complaint's price reduction travels with the goods (RK-4, L3).
 *
 * A reduction lowers what the units of a line still with the customer are
 * worth: it is spread evenly over the quantity the line had left when the
 * reduction was decided. A unit that goes back later — a withdrawal, a
 * rescission — takes its share of the reduction along, so its credit is
 * lower by that share; the units that stay carry the rest.
 *
 * Worked out from the decisions alone (their `decidedAt`), so the answer does
 * not depend on when it is asked, and nothing is stored twice: a return
 * decided after a reduction carries a part of it, one decided before or in
 * the same second carries none — and was not part of the quantity the
 * reduction was spread over either.
 */
class ReductionShares
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The part of the line's reductions that `$quantity` units going back,
     * decided at `$at`, take along.
     */
    public function taken(int $orderId, int $itemId, BigDecimal|string $quantity, int $at): BigDecimal
    {
        $quantity = BigDecimal::of((string) $quantity);
        $taken = BigDecimal::zero();

        if (!$quantity->isPositive()) {
            return $taken;
        }

        [$returns, $reductions, $lineQuantity] = $this->events($orderId, $itemId);

        foreach ($reductions as $reduction) {
            if ($reduction['at'] >= $at) {
                continue;
            }

            $spread = $this->spread($lineQuantity, $returns, $reduction['at']);

            if ($spread->isPositive()) {
                $taken = $taken->plus($reduction['amount']->multipliedBy($quantity)->dividedBy($spread, 4, RoundingMode::HalfUp));
            }
        }

        return $taken;
    }

    /**
     * What of the line's reductions the units still with the customer carry:
     * every reduction less what the returns after it took along.
     *
     * @param int $exceptComplaint a complaint being decided anew: neither its reduction nor its rescission counts
     */
    public function carried(int $orderId, int $itemId, int $exceptComplaint = 0): BigDecimal
    {
        [$returns, $reductions, $lineQuantity] = $this->events($orderId, $itemId, $exceptComplaint);
        $carried = BigDecimal::zero();

        foreach ($reductions as $reduction) {
            $spread = $this->spread($lineQuantity, $returns, $reduction['at']);
            $left = $reduction['amount'];

            foreach ($returns as $return) {
                if ($return['at'] > $reduction['at'] && $spread->isPositive()) {
                    $left = $left->minus($reduction['amount']->multipliedBy($return['quantity'])->dividedBy($spread, 4, RoundingMode::HalfUp));
                }
            }

            $carried = $carried->plus(BigDecimal::max(BigDecimal::zero(), $left));
        }

        return $carried;
    }

    /**
     * How much of the line went back, by accepted withdrawals and decided
     * rescissions together.
     *
     * @param int $exceptComplaint a complaint being decided anew, whose rescission does not count
     */
    public function returned(int $orderId, int $itemId, int $exceptComplaint = 0): BigDecimal
    {
        $returned = BigDecimal::zero();

        foreach ($this->events($orderId, $itemId, $exceptComplaint)[0] as $return) {
            $returned = $returned->plus($return['quantity']);
        }

        return $returned;
    }

    /**
     * @param list<array{at: int, quantity: BigDecimal}> $returns
     */
    private function spread(BigDecimal $lineQuantity, array $returns, int $at): BigDecimal
    {
        $left = $lineQuantity;

        foreach ($returns as $return) {
            if ($return['at'] <= $at) {
                $left = $left->minus($return['quantity']);
            }
        }

        return $left;
    }

    /**
     * @return array{0: list<array{at: int, quantity: BigDecimal}>, 1: list<array{at: int, amount: BigDecimal, complaint: int}>, 2: BigDecimal}
     */
    private function events(int $orderId, int $itemId, int $exceptComplaint = 0): array
    {
        $returns = [];

        foreach ($this->connection->fetchAllAssociative(
            "SELECT w.decidedAt, wi.acceptedQuantity AS quantity
               FROM tl_consho_withdrawal_item wi
               JOIN tl_consho_withdrawal w ON w.id = wi.pid
              WHERE w.pid = ? AND w.state = 'accepted' AND wi.orderItem = ? AND wi.acceptedQuantity > 0",
            [$orderId, $itemId],
        ) as $row) {
            $returns[] = ['at' => (int) $row['decidedAt'], 'quantity' => BigDecimal::of((string) $row['quantity'])];
        }

        $reductions = [];

        foreach ($this->connection->fetchAllAssociative(
            "SELECT c.id, c.remedy, c.decidedAt, ci.rescindedQuantity, ci.reduction
               FROM tl_consho_complaint_item ci
               JOIN tl_consho_complaint c ON c.id = ci.pid
              WHERE c.pid = ? AND ci.orderItem = ? AND c.decidedAt IS NOT NULL AND c.state <> 'rejected'
                AND c.remedy IN ('rescission', 'reduction')",
            [$orderId, $itemId],
        ) as $row) {
            if ((int) $row['id'] === $exceptComplaint) {
                continue;
            }

            if ('rescission' === $row['remedy'] && BigDecimal::of((string) $row['rescindedQuantity'])->isPositive()) {
                $returns[] = ['at' => (int) $row['decidedAt'], 'quantity' => BigDecimal::of((string) $row['rescindedQuantity'])];
            }

            if ('reduction' === $row['remedy'] && BigDecimal::of((string) ($row['reduction'] ?? '0'))->isPositive()) {
                $reductions[] = ['at' => (int) $row['decidedAt'], 'amount' => BigDecimal::of((string) $row['reduction']), 'complaint' => (int) $row['id']];
            }
        }

        $quantity = $this->connection->fetchOne('SELECT quantity FROM tl_consho_order_item WHERE id = ? AND pid = ?', [$itemId, $orderId]);

        return [$returns, $reductions, BigDecimal::of(false === $quantity ? '0' : (string) $quantity)];
    }
}

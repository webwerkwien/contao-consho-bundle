<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * What lowers an order's claim, and how much of each order item went back
 * (D-3, F7). Today these are accepted withdrawals; a complaint's rescission
 * and price reduction count here too, so nothing else works it out again.
 *
 * ONE place for it, asked by everything that works out what an order still
 * owes: the balance (OrderBalanceReader), the start of an online payment
 * (PaymentStarter) and the change of payment method (PaymentChange). Before
 * D-3 the latter two still charged the full grand
 * total after a withdrawal — 100 € started online where 70 € were owed —
 * because each had worked out the claim on its own from the order row.
 *
 * A refunded withdrawal counts with its booked amount; an accepted one not
 * yet refunded with the goods' share of their gross line totals
 * (lineTotal / quantity × accepted — there is no gross unit price column)
 * less their parts of the order's reductions (block H, H-5, R1; DiscountShares),
 * less the value compensation proposed for a service (Abo-8, P79), which
 * stays owed.
 */
class OrderCredit
{
    private ReductionShares|null $reductions = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{0: string, 1: array<int, string>} credited amount, order item id => quantity gone back
     *                                                 (accepted withdrawals and rescissions)
     */
    public function of(int $orderId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT w.id, w.decidedAt, w.refundedAt, w.refundAmount, w.valueCompensation, wi.orderItem, wi.acceptedQuantity, i.quantity, i.lineTotal
               FROM tl_consho_withdrawal w
          LEFT JOIN tl_consho_withdrawal_item wi ON wi.pid = w.id
          LEFT JOIN tl_consho_order_item i ON i.id = wi.orderItem AND i.pid = w.pid
              WHERE w.pid = ? AND w.state = 'accepted'",
            [$orderId],
        );

        $credited = BigDecimal::zero();
        $withdrawn = [];
        $refunded = [];
        $pending = [];
        $items = null;
        $parts = [];

        foreach ($rows as $row) {
            $accepted = BigDecimal::of((string) ($row['acceptedQuantity'] ?? '0'));

            if (null !== $row['orderItem'] && $accepted->isPositive()) {
                $itemId = (int) $row['orderItem'];
                $withdrawn[$itemId] = (string) BigDecimal::of($withdrawn[$itemId] ?? '0')->plus($accepted);
            }

            if (null !== $row['refundedAt']) {
                // Once per withdrawal, however many item rows the join gives.
                $refunded[(int) $row['id']] = (string) ($row['refundAmount'] ?? '0');

                continue;
            }

            $id = (int) $row['id'];
            $pending[$id] ??= ['credit' => BigDecimal::zero(), 'kept' => (string) ($row['valueCompensation'] ?? '0')];

            if ($accepted->isPositive() && null !== $row['orderItem']) {
                if (null === $items) {
                    $items = $this->items($orderId);
                    $parts = DiscountShares::of($items);
                }

                $item = $items[(int) $row['orderItem']] ?? null;

                if (null !== $item) {
                    $pending[$id]['credit'] = $pending[$id]['credit']->plus($this->credit($orderId, $item, $parts, $accepted, (int) $row['decidedAt']));
                }
            }
        }

        foreach ($pending as $withdrawal) {
            $credited = $credited->plus(BigDecimal::max(BigDecimal::zero(), $withdrawal['credit']->minus($withdrawal['kept'])));
        }

        foreach ($refunded as $amount) {
            $credited = $credited->plus($amount);
        }

        /*
         * RK-4: a complaint counts from the decision on (L1) — a rescission
         * with its quantities like an accepted withdrawal, a price reduction
         * with its amounts and no quantity, since the goods stay with the
         * customer. Refunded, it counts with what was booked.
         */
        $complaints = $this->connection->fetchAllAssociative(
            "SELECT c.id, c.remedy, c.decidedAt, c.refundedAt, c.refundAmount, ci.orderItem, ci.rescindedQuantity, ci.reduction
               FROM tl_consho_complaint c
          LEFT JOIN tl_consho_complaint_item ci ON ci.pid = c.id
              WHERE c.pid = ? AND c.decidedAt IS NOT NULL AND c.remedy IN ('rescission', 'reduction') AND c.state <> 'rejected'",
            [$orderId],
        );

        $refundedComplaints = [];

        foreach ($complaints as $row) {
            $rescinded = BigDecimal::of((string) ($row['rescindedQuantity'] ?? '0'));
            $itemId = null === $row['orderItem'] ? null : (int) $row['orderItem'];

            if (null !== $itemId && $rescinded->isPositive()) {
                $withdrawn[$itemId] = (string) BigDecimal::of($withdrawn[$itemId] ?? '0')->plus($rescinded);
            }

            if (null !== $row['refundedAt']) {
                $refundedComplaints[(int) $row['id']] = BigDecimal::of((string) ($row['refundAmount'] ?? '0'));

                continue;
            }

            if (null === $itemId) {
                continue;
            }

            if ('reduction' === $row['remedy']) {
                $credited = $credited->plus((string) ($row['reduction'] ?? '0'));

                continue;
            }

            if ($rescinded->isPositive()) {
                if (null === $items) {
                    $items = $this->items($orderId);
                    $parts = DiscountShares::of($items);
                }

                $item = $items[$itemId] ?? null;

                if (null !== $item) {
                    $credited = $credited->plus($this->credit($orderId, $item, $parts, $rescinded, (int) $row['decidedAt']));
                }
            }
        }

        foreach ($refundedComplaints as $amount) {
            $credited = $credited->plus($amount);
        }

        return [(string) $credited, $withdrawn];
    }

    /**
     * What units going back credit: their share after the order's reductions,
     * less the price reductions decided before them that they take along
     * (RK-4, L3).
     *
     * @param array<string, string>                                                $item
     * @param array<int, list<array{line: int, net: BigDecimal, gross: BigDecimal}>> $parts
     */
    private function credit(int $orderId, array $item, array $parts, BigDecimal $quantity, int $at): BigDecimal
    {
        $itemId = (int) $item['id'];
        $credit = DiscountShares::credit($item, $parts[$itemId] ?? [], $quantity);
        $this->reductions ??= new ReductionShares($this->connection);

        return BigDecimal::max(BigDecimal::zero(), $credit->minus($this->reductions->taken($orderId, $itemId, $quantity, $at)));
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function items(int $orderId): array
    {
        $items = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_consho_order_item WHERE pid = ? ORDER BY id', [$orderId]) as $row) {
            $items[(int) $row['id']] = array_map(static fn ($v): string => (string) $v, $row);
        }

        return $items;
    }

    /**
     * The claim of an order row less what its accepted withdrawals credit —
     * never below zero.
     *
     * @param array<string, mixed> $order a row of tl_consho_order
     */
    public function claimOf(array $order): string
    {
        [$credited] = $this->of((int) ($order['id'] ?? 0));

        return (string) BigDecimal::max(BigDecimal::zero(), BigDecimal::of(OrderBalance::claimOfRow($order))->minus($credited));
    }
}

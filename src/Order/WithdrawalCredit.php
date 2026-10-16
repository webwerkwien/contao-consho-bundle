<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * What accepted withdrawals take off an order's claim, and how much of each
 * order item they withdrew (D-3, F7).
 *
 * ONE place for it, asked by everything that works out what an order still
 * owes: the balance (OrderBalanceReader), the start of an online payment
 * (PaymentStarter) and the change of payment method (PaymentChange). The
 * Fable review of D-3 found the latter two still charging the full grand
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
class WithdrawalCredit
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{0: string, 1: array<int, string>} credited amount, order item id => accepted quantity
     */
    public function of(int $orderId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT w.id, w.refundedAt, w.refundAmount, w.valueCompensation, wi.orderItem, wi.acceptedQuantity, i.quantity, i.lineTotal
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
                    $pending[$id]['credit'] = $pending[$id]['credit']->plus(DiscountShares::credit($item, $parts[(int) $row['orderItem']] ?? [], $accepted));
                }
            }
        }

        foreach ($pending as $withdrawal) {
            $credited = $credited->plus(BigDecimal::max(BigDecimal::zero(), $withdrawal['credit']->minus($withdrawal['kept'])));
        }

        foreach ($refunded as $amount) {
            $credited = $credited->plus($amount);
        }

        return [(string) $credited, $withdrawn];
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

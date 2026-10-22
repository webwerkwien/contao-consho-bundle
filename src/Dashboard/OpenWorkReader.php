<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Dashboard;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Contracts\Service\ResetInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineType;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalSignals;

/**
 * "Zu tun" on the dashboard (DB3): what is open, per shop.
 *
 * ⚠️ The orders are judged by OrderBalanceReader, the same reader behind the
 * signals of the order list. A formula of its own would sooner or later name
 * a different number than the list shows — withdrawals lower the claim, a
 * status can cancel, a digital release counts as a delivery. SQL only narrows
 * the candidates, and it may return too many, never too few: an order is left
 * out if it is cancelled, or if it is marked settled AND paid in full AND
 * every line of goods is reported delivered in full.
 *
 * The same sets feed the filtered lists behind the links (DB4), so the number
 * on the tile and the rows behind it come from one place.
 */
final class OpenWorkReader implements ResetInterface
{
    public const UNPAID = 'unpaid';

    public const OVERDUE = 'overdue';

    public const TO_DELIVER = 'toDeliver';

    public const COMPLAINT = 'complaint';

    public const UNDECIDED = 'undecided';

    public const REFUND_OPEN = 'refundOpen';

    // The shop of a withdrawal: its order's, or — not assigned yet (WA) —
    // the one it came in through. Withdrawals from before WA have shop 0
    // and always an order.
    private const WITHDRAWAL_SHOP = 'CASE WHEN w.pid = 0 THEN w.shop ELSE o.shop END';

    /** @var array<int, array{shop: int, dueAt: int, unpaid: bool, toDeliver: bool}>|null */
    private array|null $orders = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
    ) {
    }

    /**
     * @return array<int, OpenWork> keyed by shop id, every shop present
     */
    public function byShop(int $now): array
    {
        $work = [];

        foreach ($this->connection->fetchFirstColumn('SELECT id FROM tl_consho_shop ORDER BY id') as $shop) {
            $work[(int) $shop] = new OpenWork();
        }

        foreach ($this->orders() as $order) {
            $shop = $work[$order['shop']] ?? null;

            if (null === $shop) {
                continue;
            }

            if ($order['unpaid']) {
                ++$shop->unpaid;

                if ($order['dueAt'] > 0 && $order['dueAt'] < $now) {
                    ++$shop->overdue;
                }
            }

            if ($order['toDeliver']) {
                ++$shop->toDeliver;
            }
        }

        // WA (A5): a withdrawal without an order yet counts for the shop it
        // came in through — undecided, like any other just received.
        foreach ($this->connection->fetchAllAssociative(
            'SELECT '.self::WITHDRAWAL_SHOP.' AS shop, w.state, w.receivedAt FROM tl_consho_withdrawal w
               LEFT JOIN tl_consho_order o ON o.id = w.pid
              WHERE (o.id IS NOT NULL OR w.pid = 0)
                AND (w.state = :received OR (w.state = :accepted AND w.refundedAt IS NULL))',
            ['received' => 'received', 'accepted' => 'accepted'],
        ) as $row) {
            $shop = $work[(int) $row['shop']] ?? null;

            if (null === $shop) {
                continue;
            }

            // The refund is due 14 days after receipt, decided or not.
            $due = WithdrawalSignals::refundDue((int) $row['receivedAt']);

            if ('received' === $row['state']) {
                ++$shop->undecided;
                $shop->undecidedDue = 0 === $shop->undecidedDue ? $due : min($shop->undecidedDue, $due);
            } else {
                ++$shop->refundOpen;
                $shop->refundDue = 0 === $shop->refundDue ? $due : min($shop->refundDue, $due);
            }
        }

        foreach ($this->connection->fetchAllKeyValue(
            'SELECT o.shop, COUNT(*) FROM tl_consho_complaint c
               INNER JOIN tl_consho_order o ON o.id = c.pid
              WHERE c.resolvedAt IS NULL OR c.resolvedAt = 0
              GROUP BY o.shop',
        ) as $shop => $count) {
            if (isset($work[(int) $shop])) {
                $work[(int) $shop]->complaints = (int) $count;
            }
        }

        return $work;
    }

    /**
     * The orders are kept for the request; a long-running worker must not show
     * the last request's numbers (review A5).
     */
    public function reset(): void
    {
        $this->orders = null;
    }

    /**
     * The ids behind a tile, for the filtered list (DB4).
     *
     * @param int $shop 0 for every shop
     *
     * @return list<int> order ids, withdrawal ids for the two withdrawal filters
     */
    public function ids(string $filter, int $now, int $shop = 0): array
    {
        return match ($filter) {
            self::UNPAID => $this->orderIds($shop, static fn (array $o): bool => $o['unpaid']),
            self::OVERDUE => $this->orderIds($shop, static fn (array $o): bool => $o['unpaid'] && $o['dueAt'] > 0 && $o['dueAt'] < $now),
            self::TO_DELIVER => $this->orderIds($shop, static fn (array $o): bool => $o['toDeliver']),
            self::COMPLAINT => $this->column(
                'SELECT DISTINCT c.pid FROM tl_consho_complaint c INNER JOIN tl_consho_order o ON o.id = c.pid
                  WHERE (c.resolvedAt IS NULL OR c.resolvedAt = 0)',
                [],
                $shop,
            ),
            self::UNDECIDED => $this->column(
                'SELECT w.id FROM tl_consho_withdrawal w LEFT JOIN tl_consho_order o ON o.id = w.pid WHERE (o.id IS NOT NULL OR w.pid = 0) AND w.state = :state',
                ['state' => 'received'],
                $shop,
                self::WITHDRAWAL_SHOP,
            ),
            self::REFUND_OPEN => $this->column(
                'SELECT w.id FROM tl_consho_withdrawal w INNER JOIN tl_consho_order o ON o.id = w.pid WHERE w.state = :state AND w.refundedAt IS NULL',
                ['state' => 'accepted'],
                $shop,
            ),
            default => [],
        };
    }

    /**
     * @param array<string, string|int> $params
     *
     * @return list<int>
     */
    private function column(string $sql, array $params, int $shop, string $shopColumn = 'o.shop'): array
    {
        $types = [];

        if ($shop > 0) {
            $sql .= ' AND '.$shopColumn.' = :shop';
            $params['shop'] = $shop;
            // Bound as text, a CASE expression (no column affinity) never equals it.
            $types['shop'] = ParameterType::INTEGER;
        }

        $ids = array_map(intval(...), $this->connection->fetchFirstColumn($sql, $params, $types));
        sort($ids);

        return $ids;
    }

    /**
     * @param \Closure(array{shop: int, dueAt: int, unpaid: bool, toDeliver: bool}): bool $keep
     *
     * @return list<int>
     */
    private function orderIds(int $shop, \Closure $keep): array
    {
        return array_keys(array_filter(
            $this->orders(),
            static fn (array $o): bool => (0 === $shop || $o['shop'] === $shop) && $keep($o),
        ));
    }

    /**
     * @return array<int, array{shop: int, dueAt: int, unpaid: bool, toDeliver: bool}>
     */
    private function orders(): array
    {
        if (null !== $this->orders) {
            return $this->orders;
        }

        $candidates = $this->connection->fetchAllAssociative(
            "SELECT o.id, o.shop, o.paymentDueAt FROM tl_consho_order o
              WHERE o.cancelledAt IS NULL
                AND (o.paymentSettledAt IS NULL
                     OR o.grandTotal > (SELECT COALESCE(SUM(p.amount), 0) FROM tl_consho_payment p WHERE p.pid = o.id)
                     OR EXISTS (
                        SELECT 1 FROM tl_consho_order_item i
                         WHERE i.pid = o.id AND i.lineType NOT IN (:notGoods)
                           AND i.quantity > (SELECT COALESCE(SUM(di.quantity), 0)
                                               FROM tl_consho_delivery_item di
                                              INNER JOIN tl_consho_delivery d ON d.id = di.pid
                                              WHERE di.orderItem = i.id AND d.reportedAt IS NOT NULL)))
              ORDER BY o.id",
            // Mirrors OrderLineType::stored(): an empty or unknown type counts
            // as goods there, so it must not drop out here (review A2).
            ['notGoods' => array_values(array_map(
                static fn (OrderLineType $type): string => $type->value,
                array_filter(OrderLineType::cases(), static fn (OrderLineType $type): bool => !$type->isGoods()),
            ))],
            ['notGoods' => ArrayParameterType::STRING],
        );

        $orders = [];

        foreach ($candidates as $row) {
            $balance = $this->balances->of((int) $row['id']);

            if (null === $balance || $balance->cancelled) {
                continue;
            }

            $dueAt = (int) $row['paymentDueAt'];
            $unpaid = \in_array($balance->paymentState, [PaymentState::Open, PaymentState::Partial], true);
            $settled = \in_array($balance->paymentState, [PaymentState::Paid, PaymentState::Overpaid, PaymentState::NothingDue], true);

            // "Zu liefern" (spec Dashboard, 4): paid, or on account — there
            // the goods go before the money. Prepayment without payment is
            // counted as unpaid, not here.
            $toDeliver = DeliveryState::Complete !== $balance->deliveryState && ($settled || $dueAt > 0);

            if ($unpaid || $toDeliver) {
                $orders[(int) $row['id']] = ['shop' => (int) $row['shop'], 'dueAt' => $dueAt, 'unpaid' => $unpaid, 'toDeliver' => $toDeliver];
            }
        }

        return $this->orders = $orders;
    }
}

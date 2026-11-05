<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * What a delivery not reported yet can carry, and what it carries (D-1).
 *
 * Available is what is still open of the order's PHYSICAL goods — digital
 * lines are released, not packed (F-4, F17) — less what the order's OTHER
 * unreported deliveries already carry. Only reported deliveries count in
 * the balance, so without that subtraction a second draft would be offered
 * the same quantities and the first report would refuse "more than open".
 */
final class DeliveryContents
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
    ) {
    }

    /**
     * @return array<int, string> order item id => quantity, positive only
     */
    public function available(int $orderId, int $deliveryId): array
    {
        $balance = $orderId > 0 ? $this->balances->of($orderId) : null;

        if (null === $balance) {
            return [];
        }

        $digital = array_map(intval(...), $this->connection->fetchFirstColumn("SELECT id FROM tl_consho_order_item WHERE pid = ? AND lineType = 'product' AND kind <> 'physical'", [$orderId]));

        $planned = array_map(strval(...), $this->connection->fetchAllKeyValue(
            'SELECT di.orderItem, SUM(di.quantity) FROM tl_consho_delivery_item di
             INNER JOIN tl_consho_delivery d ON d.id = di.pid
             WHERE d.pid = ? AND d.reportedAt IS NULL AND d.id <> ?
             GROUP BY di.orderItem',
            [$orderId, $deliveryId],
        ));

        $available = [];

        foreach ($balance->openGoods as $itemId => $quantity) {
            if (\in_array($itemId, $digital, true)) {
                continue;
            }

            $left = BigDecimal::of($quantity)->minus($planned[$itemId] ?? '0');

            if ($left->isPositive()) {
                $available[$itemId] = (string) $left;
            }
        }

        return $available;
    }

    /**
     * Whether a parcel can still concern this order: physical goods are still
     * owed, or one already left — then a replacement may have to follow.
     * False for an order withdrawn or cancelled before shipping, and for one
     * without physical goods.
     */
    public function concernsParcels(int $orderId): bool
    {
        $balance = $orderId > 0 ? $this->balances->of($orderId) : null;

        if (null === $balance) {
            return false;
        }

        if ([] !== $balance->openGoods && [] !== $this->connection->fetchFirstColumn(
            "SELECT id FROM tl_consho_order_item WHERE pid = ? AND lineType = 'product' AND kind = 'physical' AND id IN (?)",
            [$orderId, array_keys($balance->openGoods)],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        )) {
            return true;
        }

        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM tl_consho_delivery_item di
             INNER JOIN tl_consho_delivery d ON d.id = di.pid
             INNER JOIN tl_consho_order_item i ON i.id = di.orderItem
             WHERE d.pid = ? AND d.reportedAt IS NOT NULL AND i.kind = 'physical' AND di.quantity > 0",
            [$orderId],
        );
    }

    /**
     * Where each physical good of the order went — for the mask, when nothing
     * is left: "nothing open" alone reads as "everything shipped", while the
     * goods may have been withdrawn before shipping, or sit in another draft.
     *
     * @return array{cancelled: bool, items: list<array{item: int, ordered: string, shipped: string, withdrawn: string, planned: string}>}
     */
    public function account(int $orderId, int $deliveryId): array
    {
        $balance = $orderId > 0 ? $this->balances->of($orderId) : null;
        [, $withdrawn] = (new WithdrawalCredit($this->connection))->of($orderId);

        $shipped = array_map(strval(...), $this->connection->fetchAllKeyValue(
            'SELECT di.orderItem, SUM(di.quantity) FROM tl_consho_delivery_item di
             INNER JOIN tl_consho_delivery d ON d.id = di.pid
             WHERE d.pid = ? AND d.reportedAt IS NOT NULL
             GROUP BY di.orderItem',
            [$orderId],
        ));

        $planned = array_map(strval(...), $this->connection->fetchAllKeyValue(
            'SELECT di.orderItem, SUM(di.quantity) FROM tl_consho_delivery_item di
             INNER JOIN tl_consho_delivery d ON d.id = di.pid
             WHERE d.pid = ? AND d.reportedAt IS NULL AND d.id <> ?
             GROUP BY di.orderItem',
            [$orderId, $deliveryId],
        ));

        $items = [];

        foreach ($this->connection->fetchAllAssociative("SELECT id, quantity FROM tl_consho_order_item WHERE pid = ? AND lineType = 'product' AND kind = 'physical' ORDER BY sorting, id", [$orderId]) as $row) {
            $id = (int) $row['id'];
            $items[] = [
                'item' => $id,
                'ordered' => (string) $row['quantity'],
                'shipped' => $shipped[$id] ?? '0',
                'withdrawn' => $withdrawn[$id] ?? '0',
                'planned' => $planned[$id] ?? '0',
            ];
        }

        return ['cancelled' => null !== $balance && $balance->cancelled, 'items' => $items];
    }

    /**
     * @return array<int, string> order item id => quantity, positive only
     */
    public function current(int $deliveryId): array
    {
        $items = [];

        foreach ($this->connection->fetchAllAssociative('SELECT orderItem, quantity FROM tl_consho_delivery_item WHERE pid = ?', [$deliveryId]) as $row) {
            if (BigDecimal::of((string) $row['quantity'])->isPositive()) {
                $item = (int) $row['orderItem'];
                $items[$item] = (string) BigDecimal::of($items[$item] ?? '0')->plus((string) $row['quantity']);
            }
        }

        return $items;
    }

    /**
     * Whether two sets of quantities are the same, as numbers ("1" = "1.0000").
     *
     * @param array<int, string> $a
     * @param array<int, string> $b
     */
    public static function same(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        if (array_keys($a) !== array_keys($b)) {
            return false;
        }

        foreach ($a as $item => $quantity) {
            if (!BigDecimal::of($quantity)->isEqualTo($b[$item])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The delivery's items become exactly these; quantities of 0 leave no row.
     *
     * @param array<int, string> $items
     */
    public function replace(int $deliveryId, array $items, int $now): void
    {
        $this->connection->delete('tl_consho_delivery_item', ['pid' => $deliveryId]);

        foreach ($items as $itemId => $quantity) {
            if (!BigDecimal::of($quantity)->isPositive()) {
                continue;
            }

            $this->connection->insert('tl_consho_delivery_item', [
                'pid' => $deliveryId,
                'tstamp' => $now,
                'orderItem' => $itemId,
                'quantity' => (string) $quantity,
            ]);
        }
    }
}

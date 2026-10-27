<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * Reads the two balances of an order (spec D 5.1).
 *
 * The one place that answers "what is still open" — the order list, the
 * order page and the status check all ask here. Until D-1 the list and the
 * page each counted on their own, and the list counted shipping and payment
 * lines as goods (C-10): every order with shipping would have stayed
 * "partly shipped" forever once deliveries existed.
 *
 * ── What counts ──────────────────────────────────────────────────────────
 *
 *  - The claim is the grand total, or 0 once the order is cancelled — by its
 *    status or, sticky, by cancelledAt — less what accepted withdrawals
 *    credit (D-3).
 *  - Only goods are shipped. Shipping, fees and discounts are lines on the
 *    invoice, and no parcel ever carries them.
 *  - Only REPORTED deliveries count. A delivery the operator is still filling
 *    in has left nothing (D-1, "Als versandt melden").
 */
final class OrderBalanceReader implements OrderBalanceReaderInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentStateCalculator $payments,
        private readonly DeliveryStateCalculator $deliveries,
    ) {
    }

    public function of(int $orderId, int|null $asStatus = null): OrderBalance|null
    {
        $order = $this->connection->fetchAssociative(
            'SELECT grandTotal, orderStatus, cancelledAt FROM tl_consho_order WHERE id = ?',
            [$orderId],
        );

        if (false === $order) {
            return null;
        }

        $status = $asStatus ?? (int) $order['orderStatus'];

        // Once cancelled, always cancelled: the status after "Storniert" does
        // not bring the claim back (tl_consho_order.cancelledAt).
        $cancelled = null !== $order['cancelledAt'] || ($status > 0 && false !== $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_order_status WHERE id = ? AND isCancelled = 1',
            [$status],
        ));

        [$credited, $withdrawn] = (new WithdrawalCredit($this->connection))->of($orderId);

        /*
         * Accepted withdrawals lower the claim (D-3, F7): by the goods' share
         * of their line totals from the moment of acceptance, by the amount
         * actually refunded once booked (goods plus the delivery share the
         * operator chose). Never below zero — a refund larger than the claim
         * is a payment balance, not a negative claim.
         */
        $claim = $cancelled ? '0' : (string) BigDecimal::max(BigDecimal::zero(), BigDecimal::of((string) $order['grandTotal'])->minus($credited));

        $amounts = array_map(
            strval(...),
            $this->connection->fetchFirstColumn('SELECT amount FROM tl_consho_payment WHERE pid = ?', [$orderId]),
        );

        $paid = BigDecimal::zero();

        foreach ($amounts as $amount) {
            $paid = $paid->plus($amount);
        }

        $ordered = [];
        $performed = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, quantity, lineType, performedAt FROM tl_consho_order_item WHERE pid = ?', [$orderId]) as $row) {
            if (OrderLineType::stored((string) $row['lineType'])->isGoods()) {
                $ordered[(int) $row['id']] = (string) $row['quantity'];

                if ((int) $row['performedAt'] > 0) {
                    $performed[(int) $row['id']] = (string) $row['quantity'];
                }
            }
        }

        $delivered = array_map(strval(...), $this->connection->fetchAllKeyValue(
            'SELECT di.orderItem, SUM(di.quantity) FROM tl_consho_delivery_item di
             INNER JOIN tl_consho_delivery d ON d.id = di.pid
             WHERE d.pid = ? AND d.reportedAt IS NOT NULL
             GROUP BY di.orderItem',
            [$orderId],
        ));

        $anyShipped = false;

        foreach ($delivered as $quantity) {
            if (BigDecimal::of($quantity)->isPositive()) {
                $anyShipped = true;
            }
        }

        /*
         * A service marked "Leistung erbracht" is delivered in full (A4) — it never goes into a parcel, so
         * without this it stayed "Lieferung: offen" in the list and "Zu liefern"
         * on the dashboard forever. After `anyShipped` on purpose: that one
         * means a parcel left, and a performed service is not one.
         */
        foreach ($performed as $itemId => $quantity) {
            $delivered[$itemId] = $quantity;
        }

        /*
         * A cancelled order has nothing left to ship. It can only be
         * cancelled while nothing has shipped (A1a), so nothing that left is
         * hidden by this.
         */
        $owed = [];

        // Goods withdrawn and accepted are no longer owed (D-3): withdrawn
        // before shipping, they will not be sent.
        foreach ($cancelled ? [] : $ordered as $itemId => $quantity) {
            $rest = BigDecimal::of($quantity)->minus($withdrawn[$itemId] ?? '0');
            $owed[$itemId] = (string) BigDecimal::max(BigDecimal::zero(), $rest);
        }

        $open = [];

        foreach ($owed as $itemId => $quantity) {
            $rest = BigDecimal::of($quantity)->minus($delivered[$itemId] ?? '0');

            if ($rest->isPositive()) {
                $open[$itemId] = (string) $rest;
            }
        }

        return new OrderBalance(
            $claim,
            (string) $paid,
            $this->payments->calculate($claim, $amounts),
            $this->deliveries->calculate($owed, $delivered),
            $open,
            $anyShipped,
            $cancelled,
        );
    }
}

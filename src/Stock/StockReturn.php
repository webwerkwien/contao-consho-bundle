<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;

/**
 * Goods back into stock (Block G, G9): a cancelled order, a returned
 * withdrawal line. Never more than the purchase click took for that line
 * (tl_consho_order_item.stockBooked), plus what replacement deliveries took
 * (tl_consho_delivery_item.stockBooked) — an order from before stock was kept
 * gives nothing back, and the two ways back (stockCancelled, restockedQuantity)
 * cannot add up to more than went out.
 *
 * The row is found by article whether or not it is still active: goods that
 * come back after stock keeping was switched off still belong to that stock's
 * history.
 */
class StockReturn
{
    public function __construct(
        private readonly Connection $connection,
        private readonly StockBooking $booking,
        /*
         * A cancelled order frees the codes it was waiting for (G10), and no
         * booking says so — the product page would stay cached as "derzeit
         * nicht verfügbar". Optional for the tests.
         */
        private readonly CatalogCacheTags|null $cacheTags = null,
        // Block Verfügbarkeit, V1: freed codes leave "Nur verfügbare" too.
        // Optional for the tests.
        private readonly SoldOutMarks|null $soldOut = null,
    ) {
    }

    /**
     * Once, from where `cancelledAt` is set (ContaoOrderStatusWriter,
     * OrderStatusListener) — that mark is set only once. Gives back what the
     * line took, less what a return already brought back and what an earlier
     * call gave; the amount goes to stockCancelled (G). What replacement
     * deliveries took for the line counts as taken (RK-5), as at a return —
     * otherwise a return of a replaced piece would shrink what the
     * cancellation gives back.
     *
     * $user: the backend user who set the cancelling status; 0 when Consho
     * did (a payment provider's cancellation) — G-8.
     */
    public function afterCancellation(int $orderId, int $now, int $user = 0): void
    {
        $lines = $this->connection->fetchAllAssociative(
            "SELECT i.id, i.product, i.stockBooked, i.stockCancelled,
                    (SELECT COALESCE(SUM(d.stockBooked), 0) FROM tl_consho_delivery_item d WHERE d.orderItem = i.id) AS replaced,
                    (SELECT COALESCE(SUM(w.restockedQuantity), 0) FROM tl_consho_withdrawal_item w WHERE w.orderItem = i.id)
                    + (SELECT COALESCE(SUM(c.restockedQuantity), 0) FROM tl_consho_complaint_item c WHERE c.orderItem = i.id) AS restocked
               FROM tl_consho_order_item i
              WHERE i.pid = ? AND i.lineType = 'product' AND i.stockBooked > 0",
            [$orderId],
        );

        foreach ($lines as $line) {
            $back = BigDecimal::of(StockBooking::scaled($line['stockBooked']))
                ->plus(BigDecimal::of(StockBooking::scaled($line['replaced'])))
                ->minus(BigDecimal::of(StockBooking::scaled($line['restocked'])))
                ->minus(BigDecimal::of(StockBooking::scaled($line['stockCancelled'])));
            $stockId = $this->stockOf((int) $line['product']);

            if (null === $stockId || !$back->isPositive()) {
                continue;
            }

            $this->booking->book($stockId, $back, StockReason::Cancellation, $now, $user, $orderId);
            $this->connection->update('tl_consho_order_item', [
                'stockCancelled' => (string) BigDecimal::of(StockBooking::scaled($line['stockCancelled']))->plus($back)->toScale(4),
            ], ['id' => (int) $line['id']]);
        }

        $this->keysFreed($orderId);
    }

    /**
     * Codes an order no longer waits for — cancelled, or withdrawn before
     * delivery (G). No booking says so, and the product page
     * would stay cached as "derzeit nicht verfügbar" while the notice cron
     * already mails "wieder lieferbar".
     */
    public function keysFreed(int $orderId): void
    {
        if (null === $this->cacheTags && null === $this->soldOut) {
            return;
        }

        $articles = array_map(intval(...), $this->connection->fetchFirstColumn(
            "SELECT DISTINCT i.product FROM tl_consho_order_item i
               JOIN tl_consho_stock s ON s.article = i.product AND s.active = 1 AND s.source = 'keys'
              WHERE i.pid = ? AND i.lineType = 'product'",
            [$orderId],
        ));

        $this->soldOut?->refresh($articles);

        foreach ($articles as $article) {
            $this->cacheTags?->invalidate($this->cacheTags->forProduct($article));
        }
    }

    /**
     * A withdrawal line's return (G9): what should be back in stock by now —
     * the returned quantity when "ins Lager" is ticked, else nothing, never
     * more than the line took — less what already is. A corrected quantity
     * books only the difference, and so does a tick taken away.
     *
     * @return string the quantity now restocked for this withdrawal line
     */
    public function forWithdrawalItem(int $withdrawalItemId, bool $restock, int $now, int $user = 0): string
    {
        return $this->forReturnLine('tl_consho_withdrawal_item', $withdrawalItemId, $restock, $now, $user);
    }

    /**
     * The same for a complaint line's return (RK-4, R6): defective goods go
     * back into stock only when the operator ticks it.
     *
     * @return string the quantity now restocked for this complaint line
     */
    public function forComplaintItem(int $complaintItemId, bool $restock, int $now, int $user = 0): string
    {
        return $this->forReturnLine('tl_consho_complaint_item', $complaintItemId, $restock, $now, $user);
    }

    private function forReturnLine(string $table, int $rowId, bool $restock, int $now, int $user): string
    {
        $row = $this->connection->fetchAssociative(
            "SELECT r.pid, r.orderItem, r.returnedQuantity, r.restockedQuantity, i.pid AS orderId, i.product, i.stockBooked, i.stockCancelled
               FROM $table r
               JOIN tl_consho_order_item i ON i.id = r.orderItem
              WHERE r.id = ?",
            [$rowId],
        );

        if (false === $row) {
            return '0.0000';
        }

        $already = BigDecimal::of(StockBooking::scaled($row['restockedQuantity']));
        $returned = BigDecimal::of(StockBooking::scaled($row['returnedQuantity']));
        // What the cancellation gave back is no longer the return's to give
        // (G): before it the cap is what the line took,
        // after it what the return held back then. An untick after the
        // cancellation still takes the damaged piece out again.
        //
        // RK-4 (spec 3.4): one cap for every return of the order item, the
        // withdrawals' and the complaints' together — what the others
        // brought back is no longer this one's to give.
        //
        // RK-5 (spec 3.4): a replacement delivery took another piece out, so
        // one more may come back.
        $cap = BigDecimal::of(StockBooking::scaled($row['stockBooked']))
            ->plus(StockBooking::scaled($this->connection->fetchOne(
                'SELECT COALESCE(SUM(stockBooked), 0) FROM tl_consho_delivery_item WHERE orderItem = ?',
                [(int) $row['orderItem']],
            )))
            ->minus(BigDecimal::of(StockBooking::scaled($row['stockCancelled'])))
            ->minus($this->restockedByOthers((int) $row['orderItem'], $table, $rowId));
        $target = $restock ? BigDecimal::max(BigDecimal::zero(), BigDecimal::min($returned, $cap)) : BigDecimal::zero();
        $delta = $target->minus($already);
        $stockId = $this->stockOf((int) $row['product']);

        if (null === $stockId || $delta->isZero()) {
            $this->connection->update($table, ['restock' => $restock ? 1 : 0], ['id' => $rowId]);

            return (string) $already->toScale(4);
        }

        // The movement names the withdrawal or the complaint it belongs to.
        $withdrawalId = 'tl_consho_withdrawal_item' === $table ? (int) $row['pid'] : 0;
        $complaintId = 'tl_consho_complaint_item' === $table ? (int) $row['pid'] : 0;

        $this->booking->book($stockId, $delta, StockReason::Return, $now, $user, (int) $row['orderId'], $withdrawalId, complaintId: $complaintId);
        $this->connection->update($table, ['restockedQuantity' => (string) $target->toScale(4), 'restock' => $restock ? 1 : 0], ['id' => $rowId]);

        return (string) $target->toScale(4);
    }

    /**
     * What the other return lines of an order item brought back into stock.
     */
    private function restockedByOthers(int $orderItem, string $table, int $rowId): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach (['tl_consho_withdrawal_item', 'tl_consho_complaint_item'] as $other) {
            $self = $other === $table ? $rowId : 0;
            $sum = $sum->plus(StockBooking::scaled($this->connection->fetchOne(
                "SELECT COALESCE(SUM(restockedQuantity), 0) FROM $other WHERE orderItem = ? AND id <> ?",
                [$orderItem, $self],
            )));
        }

        return $sum;
    }

    private function stockOf(int $article): int|null
    {
        $id = $this->connection->fetchOne('SELECT id FROM tl_consho_stock WHERE article = ?', [$article]);

        return false === $id ? null : (int) $id;
    }
}

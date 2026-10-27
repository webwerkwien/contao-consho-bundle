<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;

/**
 * Goods back into stock (Block G, G9): a cancelled order, a returned
 * withdrawal line. Never more than the purchase click took for that line
 * (tl_consho_order_item.stockBooked) — an order from before stock was kept
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
     * call gave; the amount goes to stockCancelled (G).
     *
     * $user: the backend user who set the cancelling status; 0 when Consho
     * did (a payment provider's cancellation) — G-8.
     */
    public function afterCancellation(int $orderId, int $now, int $user = 0): void
    {
        $lines = $this->connection->fetchAllAssociative(
            "SELECT i.id, i.product, i.stockBooked, i.stockCancelled,
                    (SELECT COALESCE(SUM(w.restockedQuantity), 0) FROM tl_consho_withdrawal_item w WHERE w.orderItem = i.id) AS restocked
               FROM tl_consho_order_item i
              WHERE i.pid = ? AND i.lineType = 'product' AND i.stockBooked > 0",
            [$orderId],
        );

        foreach ($lines as $line) {
            $back = BigDecimal::of(StockBooking::scaled($line['stockBooked']))
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
        $row = $this->connection->fetchAssociative(
            'SELECT w.pid, w.returnedQuantity, w.restockedQuantity, i.pid AS orderId, i.product, i.stockBooked, i.stockCancelled
               FROM tl_consho_withdrawal_item w
               JOIN tl_consho_order_item i ON i.id = w.orderItem
              WHERE w.id = ?',
            [$withdrawalItemId],
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
        $cap = BigDecimal::of(StockBooking::scaled($row['stockBooked']))->minus(BigDecimal::of(StockBooking::scaled($row['stockCancelled'])));
        $target = $restock ? BigDecimal::min($returned, $cap) : BigDecimal::zero();
        $delta = $target->minus($already);
        $stockId = $this->stockOf((int) $row['product']);

        if (null === $stockId || $delta->isZero()) {
            $this->connection->update('tl_consho_withdrawal_item', ['restock' => $restock ? 1 : 0], ['id' => $withdrawalItemId]);

            return (string) $already->toScale(4);
        }

        $this->booking->book($stockId, $delta, StockReason::Return, $now, $user, (int) $row['orderId'], (int) $row['pid']);
        $this->connection->update('tl_consho_withdrawal_item', ['restockedQuantity' => (string) $target->toScale(4), 'restock' => $restock ? 1 : 0], ['id' => $withdrawalItemId]);

        return (string) $target->toScale(4);
    }

    private function stockOf(int $article): int|null
    {
        $id = $this->connection->fetchOne('SELECT id FROM tl_consho_stock WHERE article = ?', [$article]);

        return false === $id ? null : (int) $id;
    }
}

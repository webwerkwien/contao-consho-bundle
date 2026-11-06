<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Order\StockShortAtPurchase;

/**
 * Books an order's goods out of stock at the purchase click (Block G, G4,
 * G10) — inside OrderPlacer's transaction, right after the lines are written.
 *
 * ── Order of the locks ───────────────────────────────────────────────────
 *
 * All stock rows of the order are locked in ONE statement, ordered by id.
 * Two purchases that share two articles then always lock them in the same
 * order, and neither can hold one while waiting for the other's.
 *
 * ── Kept stock: the condition protects the last piece ────────────────────
 *
 * Not the lock: StockBooking::take()'s conditional UPDATE. The lock keeps the
 * figures of one order consistent; the condition is what refuses the second
 * buyer — and is what the tests (SQLite, no FOR UPDATE) can prove.
 *
 * ── Key pools: the lock is the guard ─────────────────────────────────────
 *
 * Nothing is booked: the code is handed out at the release (DigitalRelease).
 * What is checked is the free codes less everything ordered before — with the
 * row locked, a second purchase of the last code waits here. Then it counts
 * again — and sees the first purchase only because OrderPlacer runs the
 * purchase in READ COMMITTED: under MySQL's REPEATABLE READ the count would
 * read the snapshot from before the wait (G). "Lizenz verlängern"
 * lines take no code (Abo-7b).
 *
 * Short anywhere: StockShortAtPurchase, and the caller's transaction rolls
 * back every booking made before it, the order with them.
 */
class StockAllocation
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Availability $availability,
        private readonly StockBooking $booking,
        // A key pool's state changes with a purchase, and no booking says so.
        // Optional for the tests.
        private readonly CatalogCacheTags|null $cacheTags = null,
        // Block Verfügbarkeit, V1: the last code sold. Optional for the tests.
        private readonly SoldOutMarks|null $soldOut = null,
    ) {
    }

    /**
     * @throws StockShortAtPurchase
     */
    public function bookOrder(int $orderId, int $now): void
    {
        // The sold-out marks once at the end, sorted — not line by line in
        // the order of the basket (SoldOutMarks::deferred()).
        if (null !== $this->soldOut) {
            $this->soldOut->deferred(fn () => $this->book($orderId, $now));

            return;
        }

        $this->book($orderId, $now);
    }

    /**
     * @throws StockShortAtPurchase
     */
    private function book(int $orderId, int $now): void
    {
        $lines = $this->connection->fetchAllAssociative(
            "SELECT id, product, quantity, renews FROM tl_consho_order_item WHERE pid = ? AND lineType = 'product' ORDER BY id",
            [$orderId],
        );

        $articles = array_values(array_unique(array_map(static fn (array $line): int => (int) $line['product'], $lines)));
        $availability = $this->availability->of($articles, $orderId);

        // Only what is limited; everything else is unlimited.
        $limited = array_values(array_filter($articles, static fn (int $article): bool => null !== ($availability[$article] ?? null)?->available));

        if ([] === $limited) {
            return;
        }

        $stockIds = [];
        $sources = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, article, source FROM tl_consho_stock WHERE article IN (?) AND active = 1 ORDER BY id FOR UPDATE',
            [$limited],
            [ArrayParameterType::INTEGER],
        ) as $row) {
            $stockIds[(int) $row['article']] = (int) $row['id'];
            $sources[(int) $row['article']] = (string) $row['source'];
        }

        // Key pools counted again under the lock: a purchase that waited for
        // it now sees the one before as pending.
        $keyArticles = array_keys(array_filter($sources, static fn (string $source): bool => 'keys' === $source));
        $keysNow = [] === $keyArticles ? [] : $this->availability->of($keyArticles, $orderId);
        $wantedKeys = [];

        foreach ($lines as $line) {
            $article = (int) $line['product'];
            $state = $availability[$article] ?? null;

            if (null === $state?->available) {
                continue;
            }

            $quantity = BigDecimal::of(StockBooking::scaled($line['quantity']));

            if ('keys' === ($sources[$article] ?? null)) {
                if (0 === (int) $line['renews']) {
                    $wantedKeys[$article] = ($wantedKeys[$article] ?? BigDecimal::zero())->plus($quantity);
                }

                continue;
            }

            if (!isset($stockIds[$article])) {
                // Tracked without a row (a fault, see Availability): only an
                // article sold without stock may go on, and books nothing.
                if ($state->backorder) {
                    continue;
                }

                throw StockShortAtPurchase::forArticle($article);
            }

            if (null === $this->booking->take($stockIds[$article], $quantity, $state->backorder, StockReason::Order, $now, $orderId)) {
                throw StockShortAtPurchase::forArticle($article);
            }

            $this->connection->update('tl_consho_order_item', ['stockBooked' => (string) $quantity->toScale(4)], ['id' => (int) $line['id']]);
        }

        foreach ($wantedKeys as $article => $quantity) {
            $pool = $keysNow[$article] ?? null;

            if (null === $pool || !$pool->covers((string) $quantity)) {
                throw StockShortAtPurchase::forArticle($article);
            }

            $left = BigDecimal::of((string) $pool->available);
            $this->invalidateOnStateChange($article, $left, $left->minus($quantity));
        }
    }

    /**
     * A replacement delivery's goods out of stock when it is reported (RK-5,
     * S4), by the purchase click's rule: an article that keeps stock goes
     * below zero only when it may be sold without stock. What was taken is
     * kept at the delivery line (stockBooked), so it may come back
     * (StockReturn). Inside the caller's transaction; on a refusal the caller
     * rolls back what was booked before it.
     *
     * @return list<int> the order items short of stock, empty when all went out
     */
    public function bookReplacement(int $orderId, int $deliveryId, int $now): array
    {
        $lines = $this->connection->fetchAllAssociative(
            'SELECT di.id, di.orderItem, di.quantity, i.product FROM tl_consho_delivery_item di
               JOIN tl_consho_order_item i ON i.id = di.orderItem
              WHERE di.pid = ? AND di.quantity > 0 ORDER BY di.id',
            [$deliveryId],
        );

        $articles = array_values(array_unique(array_map(static fn (array $line): int => (int) $line['product'], $lines)));
        $availability = [] === $articles ? [] : $this->availability->of($articles, $orderId);
        $limited = array_values(array_filter($articles, static fn (int $article): bool => null !== ($availability[$article] ?? null)?->available));

        if ([] === $limited) {
            return [];
        }

        $stockIds = $this->connection->fetchAllKeyValue(
            "SELECT article, id FROM tl_consho_stock WHERE article IN (?) AND active = 1 AND source <> 'keys' ORDER BY id FOR UPDATE",
            [$limited],
            [ArrayParameterType::INTEGER],
        );

        $short = [];
        // The movement names the complaint the replacement belongs to.
        $complaintId = (int) $this->connection->fetchOne('SELECT complaint FROM tl_consho_delivery WHERE id = ?', [$deliveryId]);

        foreach ($lines as $line) {
            $article = (int) $line['product'];
            $state = $availability[$article] ?? null;

            if (null === $state?->available) {
                continue;
            }

            $quantity = BigDecimal::of(StockBooking::scaled($line['quantity']));
            $stockId = $stockIds[$article] ?? null;

            if (null === $stockId) {
                if (!$state->backorder) {
                    $short[] = (int) $line['orderItem'];
                }

                continue;
            }

            if (null === $this->booking->take((int) $stockId, $quantity, $state->backorder, StockReason::Replacement, $now, $orderId, $complaintId)) {
                $short[] = (int) $line['orderItem'];

                continue;
            }

            $this->connection->update('tl_consho_delivery_item', ['stockBooked' => (string) $quantity->toScale(4)], ['id' => (int) $line['id']]);
        }

        return $short;
    }

    private function invalidateOnStateChange(int $article, BigDecimal $before, BigDecimal $after): void
    {
        if (null === $this->cacheTags && null === $this->soldOut) {
            return;
        }

        $warning = BigDecimal::of(StockBooking::scaled($this->connection->fetchOne(
            'SELECT m.stockWarning FROM tl_consho_stock s JOIN tl_consho_product m ON m.id = s.main WHERE s.article = ?',
            [$article],
        )));

        if (StockState::of($before, $warning, false) !== StockState::of($after, $warning, false)) {
            // Asked, not set: the order being placed is not pending for
            // Availability until it is written — it is, at this point
            // (OrderPlacer writes the lines before it books).
            $this->soldOut?->refresh([$article]);
            $this->cacheTags?->invalidate($this->cacheTags->forProduct($article));
        }
    }
}

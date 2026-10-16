<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;

/**
 * The only way a stock changes (Block G, G2): an atomic update and a movement
 * that explains it, in one transaction.
 *
 * Atomic means `quantity = quantity + ?` in the database — never a figure read
 * here, changed and written back. A purchase in between would otherwise be
 * overwritten: exactly the case G3 is about.
 *
 * Nested inside a caller's transaction (the purchase click, a cancellation)
 * this is a savepoint, and rolls back with it.
 */
class StockBooking
{
    public function __construct(
        private readonly Connection $connection,
        /*
         * The product page and the listings sit in the shared page cache, and
         * a purchase is not a back end save that would invalidate them. Only
         * when the state a visitor sees changes ("lieferbar" → "nur noch
         * wenige" → "derzeit nicht verfügbar"), so a busy article does not
         * empty the listing caches on every sale. Optional for the tests.
         */
        private readonly CatalogCacheTags|null $cacheTags = null,
        // The filter "Nur verfügbare" (block Verfügbarkeit, V1) — on the same
        // state change, the receipt of a purchase list included. Optional for
        // the tests.
        private readonly SoldOutMarks|null $soldOut = null,
    ) {
    }

    /**
     * @return string the quantity after the booking, four decimals
     */
    public function book(
        int $stockId,
        BigDecimal $delta,
        StockReason $reason,
        int $now,
        int $user = 0,
        int $orderId = 0,
        int $withdrawalId = 0,
        int $purchaseList = 0,
    ): string {
        return $this->connection->transactional(function () use ($stockId, $delta, $reason, $now, $user, $orderId, $withdrawalId, $purchaseList): string {
            $change = (string) $delta->toScale(4, RoundingMode::HalfUp);

            $this->connection->executeStatement(
                'UPDATE tl_consho_stock SET quantity = quantity + ?, tstamp = ? WHERE id = ?',
                [$change, $now, $stockId],
            );

            $after = self::scaled($this->connection->fetchOne('SELECT quantity FROM tl_consho_stock WHERE id = ?', [$stockId]));
            $this->invalidateOnStateChange($stockId, BigDecimal::of($after)->minus(BigDecimal::of($change)), BigDecimal::of($after));

            $this->connection->insert('tl_consho_stock_movement', [
                'pid' => $stockId,
                'tstamp' => $now,
                'createdAt' => $now,
                'delta' => $change,
                'quantityAfter' => $after,
                'reason' => $reason->value,
                'orderId' => $orderId,
                'withdrawalId' => $withdrawalId,
                'purchaseList' => $purchaseList,
                'user' => $user,
            ]);

            return $after;
        });
    }

    /**
     * Takes $quantity out of the stock if it is there (G4) — or regardless,
     * when the article sells without stock (G6).
     *
     * The condition sits IN the UPDATE: two purchases of the last piece both
     * run it, and the database lets only one of them match. A figure read
     * first and compared here would let both through. The tests run on
     * SQLite, which has no FOR UPDATE at all — this condition is what they
     * prove.
     *
     * @return string|null the quantity after, or NULL when not enough was there
     */
    public function take(int $stockId, BigDecimal $quantity, bool $allowNegative, StockReason $reason, int $now, int $orderId = 0): string|null
    {
        return $this->connection->transactional(function () use ($stockId, $quantity, $allowNegative, $reason, $now, $orderId): string|null {
            $amount = (string) $quantity->toScale(4, RoundingMode::HalfUp);

            // The condition is left out rather than switched by a parameter:
            // `1 = ?` bound as a string is false on SQLite and true on MySQL.
            $taken = $allowNegative
                ? $this->connection->executeStatement(
                    'UPDATE tl_consho_stock SET quantity = quantity - ?, tstamp = ? WHERE id = ?',
                    [$amount, $now, $stockId],
                )
                : $this->connection->executeStatement(
                    'UPDATE tl_consho_stock SET quantity = quantity - ?, tstamp = ? WHERE id = ? AND quantity >= ?',
                    [$amount, $now, $stockId, $amount],
                );

            // A positive amount always changes the row it matches, so MySQL's
            // "found but unchanged = 0" cannot occur here.
            if (0 === $taken) {
                return null;
            }

            $after = self::scaled($this->connection->fetchOne('SELECT quantity FROM tl_consho_stock WHERE id = ?', [$stockId]));
            $this->invalidateOnStateChange($stockId, BigDecimal::of($after)->plus(BigDecimal::of($amount)), BigDecimal::of($after));

            $this->connection->insert('tl_consho_stock_movement', [
                'pid' => $stockId,
                'tstamp' => $now,
                'createdAt' => $now,
                'delta' => (string) $quantity->negated()->toScale(4, RoundingMode::HalfUp),
                'quantityAfter' => $after,
                'reason' => $reason->value,
                'orderId' => $orderId,
                'withdrawalId' => 0,
                'user' => 0,
            ]);

            return $after;
        });
    }

    private function invalidateOnStateChange(int $stockId, BigDecimal $before, BigDecimal $after): void
    {
        if (null === $this->cacheTags && null === $this->soldOut) {
            return;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT s.article, m.stockWarning, m.stockBackorder
               FROM tl_consho_stock s
               JOIN tl_consho_product m ON m.id = s.main
              WHERE s.id = ?',
            [$stockId],
        );

        if (false === $row) {
            return;
        }

        $warning = BigDecimal::of(self::scaled($row['stockWarning']));
        $backorder = (bool) $row['stockBackorder'];

        if (StockState::of($before, $warning, $backorder) !== StockState::of($after, $warning, $backorder)) {
            $this->soldOut?->refresh([(int) $row['article']]);
            $this->cacheTags?->invalidate($this->cacheTags->forProduct((int) $row['article']));
        }
    }

    /**
     * Four decimals, whatever the driver hands back — MySQL "12.0000",
     * SQLite 12 or 12.5 (the trap of Prüfungsnotiz Fall 30).
     */
    public static function scaled(mixed $value): string
    {
        $text = \is_scalar($value) ? (string) $value : '0';

        return (string) BigDecimal::of('' === $text ? '0' : $text)->toScale(4, RoundingMode::HalfUp);
    }
}

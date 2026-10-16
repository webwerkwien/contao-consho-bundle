<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Brick\Math\BigDecimal;

/**
 * A booking by hand (G3) for any caller: the product mask, a console command.
 *
 * The caller states the change and who made it. Turning "entered" into a
 * change is the mask's business (stockOpened, StockFieldsListener); a command
 * has the change already. Neither reads the request here, so the console books
 * exactly what the back end books.
 */
class ManualStock
{
    public function __construct(
        private readonly StockRows $rows,
        private readonly StockBooking $booking,
    ) {
    }

    /**
     * @return string|null the quantity after, four decimals — or NULL when
     *                     the article keeps no stock by hand (not tracked,
     *                     a main product with variants, a key pool, unknown)
     */
    public function adjust(int $article, BigDecimal $delta, int $user, int $now): string|null
    {
        // The row may not exist yet: a product whose mask was never saved
        // since stock was switched on.
        $this->rows->sync($article, $now);
        $row = $this->rows->activeRow($article);

        if (null === $row) {
            return null;
        }

        if ($delta->isZero()) {
            return StockBooking::scaled($row['quantity']);
        }

        return $this->booking->book($row['id'], $delta, StockReason::Manual, $now, $user);
    }
}

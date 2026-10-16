<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Brick\Math\BigDecimal;

/**
 * The answer of Availability for one article.
 */
final class ArticleAvailability
{
    /**
     * @param string|null $available four decimals; NULL for unlimited
     * @param int|null    $availableFrom "wieder lieferbar ab" (G12)
     */
    public function __construct(
        public readonly StockState $state,
        public readonly string|null $available,
        public readonly bool $backorder = false,
        public readonly int|null $availableFrom = null,
    ) {
    }

    public static function unlimited(): self
    {
        return new self(StockState::Available, null);
    }

    /**
     * Whether $quantity can be sold now: unlimited, enough in stock, or sold
     * on order anyway.
     */
    public function covers(string $quantity): bool
    {
        return null === $this->available
            || $this->backorder
            || BigDecimal::of($this->available)->isGreaterThanOrEqualTo(BigDecimal::of($quantity));
    }
}

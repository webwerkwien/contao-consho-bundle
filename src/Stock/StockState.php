<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Brick\Math\BigDecimal;

/**
 * What a visitor is told about an article (Block G, G7).
 *
 * Never the number itself on the product page or a tile: "nur noch wenige"
 * says enough, and a figure there is a promise the next buyer may break.
 */
enum StockState: string
{
    /** Unlimited, or more than the threshold. */
    case Available = 'available';

    /** In stock, at or below the threshold (stockWarning). */
    case Few = 'few';

    /** Nothing left and no selling without stock: no purchase, the page stays. */
    case Unavailable = 'unavailable';

    /** Nothing left, sold on order (G6). */
    case Backorder = 'backorder';

    /**
     * The state of a kept stock — the one rule, read by Availability and by
     * StockBooking to tell whether a booking changed what the pages show.
     */
    public static function of(BigDecimal $quantity, BigDecimal $warning, bool $backorder): self
    {
        return match (true) {
            $quantity->isPositive() && $quantity->isLessThanOrEqualTo($warning) => self::Few,
            $quantity->isPositive() => self::Available,
            $backorder => self::Backorder,
            default => self::Unavailable,
        };
    }

    /**
     * The best of several — what a product with an open variant choice can
     * honestly claim: in stock as long as one variant is (JSON-LD
     * AggregateOffer). Nothing to compare: available.
     *
     * @param list<self> $states
     */
    public static function best(array $states): self
    {
        foreach ([self::Available, self::Few, self::Backorder, self::Unavailable] as $candidate) {
            if (\in_array($candidate, $states, true)) {
                return $candidate;
            }
        }

        return self::Available;
    }

    public function purchasable(): bool
    {
        return self::Unavailable !== $this;
    }

    /** schema.org/ItemAvailability (G7). */
    public function schemaOrg(): string
    {
        return match ($this) {
            self::Available => 'https://schema.org/InStock',
            self::Few => 'https://schema.org/LimitedAvailability',
            self::Unavailable => 'https://schema.org/OutOfStock',
            self::Backorder => 'https://schema.org/BackOrder',
        };
    }
}

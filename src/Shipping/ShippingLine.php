<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * One cart position as a shipping type sees it. Amounts are strings for
 * brick/math; `net` is the line total, not the unit price.
 *
 * Weight (kg) and measurements (cm) are PER PIECE, as the product carries
 * them (E-4, V1); '0.0000' where the catalogue knows nothing. A type that
 * sums weight multiplies by the quantity itself — totalWeight() does it.
 */
final class ShippingLine
{
    public function __construct(
        public readonly int $articleId,
        public readonly string $quantity,
        public readonly string $net,
        public readonly int $taxClassId,
        public readonly string $weight = '0.0000',
        public readonly string $length = '0.0000',
        public readonly string $width = '0.0000',
        public readonly string $height = '0.0000',
    ) {
    }

    /**
     * Weight of the whole position in kg, four places.
     */
    public function totalWeight(): string
    {
        return (string) BigDecimal::of($this->weight)->multipliedBy($this->quantity)->toScale(4, RoundingMode::HalfUp);
    }
}

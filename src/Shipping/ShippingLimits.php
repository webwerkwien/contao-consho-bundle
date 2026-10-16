<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

use Brick\Math\BigDecimal;

/**
 * What a shipping method will carry, per piece (E-5, V4): the longest side,
 * the girth (length + 2 × width + 2 × height, the longest side counted as
 * the length) and the weight. '0.0000' means no limit.
 *
 * A limit makes the method unavailable, it adds no surcharge: surcharges are
 * computed differently by every carrier and belong to a module. The limit
 * alone prevents the expensive case — a parcel the carrier will not take.
 * For bulky goods the operator creates a shipping method of its own.
 *
 * A product without measurements (0) fits: the catalogue's gap is reported at
 * the product, not turned into "cannot be shipped" at the checkout.
 */
final class ShippingLimits
{
    public function __construct(
        public readonly string $maxLength = '0.0000',
        public readonly string $maxGirth = '0.0000',
        public readonly string $maxPieceWeight = '0.0000',
    ) {
    }

    /**
     * @param list<ShippingLine> $lines
     */
    public function admitAll(array $lines): bool
    {
        foreach ($lines as $line) {
            if (!$this->admits($line)) {
                return false;
            }
        }

        return true;
    }

    public function admits(ShippingLine $line): bool
    {
        $sides = [BigDecimal::of($line->length), BigDecimal::of($line->width), BigDecimal::of($line->height)];
        usort($sides, static fn (BigDecimal $a, BigDecimal $b): int => $b->compareTo($a));

        [$longest, $second, $third] = $sides;

        if (self::exceeds($longest, $this->maxLength)) {
            return false;
        }

        // Girth as parcel services define it: the longest side is the length.
        // With no measurements at all it is 0 and fits.
        if (self::exceeds($longest->plus($second->multipliedBy(2))->plus($third->multipliedBy(2)), $this->maxGirth)) {
            return false;
        }

        return !self::exceeds(BigDecimal::of($line->weight), $this->maxPieceWeight);
    }

    private static function exceeds(BigDecimal $value, string $limit): bool
    {
        $max = BigDecimal::of($limit);

        return $max->isPositive() && $value->isGreaterThan($max);
    }
}

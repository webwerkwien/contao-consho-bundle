<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

use Brick\Math\BigDecimal;

/**
 * Gewichtsstaffel (E-4, V2): the price depends on the parcel's weight.
 *
 * The price rows carry an upper limit in kg ("bis 2 kg: 4,90") per currency;
 * the smallest bracket the parcel fits into applies. Above the highest one
 * the method is NOT available — it does not get cheaper, and it does not fall
 * back to anything: a parcel the carrier will not take at that price is not a
 * parcel this method can promise. Like the flat rate without an amount,
 * which is unavailable rather than free.
 *
 * The weight is the sum of the lines' shipping weights (ShippingLine, V1).
 * An article without a weight adds nothing — the catalogue's gap, not the
 * parcel's lightness; the operator sees it at the product.
 *
 * Reads the reserved key `rates` (ShippingMethodTypeInterface): the rows in
 * the context currency as [maxWeight, net], put there by the core.
 */
final class WeightRateShipping implements ShippingMethodTypeInterface
{
    public static function getType(): string
    {
        return 'weight_rate';
    }

    public function isAvailable(ShippingContext $context, array $config): bool
    {
        return null !== $this->bracket($context, $config);
    }

    public function calculate(ShippingContext $context, array $config): ShippingQuote
    {
        $net = $this->bracket($context, $config);

        if (null === $net) {
            throw new \LogicException('No weight bracket fits this parcel; ask isAvailable() first.');
        }

        return new ShippingQuote($net);
    }

    /**
     * Checks no age; collects cash on delivery when ticked, like the flat rate.
     */
    public function capabilities(array $config): ShippingCapabilities
    {
        return new ShippingCapabilities(0, false, (bool) ($config['cashOnDelivery'] ?? false));
    }

    /**
     * The net amount of the smallest bracket that holds the parcel, or null.
     *
     * @param array<string, mixed> $config
     */
    private function bracket(ShippingContext $context, array $config): string|null
    {
        $weight = BigDecimal::zero();

        foreach ($context->lines as $line) {
            $weight = $weight->plus($line->totalWeight());
        }

        $best = null;

        foreach (\is_array($config['rates'] ?? null) ? $config['rates'] : [] as $rate) {
            if (!\is_array($rate) || !is_numeric($rate['maxWeight'] ?? null) || !is_numeric($rate['net'] ?? null)) {
                continue;
            }

            $limit = BigDecimal::of((string) $rate['maxWeight']);

            // A row without a limit is no bracket: "bis 0 kg" holds nothing,
            // and reading it as "no limit" would make the method unlimited.
            if (!$limit->isPositive() || $weight->isGreaterThan($limit)) {
                continue;
            }

            if (null === $best || $limit->isLessThan($best['limit'])) {
                $best = ['limit' => $limit, 'net' => (string) $rate['net']];
            }
        }

        return $best['net'] ?? null;
    }
}

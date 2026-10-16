<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

/**
 * A shipping method as a shop offers it: the record plus its ready config
 * array (C3), `net` included (ShippingMethodTypeInterface).
 */
final class ShippingMethod
{
    /**
     * @param array<string, mixed> $config
     * @param list<string>         $countries upper-case ISO codes it delivers to (E-5, V3); empty means
     *                                        every delivery country of the shop
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $title,
        public readonly ShippingTaxMode $taxMode,
        public readonly int $taxClassId,
        public readonly array $config,
        public readonly array $countries = [],
        public readonly ShippingLimits $limits = new ShippingLimits(),
    ) {
    }

    /**
     * The core's own rules, asked before the type (E-5): a country the method
     * does not serve, or a piece beyond its limits, and no module is asked.
     *
     * @param list<ShippingLine> $lines
     */
    public function carries(string $country, array $lines): bool
    {
        if ([] !== $this->countries && !\in_array(strtoupper($country), $this->countries, true)) {
            return false;
        }

        return $this->limits->admitAll($lines);
    }
}

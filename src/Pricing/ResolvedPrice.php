<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * The result of a price resolution.
 *
 * Amounts are decimal strings, never floats. A price that has been through a
 * float has already lost the property that makes it a price.
 */
final class ResolvedPrice
{
    /**
     * @param int|null $customerGroup the group whose row won, null for a price
     *                                that applies to everyone
     */
    public function __construct(
        public readonly string $net,
        public readonly string $currency,
        public readonly PriceSource $source,
        public readonly string $minQuantity,
        public readonly int|null $customerGroup = null,
    ) {
    }
}

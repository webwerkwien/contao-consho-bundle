<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

/**
 * Everything a shipping type may base its answer on (spec 6.1). Built by the
 * checkout (C-5); a type never looks further.
 *
 * ⚠️ `customerGroups` is a LIST, not one group. C-2 wrote a single int here,
 * from before C-4 measured that tl_member.groups is a multi-select: a member
 * can be a dealer and a club member at once, and picking one of them would
 * need a rule that does not exist. The price layer resolves PER GROUP and then
 * takes the best amount (PriceCalculator::offers()); a shipping type that asks
 * "is this a dealer" must see the same groups the price did, or a dealer price
 * could arrive with a retail shipping rate. Changed in C-5, while the only
 * callers were tests.
 */
final class ShippingContext
{
    /**
     * @param list<ShippingLine> $lines
     * @param list<int>          $customerGroups every group the visitor is in, empty for a guest
     * @param int                $minimumAge     highest minimum age in the cart, 0 for none
     */
    public function __construct(
        public readonly array $lines,
        public readonly string $deliveryCountry,
        public readonly int $shopId,
        public readonly string $currency,
        public readonly array $customerGroups,
        public readonly int $minimumAge,
    ) {
    }
}

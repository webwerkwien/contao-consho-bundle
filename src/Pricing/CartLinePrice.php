<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * The price of one basket line: the net of ONE unit at this quantity (scale
 * prices apply), and the tax class that turns net into gross.
 *
 * No gross in here. The gross of a line is calculated from the line's net, not
 * from a rounded unit gross multiplied by the quantity (C22).
 *
 * Since C-7 it also carries where the amount came from, because the same value
 * now feeds an ORDER line, and an order line has to state its provenance: the
 * source for the customer-group notice and the FAGG duty on personalised
 * pricing, the group so that a complaint years later can be answered.
 */
final class CartLinePrice
{
    /**
     * @param int|null $customerGroup the group whose price won, null for a
     *                                price that applies to everyone
     */
    public function __construct(
        public readonly string $unitNet,
        public readonly int $taxClassId,
        public readonly PriceSource $source = PriceSource::List,
        public readonly int|null $customerGroup = null,
    ) {
    }
}

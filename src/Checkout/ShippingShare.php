<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

/**
 * One part of a shipping charge, belonging to one tax class (C4). Becomes one
 * line of type `shipping` on the order (spec 5.2).
 *
 * Also the shape of a reduction's part — the payment discount, a cart rule's
 * discount. Those carry the country of the supplies they reduce (H): a reduction lowers the consideration of each supply at that supply's
 * own rate, and a service is taxed in the shop's country while goods are
 * taxed in the customer's (DL4). '' = the delivery country, as for shipping.
 */
final class ShippingShare
{
    public function __construct(
        public readonly int $taxClassId,
        public readonly string $net,
        /**
         * Left at zero by ShippingTaxSplitter and filled in by
         * CheckoutCalculator::grossed().
         *
         * The splitter divides an amount; it has no country, no timestamp and
         * no business asking for a tax rate. Giving it those would make its
         * tests need rates in order to check arithmetic that has nothing to do
         * with them — and the class that already knows the country is one
         * level up.
         */
        public readonly string $gross = '0.0000',
        public readonly string $taxCountry = '',
    ) {
    }

    public function withGross(string $gross): self
    {
        return new self($this->taxClassId, $this->net, $gross, $this->taxCountry);
    }
}

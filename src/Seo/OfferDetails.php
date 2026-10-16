<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Webwerkwien\ContaoConshoBundle\Shipping\ShippingRate;

/**
 * What the Offer of ONE determined article says beyond its price (Connect-1,
 * S6) — base price, struck price, shipping, returns. Google's merchant
 * listings want an Offer for all of it; an AggregateOffer over a choice still
 * open gets none.
 *
 * Every part may be absent, and an absent part is left out of the markup,
 * never written empty.
 */
final class OfferDetails
{
    /**
     * @param array{unit: string, purchaseUnit: string, referenceUnit: string}|null $units            the article's unit set, where it has a base price
     * @param list<ShippingRate>                                                   $shipping         the cheapest rate per country (K3)
     * @param array{int, int}|null                                                 $deliveryDays     the shop's delivery time, min and max days
     * @param list<string>                                                         $returnCountries  where the return window applies (K4)
     */
    public function __construct(
        public readonly string|null $compareGross = null,
        public readonly array|null $units = null,
        public readonly array $shipping = [],
        public readonly array|null $deliveryDays = null,
        public readonly int $returnDays = 0,
        public readonly array $returnCountries = [],
    ) {
    }
}

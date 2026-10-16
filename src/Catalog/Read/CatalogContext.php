<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog\Read;

use Webwerkwien\ContaoConshoBundle\Tax\TaxPlace;

/**
 * Who the catalogue is read for, without a page or a request (Connect-1, CC2).
 *
 * A start point fixes shop, language and currency, as it does for a visitor.
 * The country is the DELIVERY country the reader prices for — a feed for
 * Germany from an Austrian shop asks with DE. Which rate makes the gross is
 * TaxPlace's answer, as in the checkout (K1): the price this country really
 * pays, not the one the product page shows before an address is known.
 *
 * The groups are handed in rather than read from a token: a feed has no
 * visitor. Empty is the guest, the same audience a crawler meets.
 */
final class CatalogContext
{
    /**
     * @param list<string> $deliveryCountries upper-case ISO codes the shop delivers to; empty means the shop states none
     * @param list<int>    $groups            member groups whose prices apply; empty for a guest
     */
    public function __construct(
        public readonly int $shopId,
        public readonly int $rootPageId,
        public readonly string $language,
        public readonly string $fallback,
        public readonly string $currency,
        public readonly string $country,
        public readonly string $shopCountry,
        public readonly bool $taxAtDestination,
        public readonly array $deliveryCountries,
        // Scheme and host of the start point, "https://shop.example" — what
        // makes an address absolute where no request says it.
        public readonly string $baseUrl = '',
        public readonly array $groups = [],
        public readonly int $now = 0,
    ) {
    }

    /**
     * The country whose rate makes the gross — the checkout's rule for a
     * distance sale (TaxPlace).
     */
    public function taxCountry(): string
    {
        return TaxPlace::forDistanceSale($this->country, $this->shopCountry, $this->taxAtDestination);
    }

    /**
     * The countries shipping and returns are stated for: those the shop
     * delivers to, or its own country where it names none — the checkout
     * offers every country then, but a statement about all of them would
     * claim methods and rates nobody checked.
     *
     * @return list<string>
     */
    public function servedCountries(): array
    {
        if ([] !== $this->deliveryCountries) {
            return $this->deliveryCountries;
        }

        return '' !== $this->shopCountry ? [$this->shopCountry] : [];
    }
}

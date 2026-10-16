<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax;

/**
 * Where a supply that travels to the customer is taxed (block R, O1, R2):
 * goods and digital goods, and with them shipping and payment fees.
 *
 * A distance sale to a consumer in another member state is taxed in the
 * shop's country while the business stays below the 10,000 euro threshold
 * of Art. 59c MwStSystRL (AT Art. 3 Abs. 5 UStG-BMR, DE § 3c Abs. 4 UStG),
 * and in the customer's once it is over it or has opted out of it. Consho
 * cannot know which — sales outside Consho count as well — so the operator
 * says it with the switch "Steuer im Bestimmungsland" (O1, default off).
 *
 * Only between two member states. A customer outside the EU keeps the rate
 * the operator set for that country (an export at 0 %, say), and a shop
 * outside the EU has no threshold of this kind to stay below.
 *
 * Reverse charge is decided before this is asked (CheckoutCalculator,
 * OrderFactory) and a service is taxed in the shop's country regardless
 * (ProductKind::taxCountry()).
 */
final class TaxPlace
{
    /**
     * The 27 member states as ISO 3166-1 codes — Greece is GR here, not the
     * EL of VAT IDs: Consho stores countries as ISO codes.
     */
    public const EU_MEMBER_STATES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public static function isEuMemberState(string $country): bool
    {
        return \in_array(strtoupper(trim($country)), self::EU_MEMBER_STATES, true);
    }

    /**
     * The customer's country, unless it is a distance sale within the EU
     * and the shop is not taxing at destination — then the shop's.
     */
    public static function forDistanceSale(string $customerCountry, string $shopCountry, bool $taxAtDestination): string
    {
        $customer = strtoupper(trim($customerCountry));
        $shop = strtoupper(trim($shopCountry));

        if (!$taxAtDestination && '' !== $customer && $customer !== $shop && self::isEuMemberState($customer) && self::isEuMemberState($shop)) {
            return $shop;
        }

        return $customerCountry;
    }
}

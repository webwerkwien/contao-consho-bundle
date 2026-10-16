<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Contao\CoreBundle\Routing\ResponseContext\JsonLd\JsonLdManager;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlDecoder;
use Webwerkwien\ContaoConshoBundle\Catalog\Gtin;
use Webwerkwien\ContaoConshoBundle\Stock\StockState;

/**
 * schema.org/Product for a product page (plan B-6, specification 8.1).
 *
 * It describes what the page shows, and only when the page offers something:
 *
 * - One article determined (no variants, the only visible one, or ?variant=):
 *   an Offer with that article's price.
 * - A choice still open: an AggregateOffer from the cheapest to the dearest
 *   visible variant with a price — the same prices as the "from" price on the
 *   page (decision E2). Not Google's ProductGroup: one markup per page, matching
 *   the canonical address that never carries ?variant=.
 * - No price at all: nothing (decision E3). Google requires an offer or
 *   reviews for product snippets, and a Product without offers is an error
 *   there, not a smaller result.
 *
 * The Offer of a determined article also carries, since Connect-1 (S6), its
 * base price, its struck price, the shipping costs per country and the
 * return window — OfferDetails. An AggregateOffer carries none of it.
 *
 * ── Deliberately absent ──────────────────────────────────────────────────
 *
 * - gtin unless one article is determined AND Gtin::isValid(): a GTIN is never
 *   inherited (B-5), and a wrong one identifies somebody else's product towards
 *   price comparison sites, where nobody from this shop would notice.
 * - aggregateRating and review: there are no ratings. Invented ones break
 *   Google's guidelines and are a legal risk.
 * - Empty fields: left out rather than written empty.
 */
final class ProductJsonLd
{
    /**
     * Consho's units (tl_consho_product.unit) as UN/CEFACT codes, which
     * Google takes for unit pricing.
     */
    private const UNIT_CODES = [
        'kg' => 'KGM',
        'g' => 'GRM',
        'l' => 'LTR',
        'ml' => 'MLT',
        'm' => 'MTR',
        'cm' => 'CMT',
        'm2' => 'MTK',
        'stk' => 'C62',
    ];

    public function __construct(
        private readonly ResponseContextAccessor $contexts,
        private readonly HtmlDecoder $decoder,
    ) {
    }

    /**
     * @param string        $title         input-encoded, the MAIN product's
     * @param string        $teaser        input-encoded, the main product's
     * @param string        $description   HTML, the main product's; used when the teaser is empty
     * @param string        $gtin          the determined article's GTIN, '' while a choice is open
     * @param string|null   $price         gross of the determined article, null while a choice is open or without a price
     * @param list<string>  $variantPrices gross of every visible variant with a price, for the open choice
     * @param list<string>  $images        absolute addresses
     * @param StockState    $availability  the determined article's, or the best of the variants
     *                                     while a choice is open (Block G, G7)
     *
     * @return array<string, mixed>|null
     */
    public function build(
        string $title,
        string $teaser,
        string $description,
        string $sku,
        string $mpn,
        string $gtin,
        string $brand,
        string|null $price,
        array $variantPrices,
        string $currency,
        string $url,
        array $images,
        StockState $availability = StockState::Available,
        OfferDetails|null $details = null,
    ): ?array {
        $offers = null !== $price
            ? $this->offer($price, $currency, $url, $availability, $details ?? new OfferDetails())
            : $this->aggregateOffer($variantPrices, $currency, $url, $availability);

        if (null === $offers) {
            return null;
        }

        $text = trim($this->decoder->inputEncodedToPlainText($teaser));

        if ('' === $text) {
            $text = trim($this->decoder->htmlToPlainText($description));
        }

        $jsonLd = [
            '@type' => 'Product',
            'name' => trim($this->decoder->inputEncodedToPlainText($title)),
            'description' => $text,
            'image' => $images,
            'sku' => $sku,
            'mpn' => $mpn,
            // Only for a determined article — $price says so — and only a valid number.
            'gtin' => null !== $price && '' !== $gtin && Gtin::isValid($gtin) ? Gtin::normalise($gtin) : '',
            'brand' => '' === $brand ? '' : ['@type' => 'Brand', 'name' => $brand],
            'offers' => $offers,
        ];

        return array_filter($jsonLd, static fn (mixed $value): bool => '' !== $value && [] !== $value);
    }

    /**
     * Hangs the markup into the page's schema.org graph, where Contao prints it.
     *
     * @param array<string, mixed> $jsonLd
     */
    public function apply(array $jsonLd, int $productId): void
    {
        $context = $this->contexts->getResponseContext();

        if (null === $context || !$context->has(JsonLdManager::class)) {
            return;
        }

        $manager = $context->get(JsonLdManager::class);

        $manager
            ->getGraphForSchema(JsonLdManager::SCHEMA_ORG)
            ->set($manager->createSchemaOrgTypeFromArray($jsonLd), '#/schema/consho/product/'.$productId)
        ;
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(string $price, string $currency, string $url, StockState $availability, OfferDetails $details): array
    {
        return array_filter([
            '@type' => 'Offer',
            'price' => self::amount($price),
            'priceCurrency' => $currency,
            'priceSpecification' => self::priceSpecification($price, $currency, $details),
            'availability' => $availability->schemaOrg(),
            'url' => $url,
            'shippingDetails' => self::shippingDetails($currency, $details),
            'hasMerchantReturnPolicy' => self::returnPolicy($details),
        ], static fn (mixed $value): bool => [] !== $value);
    }

    /**
     * Base price and struck price, as Google's merchant listings read them
     * (Connect-1, S6): the active price as a UnitPriceSpecification with the
     * content it is for and the quantity the base price refers to — "750 ml,
     * priced per 1000 ml" —, the struck one with priceType StrikethroughPrice
     * ("Don't mark the active price with a priceType property").
     *
     * One object for one specification, a list for two.
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private static function priceSpecification(string $price, string $currency, OfferDetails $details): array
    {
        $specifications = [];
        $unitCode = null === $details->units ? null : self::UNIT_CODES[$details->units['unit']] ?? null;

        if (null !== $details->units && null !== $unitCode) {
            $specifications[] = [
                '@type' => 'UnitPriceSpecification',
                'price' => self::amount($price),
                'priceCurrency' => $currency,
                'referenceQuantity' => [
                    '@type' => 'QuantitativeValue',
                    'value' => self::quantity($details->units['purchaseUnit']),
                    'unitCode' => $unitCode,
                    'valueReference' => [
                        '@type' => 'QuantitativeValue',
                        'value' => self::quantity($details->units['referenceUnit']),
                        'unitCode' => $unitCode,
                    ],
                ],
            ];
        }

        if (null !== $details->compareGross) {
            $specifications[] = [
                '@type' => 'UnitPriceSpecification',
                'priceType' => 'https://schema.org/StrikethroughPrice',
                'price' => self::amount($details->compareGross),
                'priceCurrency' => $currency,
            ];
        }

        return 1 === \count($specifications) ? $specifications[0] : $specifications;
    }

    /**
     * One OfferShippingDetails per country (K3). Google requires deliveryTime,
     * so without the shop's delivery days there is none at all: an incomplete
     * entry is an error in Search Console, a missing one is not.
     *
     * The shop states ONE delivery time, from order to doorstep. It goes in as
     * transit time with no handling time — the two add up to what the shop
     * says, without inventing a split it never made.
     *
     * @return list<array<string, mixed>>
     */
    private static function shippingDetails(string $currency, OfferDetails $details): array
    {
        if (null === $details->deliveryDays) {
            return [];
        }

        [$min, $max] = $details->deliveryDays;

        return array_map(static fn ($rate): array => [
            '@type' => 'OfferShippingDetails',
            'shippingRate' => [
                '@type' => 'MonetaryAmount',
                'value' => self::amount($rate->gross),
                'currency' => $currency,
            ],
            'shippingDestination' => [
                '@type' => 'DefinedRegion',
                'addressCountry' => $rate->country,
            ],
            'deliveryTime' => [
                '@type' => 'ShippingDeliveryTime',
                // The shop counts working days, Monday to Friday (shop DCA),
                // and says so — a bare DAY would read as calendar days
                // (Fable review Connect-1, H3).
                'businessDays' => [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => array_map(static fn (string $day): string => 'https://schema.org/'.$day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']),
                ],
                'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 0, 'unitCode' => 'DAY'],
                'transitTime' => ['@type' => 'QuantitativeValue', 'minValue' => $min, 'maxValue' => $max, 'unitCode' => 'DAY'],
            ],
        ], $details->shipping);
    }

    /**
     * The withdrawal period as a finite return window (K4). Nothing for 0 —
     * "no withdrawal function" is not "returns not permitted", and markup
     * claiming the latter would be a statement the shop never made.
     *
     * @return array<string, mixed>
     */
    private static function returnPolicy(OfferDetails $details): array
    {
        if ($details->returnDays < 1 || [] === $details->returnCountries) {
            return [];
        }

        return [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => 1 === \count($details->returnCountries) ? $details->returnCountries[0] : $details->returnCountries,
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => $details->returnDays,
        ];
    }

    /**
     * "750.0000" → "750", "0.7500" → "0.75" — a decimal(10,4) column as a
     * figure, not as MySQL hands it back. Through BigDecimal: trimming zeros
     * off the string would turn a bare "500" (SQLite, or a value written by
     * hand) into "5".
     */
    private static function quantity(string $value): string
    {
        return (string) BigDecimal::of(trim($value))->strippedOfTrailingZeros();
    }

    /**
     * @param list<string> $prices
     *
     * @return array<string, string|int>|null
     */
    private function aggregateOffer(array $prices, string $currency, string $url, StockState $availability): ?array
    {
        if ([] === $prices) {
            return null;
        }

        $low = BigDecimal::of($prices[0]);
        $high = $low;

        foreach ($prices as $price) {
            $amount = BigDecimal::of($price);
            $low = $amount->isLessThan($low) ? $amount : $low;
            $high = $amount->isGreaterThan($high) ? $amount : $high;
        }

        return [
            '@type' => 'AggregateOffer',
            'lowPrice' => self::amount((string) $low),
            'highPrice' => self::amount((string) $high),
            'offerCount' => \count($prices),
            'priceCurrency' => $currency,
            'availability' => $availability->schemaOrg(),
            'url' => $url,
        ];
    }

    /**
     * Two decimals with a point, rounded half up — the figure the page prints
     * (MoneyFormatter rounds before formatting for the same reason).
     */
    private static function amount(string $gross): string
    {
        return (string) BigDecimal::of($gross)->toScale(2, RoundingMode::HalfUp);
    }
}

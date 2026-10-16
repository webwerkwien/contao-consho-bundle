<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\Routing\ResponseContext\JsonLd\JsonLdManager;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContext;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlDecoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Seo\OfferDetails;
use Webwerkwien\ContaoConshoBundle\Seo\ProductJsonLd;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingRate;
use Webwerkwien\ContaoConshoBundle\Stock\StockState;

class ProductJsonLdTest extends TestCase
{
    private const VALID_GTIN = '4006381333931';
    private const MISTYPED_GTIN = '4006381333932';
    private const URL = 'https://example.org/produkt/schwedenbitter.html';

    public function testADeterminedArticleGetsAnOfferWithItsPrice(): void
    {
        $jsonLd = $this->build(price: '13.0000');

        self::assertNotNull($jsonLd);
        self::assertSame(
            ['@type' => 'Offer', 'price' => '13.00', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock', 'url' => self::URL],
            $jsonLd['offers'],
        );
    }

    /**
     * Block G (G7): the offer says what the stock says.
     */
    // ── Connect-1, S6: base price, struck price, shipping, returns ─────────

    public function testDerGrundpreisNenntInhaltUndBezugsmenge(): void
    {
        $offer = $this->build(details: new OfferDetails(units: ['unit' => 'ml', 'purchaseUnit' => '750.0000', 'referenceUnit' => '1000.0000']))['offers'] ?? [];

        // Google's own shape: the price is for 750 ml, the base price per 1000 ml.
        self::assertSame([
            '@type' => 'UnitPriceSpecification',
            'price' => '13.00',
            'priceCurrency' => 'EUR',
            'referenceQuantity' => [
                '@type' => 'QuantitativeValue',
                'value' => '750',
                'unitCode' => 'MLT',
                'valueReference' => ['@type' => 'QuantitativeValue', 'value' => '1000', 'unitCode' => 'MLT'],
            ],
        ], $offer['priceSpecification'] ?? null);
        self::assertSame('13.00', $offer['price'] ?? null, 'the active price stays at the offer');
    }

    public function testEineMengeOhneNachkommastellenBleibtGanz(): void
    {
        $quantity = $this->build(details: new OfferDetails(units: ['unit' => 'ml', 'purchaseUnit' => '500', 'referenceUnit' => '100']))['offers']['priceSpecification']['referenceQuantity'] ?? [];

        // Not "5" and "1": the zeros of a whole number are not decimals.
        self::assertSame(['500', '100'], [$quantity['value'] ?? null, $quantity['valueReference']['value'] ?? null]);
    }

    public function testEineUnbekannteEinheitHatKeinenGrundpreis(): void
    {
        $offer = $this->build(details: new OfferDetails(units: ['unit' => 'fass', 'purchaseUnit' => '1.0000', 'referenceUnit' => '1.0000']))['offers'] ?? [];

        self::assertArrayNotHasKey('priceSpecification', $offer);
    }

    public function testDerStreichpreisAlleinUndNebenDemGrundpreis(): void
    {
        $strike = ['@type' => 'UnitPriceSpecification', 'priceType' => 'https://schema.org/StrikethroughPrice', 'price' => '29.00', 'priceCurrency' => 'EUR'];

        self::assertSame($strike, $this->build(details: new OfferDetails('29.0000'))['offers']['priceSpecification'] ?? null);

        $both = $this->build(details: new OfferDetails('29.0000', ['unit' => 'l', 'purchaseUnit' => '0.7500', 'referenceUnit' => '1.0000']))['offers']['priceSpecification'] ?? [];

        // A list of two, and only the struck one carries a priceType.
        self::assertCount(2, $both);
        self::assertSame(['0.75', 'LTR'], [$both[0]['referenceQuantity']['value'] ?? null, $both[0]['referenceQuantity']['unitCode'] ?? null]);
        self::assertArrayNotHasKey('priceType', $both[0]);
        self::assertSame($strike, $both[1]);
    }

    public function testVersandJeLandMitLieferzeit(): void
    {
        $details = new OfferDetails(
            shipping: [new ShippingRate(1, 'Paket', 'AT', '5.0000', '6.0000'), new ShippingRate(2, 'Paket EU', 'DE', '8.0000', '9.6000')],
            deliveryDays: [2, 4],
        );

        $shipping = $this->build(details: $details)['offers']['shippingDetails'] ?? [];

        self::assertSame([
            '@type' => 'OfferShippingDetails',
            'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => '6.00', 'currency' => 'EUR'],
            'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'AT'],
            'deliveryTime' => [
                '@type' => 'ShippingDeliveryTime',
                'businessDays' => [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => ['https://schema.org/Monday', 'https://schema.org/Tuesday', 'https://schema.org/Wednesday', 'https://schema.org/Thursday', 'https://schema.org/Friday'],
                ],
                'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 0, 'unitCode' => 'DAY'],
                'transitTime' => ['@type' => 'QuantitativeValue', 'minValue' => 2, 'maxValue' => 4, 'unitCode' => 'DAY'],
            ],
        ], $shipping[0] ?? null);
        self::assertSame(['DE', '9.60'], [$shipping[1]['shippingDestination']['addressCountry'] ?? null, $shipping[1]['shippingRate']['value'] ?? null]);
    }

    public function testOhneLieferzeitKeinVersand(): void
    {
        // Google requires deliveryTime: an entry without it is an error.
        $offer = $this->build(details: new OfferDetails(shipping: [new ShippingRate(1, 'Paket', 'AT', '5.0000', '6.0000')]))['offers'] ?? [];

        self::assertArrayNotHasKey('shippingDetails', $offer);
    }

    public function testDieRueckgabefristAusDemWiderruf(): void
    {
        $one = $this->build(details: new OfferDetails(returnDays: 14, returnCountries: ['AT']))['offers']['hasMerchantReturnPolicy'] ?? null;
        $two = $this->build(details: new OfferDetails(returnDays: 30, returnCountries: ['AT', 'DE']))['offers']['hasMerchantReturnPolicy'] ?? null;

        self::assertSame([
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => 'AT',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => 14,
        ], $one);
        self::assertSame([['AT', 'DE'], 30], [$two['applicableCountry'] ?? null, $two['merchantReturnDays'] ?? null]);
    }

    public function testOhneWiderrufKeineRueckgabeAngabe(): void
    {
        // 0 is "no withdrawal function", not "returns not permitted".
        self::assertArrayNotHasKey('hasMerchantReturnPolicy', $this->build(details: new OfferDetails(returnDays: 0, returnCountries: ['AT']))['offers'] ?? []);
    }

    public function testEinSammelangebotBekommtNichtsDavon(): void
    {
        $details = new OfferDetails('29.0000', ['unit' => 'ml', 'purchaseUnit' => '750.0000', 'referenceUnit' => '1000.0000'], [new ShippingRate(1, 'Paket', 'AT', '5.0000', '6.0000')], [2, 4], 14, ['AT']);

        $offers = $this->build(price: null, variantPrices: ['13.0000', '15.0000'], details: $details)['offers'] ?? [];

        self::assertSame('AggregateOffer', $offers['@type'] ?? null);
        self::assertSame([], array_intersect(array_keys($offers), ['priceSpecification', 'shippingDetails', 'hasMerchantReturnPolicy']));
    }

    public function testTheAvailabilityFollowsTheStock(): void
    {
        self::assertSame('https://schema.org/OutOfStock', $this->build(availability: StockState::Unavailable)['offers']['availability'] ?? null);
        self::assertSame('https://schema.org/LimitedAvailability', $this->build(availability: StockState::Few)['offers']['availability'] ?? null);
        self::assertSame('https://schema.org/BackOrder', $this->build(price: null, variantPrices: ['13.0000'], availability: StockState::Backorder)['offers']['availability'] ?? null);
    }

    public function testAnOpenChoiceGetsAnAggregateOfferOverTheVariantPrices(): void
    {
        // Dieselben Preise wie der Ab-Preis der Seite: günstigste und teuerste sichtbare Variante.
        $jsonLd = $this->build(price: null, variantPrices: ['29.9000', '13.0000', '19.5000'], gtin: self::VALID_GTIN);

        self::assertNotNull($jsonLd);
        self::assertSame('AggregateOffer', $jsonLd['offers']['@type']);
        self::assertSame('13.00', $jsonLd['offers']['lowPrice']);
        self::assertSame('29.90', $jsonLd['offers']['highPrice']);
        self::assertSame(3, $jsonLd['offers']['offerCount']);
        self::assertArrayNotHasKey('gtin', $jsonLd, 'Ohne gewählte Variante gibt es keine GTIN — sie wird nie vererbt.');
    }

    public function testAValidGtinAppearsAndAMistypedOneDoesNot(): void
    {
        self::assertSame(self::VALID_GTIN, $this->build(gtin: self::VALID_GTIN)['gtin'] ?? null);

        $mistyped = $this->build(gtin: self::MISTYPED_GTIN);

        self::assertNotNull($mistyped);
        self::assertArrayNotHasKey('gtin', $mistyped, 'Eine vertippte GTIN bezeichnet ein fremdes Produkt.');
        self::assertSame('Schwedenbitter', $mistyped['name'], 'Der Rest steht trotzdem.');
    }

    public function testWithoutAnyPriceThereIsNoMarkupAtAll(): void
    {
        // E3: Google verlangt für Produkt-Snippets ein Angebot.
        self::assertNull($this->build(price: null, variantPrices: []));
    }

    public function testEmptyFieldsAreLeftOutAndThereAreNoRatings(): void
    {
        $jsonLd = $this->build(teaser: '', description: '', sku: '', mpn: '', brand: '', images: []);

        self::assertNotNull($jsonLd);
        self::assertSame(['@type', 'name', 'offers'], array_keys($jsonLd));
    }

    public function testTheDescriptionFallsBackFromTheTeaserToTheDescriptionAsPlainText(): void
    {
        self::assertSame('Kräuter & Alkohol', $this->build(teaser: 'Kräuter &amp; Alkohol')['description'] ?? null);
        self::assertSame('Nach altem Rezept', $this->build(teaser: '', description: '<p>Nach <strong>altem</strong> Rezept</p>')['description'] ?? null);
    }

    public function testTheBrandIsABrandObject(): void
    {
        self::assertSame(['@type' => 'Brand', 'name' => 'Swiss Herbs AG'], $this->build(brand: 'Swiss Herbs AG')['brand'] ?? null);
    }

    public function testApplyHangsTheProductIntoContaosGraph(): void
    {
        $context = new ResponseContext();
        $manager = new JsonLdManager($context);
        $context->add($manager);

        $jsonLd = $this->build(price: null, variantPrices: ['13.0000', '29.9000']);
        self::assertNotNull($jsonLd);

        $this->jsonLd($context)->apply($jsonLd, 1);

        $script = $manager->collectFinalScriptFromGraphs();

        // Contao gibt das JSON eingerückt aus — die Prüfung hängt nicht an Leerzeichen.
        self::assertMatchesRegularExpression('/"@type":\s*"Product"/', $script);
        self::assertMatchesRegularExpression('/"@type":\s*"AggregateOffer"/', $script);
        self::assertMatchesRegularExpression('/"@type":\s*"Brand"/', $script);
    }

    public function testApplyDoesNothingWithoutAJsonLdManager(): void
    {
        $context = new ResponseContext();

        $this->jsonLd($context)->apply(['@type' => 'Product', 'name' => 'x'], 1);

        self::assertFalse($context->has(JsonLdManager::class));
    }

    /**
     * @param list<string> $variantPrices
     * @param list<string> $images
     *
     * @return array<string, mixed>|null
     */
    private function build(
        string $teaser = 'Kräuterbitter nach altem Rezept',
        string $description = '',
        string $sku = 'SB-250',
        string $mpn = 'MPN-1',
        string $gtin = '',
        string $brand = 'Swiss Herbs AG',
        string|null $price = '13.0000',
        array $variantPrices = [],
        array $images = ['https://example.org/assets/images/schwedenbitter.jpg'],
        StockState $availability = StockState::Available,
        OfferDetails|null $details = null,
    ): ?array {
        return $this->jsonLd(new ResponseContext())->build(
            'Schwedenbitter',
            $teaser,
            $description,
            $sku,
            $mpn,
            $gtin,
            $brand,
            $price,
            $variantPrices,
            'EUR',
            self::URL,
            $images,
            $availability,
            $details,
        );
    }

    private function jsonLd(ResponseContext $context): ProductJsonLd
    {
        $request = Request::create('/');
        $request->attributes->set(ResponseContext::REQUEST_ATTRIBUTE_NAME, $context);

        $stack = new RequestStack();
        $stack->push($request);

        $insertTags = $this->createMock(InsertTagParser::class);
        $insertTags->method('replaceInline')->willReturnArgument(0);

        return new ProductJsonLd(new ResponseContextAccessor($stack), new HtmlDecoder($insertTags));
    }
}

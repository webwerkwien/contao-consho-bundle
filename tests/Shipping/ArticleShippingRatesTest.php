<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use Contao\CoreBundle\Intl\Countries;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogContext;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTaxClassesInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingRates;
use Webwerkwien\ContaoConshoBundle\Shipping\FlatRateShipping;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingContext;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingLimits;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethod;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypeInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypes;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingQuote;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;
use Webwerkwien\ContaoConshoBundle\Shipping\WeightRateShipping;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;

/**
 * Connect-1, K3: the cheapest method for one piece, asked as the checkout
 * asks a basket of that piece.
 */
class ArticleShippingRatesTest extends TestCase
{
    /** Tax class of the article (10 %), the methods carry class 1 (20 %). */
    public const REDUCED = 2;

    private const ARTICLE = 7;

    public function testDieGuenstigsteArtDieDorthinTraegt(): void
    {
        $rate = $this->rates([
            new ShippingMethod(1, 'flat_rate', 'Express', ShippingTaxMode::Fixed, 1, ['net' => '9.0000']),
            new ShippingMethod(2, 'flat_rate', 'Nur Österreich', ShippingTaxMode::Fixed, 1, ['net' => '3.0000'], ['AT']),
            new ShippingMethod(3, 'flat_rate', 'Paket', ShippingTaxMode::Fixed, 1, ['net' => '5.0000']),
        ])->cheapest($this->context(), 'de', self::ARTICLE, '10.0000');

        // Not "Nur Österreich", and the country upper-cased.
        self::assertSame([3, 'Paket', 'DE', '5.0000', '6.00'], [$rate?->methodId, $rate?->title, $rate?->country, $rate?->net, self::money($rate?->gross)]);
    }

    public function testZuSchwerOderOhneBetragZaehltNicht(): void
    {
        $rate = $this->rates([
            new ShippingMethod(1, 'flat_rate', 'Brief', ShippingTaxMode::Fixed, 1, ['net' => '1.0000'], [], new ShippingLimits('0.0000', '0.0000', '1.0000')),
            new ShippingMethod(2, 'flat_rate', 'Ohne Betrag', ShippingTaxMode::Fixed, 1, ['net' => null]),
            new ShippingMethod(3, 'weight_rate', 'Nach Gewicht', ShippingTaxMode::Fixed, 1, ['rates' => [['maxWeight' => '1.0000', 'net' => '4.0000'], ['maxWeight' => '5.0000', 'net' => '7.0000']]]),
            new ShippingMethod(4, 'gone', 'Modul entfernt', ShippingTaxMode::Fixed, 1, []),
        ], weight: '2.5000')->cheapest($this->context(), 'AT', self::ARTICLE, '10.0000');

        // 2.5 kg: too heavy for the letter, the second weight step.
        self::assertSame([3, '7.0000'], [$rate?->methodId, $rate?->net]);
    }

    public function testEineMindestmengeWiegtWieDerWarenkorb(): void
    {
        $methods = [
            new ShippingMethod(3, 'weight_rate', 'Nach Gewicht', ShippingTaxMode::Fixed, 1, ['rates' => [['maxWeight' => '1.0000', 'net' => '4.0000'], ['maxWeight' => '5.0000', 'net' => '7.0000']]]),
        ];

        // 0.4 kg a piece: one fits the first step, three (1.2 kg) do not (Connect-1).
        $one = $this->rates($methods, weight: '0.4000')->cheapest($this->context(), 'AT', self::ARTICLE, '10.0000');
        $three = $this->rates($methods, weight: '0.4000')->cheapest($this->context(), 'AT', self::ARTICLE, '30.0000', 0, '3');

        self::assertSame(['4.0000', '7.0000'], [$one?->net, $three?->net]);
    }

    public function testNichtsTraegtDorthin(): void
    {
        self::assertNull($this->rates([
            new ShippingMethod(1, 'flat_rate', 'Nur Österreich', ShippingTaxMode::Fixed, 1, ['net' => '3.0000'], ['AT']),
        ])->cheapest($this->context(), 'DE', self::ARTICLE, '10.0000'));
    }

    public function testSteuerlandUndAnteiligeSteuerWieImCheckout(): void
    {
        $methods = [
            new ShippingMethod(1, 'flat_rate', 'Fest', ShippingTaxMode::Fixed, 1, ['net' => '5.0000']),
            new ShippingMethod(2, 'flat_rate', 'Anteilig', ShippingTaxMode::Proportional, 1, ['net' => '5.0000']),
        ];

        // Below the OSS threshold: Austrian rates. Proportional follows the
        // article's 10 %, so it is the cheaper of two equal nets.
        $home = $this->rates($methods)->cheapest($this->context(), 'DE', self::ARTICLE, '10.0000');
        self::assertSame([2, '5.50'], [$home?->methodId, self::money($home?->gross)]);

        // OSS: German rates, 7 % for the reduced class.
        $oss = $this->rates($methods)->cheapest($this->context(taxAtDestination: true), 'DE', self::ARTICLE, '10.0000');
        self::assertSame([2, '5.35'], [$oss?->methodId, self::money($oss?->gross)]);
    }

    public function testDerTypBekommtDenWarenkorbEinesStuecks(): void
    {
        $type = new class implements ShippingMethodTypeInterface {
            public ShippingContext|null $seen = null;

            public static function getType(): string
            {
                return 'probe';
            }

            public function isAvailable(ShippingContext $context, array $config): bool
            {
                $this->seen = $context;

                return true;
            }

            public function calculate(ShippingContext $context, array $config): ShippingQuote
            {
                return new ShippingQuote('2.0000');
            }

            public function capabilities(array $config): ShippingCapabilities
            {
                return new ShippingCapabilities(0, false);
            }
        };

        $this->rates([new ShippingMethod(1, 'probe', 'Modul', ShippingTaxMode::Fixed, 1, [])], probe: $type)
            ->cheapest($this->context(groups: [4]), 'AT', self::ARTICLE, '10.0000', 18);

        $seen = $type->seen;
        self::assertNotNull($seen);
        self::assertSame(['AT', 1, 'EUR', [4], 18, 1, '1', '10.0000', self::REDUCED, '2.5000'], [
            $seen->deliveryCountry, $seen->shopId, $seen->currency, $seen->customerGroups, $seen->minimumAge, \count($seen->lines),
            $seen->lines[0]->quantity, $seen->lines[0]->net, $seen->lines[0]->taxClassId, $seen->lines[0]->weight,
        ]);
    }

    /**
     * @param list<ShippingMethod> $methods
     */
    private function rates(array $methods, string $weight = '2.5000', ShippingMethodTypeInterface|null $probe = null): ArticleShippingRates
    {
        $repository = $this->createStub(ShippingMethodRepositoryInterface::class);
        $repository->method('forShop')->willReturn($methods);

        $types = new ShippingMethodTypes(new ServiceLocator(array_filter([
            'flat_rate' => static fn (): ShippingMethodTypeInterface => new FlatRateShipping(),
            'weight_rate' => static fn (): ShippingMethodTypeInterface => new WeightRateShipping(),
            'probe' => null === $probe ? null : static fn (): ShippingMethodTypeInterface => $probe,
        ])));

        $articles = $this->createStub(ArticleShippingDataInterface::class);
        $articles->method('of')->willReturn([self::ARTICLE => ['weight' => $weight, 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000', 'kind' => 'physical']]);

        $taxClasses = $this->createStub(ArticleTaxClassesInterface::class);
        $taxClasses->method('taxClassOf')->willReturn(self::REDUCED);

        // Two classes: 1 the methods' (20/19 %), REDUCED the article's (10/7 %).
        $rates = new class implements TaxRateRepositoryInterface {
            public function findRate(int $taxClassId, string $countryCode, int $timestamp): string|null
            {
                return [
                    'AT' => [1 => '20.0000', ArticleShippingRatesTest::REDUCED => '10.0000'],
                    'DE' => [1 => '19.0000', ArticleShippingRatesTest::REDUCED => '7.0000'],
                ][$countryCode][$taxClassId] ?? null;
            }

            public function findConfiguredCountries(int $taxClassId, int $timestamp): array
            {
                return ['AT', 'DE'];
            }

            public function findTaxClassIdsUsedByShop(int $shopId): array
            {
                return [1, ArticleShippingRatesTest::REDUCED];
            }

            public function findClassesWithBoundaryIn(int $after, int $until): array
            {
                return [];
            }
        };

        $countries = $this->createStub(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE']);

        return new ArticleShippingRates($repository, $types, $articles, $taxClasses, new GrossCalculator(new TaxResolver($rates, new CountryProvider($countries))));
    }

    /**
     * @param list<int> $groups
     */
    private function context(bool $taxAtDestination = false, array $groups = []): CatalogContext
    {
        return new CatalogContext(1, 1, 'de', 'de', 'EUR', 'AT', 'AT', $taxAtDestination, ['AT', 'DE'], 'https://shop.example', $groups, 1_790_000_000);
    }

    private static function money(string|null $amount): string
    {
        return null === $amount ? '' : number_format((float) $amount, 2, '.', '');
    }
}

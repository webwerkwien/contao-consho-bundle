<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Tax;

use Contao\CoreBundle\Intl\Countries;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\DeliveryCountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;

class DeliveryCountryProviderTest extends TestCase
{
    public function testOffersWhatTheOperatorTickedEvenWhenARateIsMissing(): void
    {
        // The rule this pins, and it replaced the opposite one: a missing rate
        // is a configuration mistake, not a business decision. Removing the
        // country would hide the mistake and lose the orders silently.
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository->method('findTaxClassIdsUsedByShop')->willReturn([1, 2]);
        $repository
            ->method('findConfiguredCountries')
            ->willReturnMap([
                [1, 1_757_000_000, ['AT', 'DE', 'IT']],
                [2, 1_757_000_000, ['AT', 'DE']],
            ])
        ;

        $provider = new DeliveryCountryProvider($repository, $this->countryProvider());

        self::assertSame(
            ['AT' => 'Austria', 'DE' => 'Germany', 'IT' => 'Italy'],
            $provider->forShop($this->shop(['AT', 'DE', 'IT']), 'en'),
        );
    }

    public function testReportsTheCountryAndTheClassThatLacksARate(): void
    {
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository->method('findTaxClassIdsUsedByShop')->willReturn([1, 2]);
        $repository
            ->method('findConfiguredCountries')
            ->willReturnMap([
                [1, 1_757_000_000, ['AT', 'DE', 'IT']],
                [2, 1_757_000_000, ['AT', 'DE']],
            ])
        ;

        $provider = new DeliveryCountryProvider($repository, $this->countryProvider());

        self::assertSame(
            ['IT' => [2]],
            $provider->incompleteFor($this->shop(['AT', 'DE', 'IT']), 1_757_000_000),
        );
    }

    public function testACountryWithoutAReducedRateOfItsOwnIsStillOfferedButReported(): void
    {
        // Denmark has no reduced VAT rate at all — 25 % is the only one. The
        // tax class is the shop's own grouping, not a claim about Danish law,
        // so the remedy is to enter 25 for the reduced class as well. Until
        // that happens the country stays on offer and the report names it.
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository->method('findTaxClassIdsUsedByShop')->willReturn([1, 2]);
        $repository
            ->method('findConfiguredCountries')
            ->willReturnMap([
                [1, 1_757_000_000, ['DE', 'DK']],
                [2, 1_757_000_000, ['DE']],
            ])
        ;

        $provider = new DeliveryCountryProvider($repository, $this->countryProvider(['DE' => 'Germany', 'DK' => 'Denmark']));
        $shop = $this->shop(['DE', 'DK']);

        self::assertSame(['DE' => 'Germany', 'DK' => 'Denmark'], $provider->forShop($shop, 'en'));
        self::assertSame(['DK' => [2]], $provider->incompleteFor($shop, 1_757_000_000));
    }

    public function testNeverOffersACountryOutsideTheShopWhitelist(): void
    {
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository->method('findTaxClassIdsUsedByShop')->willReturn([1]);
        $repository->method('findConfiguredCountries')->willReturn(['AT', 'DE', 'IT']);

        $provider = new DeliveryCountryProvider($repository, $this->countryProvider());

        self::assertSame(
            ['AT' => 'Austria'],
            $provider->forShop($this->shop(['AT']), 'en'),
        );
    }

    public function testOffersNothingWhenTheShopHasNoWhitelist(): void
    {
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository->method('findTaxClassIdsUsedByShop')->willReturn([1]);
        $repository->method('findConfiguredCountries')->willReturn(['AT']);

        $provider = new DeliveryCountryProvider($repository, $this->countryProvider());

        self::assertSame([], $provider->forShop($this->shop([]), 'en'));
    }

    public function testReportsNothingWhenNoProductUsesATaxClassYet(): void
    {
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository->method('findTaxClassIdsUsedByShop')->willReturn([]);

        $provider = new DeliveryCountryProvider($repository, $this->countryProvider());

        self::assertSame([], $provider->incompleteFor($this->shop(['AT', 'DE']), 1_757_000_000));
    }

    public function testNormalisesAWhitelistThatWasStoredInLowerCase(): void
    {
        // Whitelists can predate the normalisation, or arrive from an import.
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository->method('findTaxClassIdsUsedByShop')->willReturn([1]);
        $repository->method('findConfiguredCountries')->willReturn(['AT']);

        $provider = new DeliveryCountryProvider($repository, $this->countryProvider());

        self::assertSame(
            ['AT' => 'Austria'],
            $provider->forShop($this->shop(['at']), 'en'),
        );
    }

    public function testTheHomeCountryIsCheckedEvenWhereTheShopDoesNotDeliver(): void
    {
        // The gross of every display comes about with the home country's rate
        // (C25). A shop in Vienna that only delivers to Germany still needs the
        // Austrian rates.
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository->method('findTaxClassIdsUsedByShop')->willReturn([1]);
        $repository->method('findConfiguredCountries')->willReturnMap([[1, 1_757_000_000, ['DE']]]);

        $provider = new DeliveryCountryProvider($repository, $this->countryProvider());

        self::assertSame(['AT' => [1]], $provider->incompleteFor($this->shop(['DE'], 'AT'), 1_757_000_000));
    }

    /**
     * Nachlese stage 2: Saving a shop's configuration produced 26 messages
     * "order fails at the tax calculation" for EU countries. But with "tax in
     * the country of destination" off, checkout, order and catalogue compute
     * an EU consumer with the rate of the shop's country
     * (TaxPlace::forDistanceSale) — the rate of the delivery country is never
     * asked. Third countries are, and with the switch on, the EU too.
     */
    public function testOhneSteuerImBestimmungslandBrauchenEuLaenderKeinenEigenenSatz(): void
    {
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository->method('findTaxClassIdsUsedByShop')->willReturn([1]);
        $repository->method('findConfiguredCountries')->willReturnMap([[1, 1_757_000_000, ['AT']]]);

        $provider = new DeliveryCountryProvider($repository, $this->countryProvider());

        self::assertSame(['CH' => [1]], $provider->incompleteFor($this->shop(['AT', 'DE', 'IT', 'CH'], 'AT'), 1_757_000_000));
        self::assertSame(
            ['DE' => [1], 'IT' => [1], 'CH' => [1]],
            $provider->incompleteFor($this->shop(['AT', 'DE', 'IT', 'CH'], 'AT', taxAtDestination: true), 1_757_000_000),
        );
    }

    public function testEinShopAusserhalbDerEuBrauchtJedenSatz(): void
    {
        // Without an EU home there is no distance-selling threshold (TaxPlace).
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository->method('findTaxClassIdsUsedByShop')->willReturn([1]);
        $repository->method('findConfiguredCountries')->willReturnMap([[1, 1_757_000_000, ['CH']]]);

        $provider = new DeliveryCountryProvider($repository, $this->countryProvider());

        self::assertSame(['DE' => [1]], $provider->incompleteFor($this->shop(['CH', 'DE'], 'CH'), 1_757_000_000));
    }

    /**
     * @param list<string> $whitelist
     */
    private function shop(array $whitelist, string $country = '', bool $taxAtDestination = false): ShopModel
    {
        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnMap([
            ['id', 3],
            ['deliveryCountries', serialize($whitelist)],
            ['country', $country],
            ['taxAtDestination', $taxAtDestination ? '1' : ''],
        ]);

        return $shop;
    }

    /**
     * @param array<string, string>|null $labels
     */
    private function countryProvider(array|null $labels = null): CountryProvider
    {
        $countries = $this->createMock(Countries::class);
        $countries->method('getCountries')->willReturn(
            $labels ?? ['AT' => 'Austria', 'DE' => 'Germany', 'IT' => 'Italy'],
        );

        return new CountryProvider($countries);
    }
}

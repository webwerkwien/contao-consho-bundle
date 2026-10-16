<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Tax;

use Contao\CoreBundle\Intl\Countries;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;

class CountryProviderTest extends TestCase
{
    public function testReturnsTheCountriesContaoOffers(): void
    {
        $countries = $this->createMock(Countries::class);
        $countries
            ->method('getCountries')
            ->with('de')
            ->willReturn(['AT' => 'Österreich', 'DE' => 'Deutschland'])
        ;

        $provider = new CountryProvider($countries);

        self::assertSame(['AT' => 'Österreich', 'DE' => 'Deutschland'], $provider->all('de'));
    }

    public function testNormalisesACodeToUppercase(): void
    {
        $provider = new CountryProvider($this->createMock(Countries::class));

        self::assertSame('AT', $provider->normalise('at'));
        self::assertSame('AT', $provider->normalise(' At '));
    }

    public function testKnowsWhetherACodeIsValid(): void
    {
        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE', 'CH']);

        $provider = new CountryProvider($countries);

        self::assertTrue($provider->isValid('at'));
        self::assertTrue($provider->isValid('AT'));
        self::assertFalse($provider->isValid('XX'));
        self::assertFalse($provider->isValid(''));
    }
}

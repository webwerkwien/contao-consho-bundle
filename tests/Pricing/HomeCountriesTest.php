<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\HomeCountries;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketRepositoryInterface;

class HomeCountriesTest extends TestCase
{
    public function testWaehrungenUndLaenderSindSortiert(): void
    {
        $homeCountries = $this->homeCountries([['EUR', 'DE'], ['CHF', 'CH'], ['EUR', 'AT'], ['EUR', 'DE']]);

        self::assertSame(['CHF', 'EUR'], $homeCountries->currencies());
        self::assertSame(['AT', 'DE'], $homeCountries->countriesFor('EUR'));
        self::assertSame(['AT', 'CH', 'DE'], $homeCountries->all());
    }

    public function testEineWaehrungOhneShopHatKeineLaender(): void
    {
        $homeCountries = $this->homeCountries([['EUR', 'AT']]);

        self::assertSame([], $homeCountries->countriesFor('XYZ'));
    }

    public function testEineWahlGibtEsNurBeiZweiHeimatlaendernEinerWaehrung(): void
    {
        self::assertFalse($this->homeCountries([['EUR', 'AT'], ['CHF', 'CH']])->offersChoice());
        self::assertTrue($this->homeCountries([['EUR', 'AT'], ['EUR', 'DE']])->offersChoice());
    }

    public function testDasGespeicherteLandGiltSolangeEsZurWaehrungGehoert(): void
    {
        $homeCountries = $this->homeCountries([['EUR', 'AT'], ['EUR', 'DE']]);

        self::assertSame('DE', $homeCountries->resolve('EUR', 'DE'));
    }

    public function testBeiNurEinemHeimatlandGiltDiesesAuchOhnePassendeWahl(): void
    {
        $homeCountries = $this->homeCountries([['EUR', 'AT']]);

        self::assertSame('AT', $homeCountries->resolve('EUR', ''));
        self::assertSame('AT', $homeCountries->resolve('EUR', 'DE'));
    }

    public function testOhnePassendeWahlUnterMehrerenGiltKeines(): void
    {
        $homeCountries = $this->homeCountries([['EUR', 'AT'], ['EUR', 'DE']]);

        self::assertNull($homeCountries->resolve('EUR', ''));
        self::assertNull($homeCountries->resolve('XYZ', 'AT'));
    }

    public function testDieMaerkteWerdenJeRequestNurEinmalGelesen(): void
    {
        $repository = $this->createMock(MarketRepositoryInterface::class);
        $repository->expects(self::once())->method('findMarkets')->willReturn([new Market('EUR', 'AT')]);

        $homeCountries = new HomeCountries($repository);

        $homeCountries->currencies();
        $homeCountries->countriesFor('EUR');
        $homeCountries->all();
        $homeCountries->offersChoice();
        $homeCountries->resolve('EUR', 'AT');
    }

    public function testNachDemZuruecksetzenWerdenSieNeuGelesen(): void
    {
        $repository = $this->createMock(MarketRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('findMarkets')->willReturn([new Market('EUR', 'AT')]);

        $homeCountries = new HomeCountries($repository);

        $homeCountries->currencies();
        $homeCountries->reset();
        $homeCountries->currencies();
    }

    /**
     * @param list<array{string, string}> $markets currency, country
     */
    private function homeCountries(array $markets): HomeCountries
    {
        $repository = $this->createMock(MarketRepositoryInterface::class);
        $repository->method('findMarkets')->willReturn(array_map(static fn (array $m): Market => new Market($m[0], $m[1]), $markets));

        return new HomeCountries($repository);
    }
}

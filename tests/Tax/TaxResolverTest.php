<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Tax;

use Contao\CoreBundle\Intl\Countries;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;

class TaxResolverTest extends TestCase
{
    public function testResolvesTheRateForAClassCountryAndDate(): void
    {
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository
            ->method('findRate')
            ->with(1, 'AT', 1_757_000_000)
            ->willReturn('20.0000')
        ;

        $resolver = new TaxResolver($repository, $this->countryProvider());

        self::assertSame('20.0000', $resolver->resolveRate(1, 'AT', 1_757_000_000));
    }

    public function testNormalisesTheCountryCodeBeforeLookingUp(): void
    {
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('findRate')
            ->with(1, 'AT', 1_757_000_000)
            ->willReturn('20.0000')
        ;

        $resolver = new TaxResolver($repository, $this->countryProvider());

        self::assertSame('20.0000', $resolver->resolveRate(1, ' at ', 1_757_000_000));
    }

    public function testThrowsWhenNoRateIsConfigured(): void
    {
        $repository = $this->createMock(TaxRateRepositoryInterface::class);
        $repository->method('findRate')->willReturn(null);

        $resolver = new TaxResolver($repository, $this->countryProvider());

        $this->expectException(TaxRateNotFound::class);
        $this->expectExceptionMessage('IT');

        $resolver->resolveRate(1, 'IT', 1_757_000_000);
    }

    public function testTellsAConfiguredZeroRateApartFromAMissingOne(): void
    {
        // The rule this pins: zero per cent is a legitimate rate — zero-rated
        // goods exist — but a *missing* rate must never quietly become zero.
        // That is the obvious "fix" once someone trips over the exception, and
        // it would produce invoices that are wrong and look right.
        $configured = $this->createMock(TaxRateRepositoryInterface::class);
        $configured->method('findRate')->willReturn('0.0000');

        $missing = $this->createMock(TaxRateRepositoryInterface::class);
        $missing->method('findRate')->willReturn(null);

        self::assertSame(
            '0.0000',
            (new TaxResolver($configured, $this->countryProvider()))->resolveRate(1, 'AT', 1_757_000_000),
        );

        $this->expectException(TaxRateNotFound::class);

        (new TaxResolver($missing, $this->countryProvider()))->resolveRate(1, 'AT', 1_757_000_000);
    }

    private function countryProvider(): CountryProvider
    {
        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE', 'IT']);

        return new CountryProvider($countries);
    }
}

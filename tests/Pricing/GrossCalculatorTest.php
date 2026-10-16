<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

class GrossCalculatorTest extends TestCase
{
    use TaxFixture;

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function amounts(): iterable
    {
        yield 'glatter Betrag, 20 %' => ['10.0000', 'AT', '12.0000'];
        yield 'aus einer Brutto-Eingabe zurückgerechnet, 20 %' => ['10.8333', 'AT', '13.0000'];
        yield 'Deutschland, 19 %' => ['10.0000', 'DE', '11.9000'];
        yield 'Satz null' => ['10.0000', 'CH', '10.0000'];
        yield 'Rundung auf vier Stellen' => ['0.0001', 'AT', '0.0001'];
    }

    #[DataProvider('amounts')]
    public function testAddsTheRateOfTheCountryToTheNetAmount(string $net, string $country, string $expected): void
    {
        $calculator = $this->grossCalculator(['AT' => '20.0000', 'DE' => '19.0000', 'CH' => '0.0000']);

        self::assertSame($expected, $calculator->gross($net, 1, $country, 1_790_000_000));
    }

    public function testACountryWithoutARateFailsLoudly(): void
    {
        // Lieber kein Preis als ein still falscher: ohne Satz kein Brutto.
        $this->expectException(TaxRateNotFound::class);

        $this->grossCalculator(['AT' => '20.0000'])->gross('10.0000', 1, 'IT', 1_790_000_000);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function grossAmounts(): iterable
    {
        yield 'glatter Betrag, 20 %' => ['12.0000', 'AT', '10.0000'];
        yield 'krummes Brutto, 20 %' => ['19.99', 'AT', '16.6583'];
        yield 'Deutschland, 19 %' => ['11.90', 'DE', '10.0000'];
        yield 'Satz null' => ['10.00', 'CH', '10.0000'];
        // 0,0003 / 1,2 = 0,00025 — HalfEven und HalfDown ergäben 0,0002.
        yield 'kaufmännisch aufgerundet' => ['0.0003', 'AT', '0.0003'];
    }

    #[DataProvider('grossAmounts')]
    public function testZiehtDenSatzDesLandesVomBruttoAb(string $gross, string $country, string $expected): void
    {
        $calculator = $this->grossCalculator(['AT' => '20.0000', 'DE' => '19.0000', 'CH' => '0.0000']);

        self::assertSame($expected, $calculator->net($gross, 1, $country, 1_790_000_000));
    }

    public function testOhneSatzScheitertAuchDieRueckrechnung(): void
    {
        $this->expectException(TaxRateNotFound::class);

        $this->grossCalculator(['AT' => '20.0000'])->net('12.00', 1, 'IT', 1_790_000_000);
    }
}

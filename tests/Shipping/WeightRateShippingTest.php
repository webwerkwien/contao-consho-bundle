<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingContext;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingLine;
use Webwerkwien\ContaoConshoBundle\Shipping\WeightRateShipping;

/**
 * Gewichtsstaffel (E-4, V2): die kleinste Stufe, in die das Paket passt;
 * über der höchsten nicht verfügbar.
 */
class WeightRateShippingTest extends TestCase
{
    private const RATES = ['rates' => [
        ['maxWeight' => '2.0000', 'net' => '4.0000'],
        ['maxWeight' => '10.0000', 'net' => '7.5000'],
        ['maxWeight' => '31.5000', 'net' => '12.0000'],
    ]];

    public function testDieKleinstePassendeStufeGilt(): void
    {
        $type = new WeightRateShipping();

        self::assertSame('4.0000', $type->calculate($this->parcel(['1.5000' => '1']), self::RATES)->net);
        self::assertSame('4.0000', $type->calculate($this->parcel(['1.0000' => '2']), self::RATES)->net, 'genau 2 kg passen noch in „bis 2 kg"');
        self::assertSame('7.5000', $type->calculate($this->parcel(['1.0000' => '2', '0.0010' => '1']), self::RATES)->net);
        self::assertSame('12.0000', $type->calculate($this->parcel(['15.0000' => '2']), self::RATES)->net);
    }

    public function testUeberDerHoechstenStufeNichtVerfuegbar(): void
    {
        self::assertFalse((new WeightRateShipping())->isAvailable($this->parcel(['16.0000' => '2']), self::RATES));
    }

    /**
     * Die Reihenfolge der Zeilen spielt keine Rolle: Ein Betreiber legt die
     * 5-kg-Stufe vielleicht nachträglich an.
     */
    public function testDieReihenfolgeDerZeilenIstEgal(): void
    {
        $rates = ['rates' => [
            ['maxWeight' => '10.0000', 'net' => '7.5000'],
            ['maxWeight' => '2.0000', 'net' => '4.0000'],
            ['maxWeight' => '5.0000', 'net' => '5.9000'],
        ]];

        self::assertSame('5.9000', (new WeightRateShipping())->calculate($this->parcel(['3.0000' => '1']), $rates)->net);
    }

    /**
     * „Bis 0 kg" fasst nichts. Als „ohne Grenze" gelesen, wäre die Versandart
     * für jedes Gewicht offen.
     */
    public function testEineZeileOhneGrenzeIstKeineStufe(): void
    {
        $rates = ['rates' => [['maxWeight' => '0.0000', 'net' => '3.0000']]];

        self::assertFalse((new WeightRateShipping())->isAvailable($this->parcel(['1.0000' => '1']), $rates));
    }

    public function testOhneZeilenNichtVerfuegbar(): void
    {
        self::assertFalse((new WeightRateShipping())->isAvailable($this->parcel(['1.0000' => '1']), ['rates' => []]));
        self::assertFalse((new WeightRateShipping())->isAvailable($this->parcel(['1.0000' => '1']), []));
    }

    public function testNachnahmeWieBeiDerPauschaleNurMitHaekchen(): void
    {
        self::assertTrue((new WeightRateShipping())->capabilities(['cashOnDelivery' => '1'])->cashOnDelivery);
        self::assertFalse((new WeightRateShipping())->capabilities([])->cashOnDelivery);
    }

    /**
     * @param array<string, string> $weights weight per piece => quantity
     */
    private function parcel(array $weights): ShippingContext
    {
        $lines = [];
        $id = 0;

        foreach ($weights as $weight => $quantity) {
            $lines[] = new ShippingLine(++$id, $quantity, '10.0000', 1, (string) $weight);
        }

        return new ShippingContext($lines, 'AT', 1, 'EUR', [], 0);
    }
}

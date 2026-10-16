<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Shipping\FlatRateShipping;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingContext;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingLine;

class FlatRateShippingTest extends TestCase
{
    public function testDerSchluesselIstFest(): void
    {
        self::assertSame('flat_rate', FlatRateShipping::getType());
    }

    public function testMitBetragInDerWaehrungIstSieVerfuegbarUndKostetIhn(): void
    {
        $flat = new FlatRateShipping();

        self::assertTrue($flat->isAvailable($this->context(), ['net' => '4.0833']));
        self::assertSame('4.0833', $flat->calculate($this->context(), ['net' => '4.0833'])->net);
    }

    public function testDieMengeAendertDiePauschaleNicht(): void
    {
        $many = new ShippingContext([new ShippingLine(1, '50', '500.0000', 1)], 'AT', 1, 'EUR', [], 0);

        self::assertSame('4.0833', (new FlatRateShipping())->calculate($many, ['net' => '4.0833'])->net);
    }

    public function testOhneBetragInDerWaehrungIstSieNichtVerfuegbar(): void
    {
        // Spec 6.1: fehlt der Betrag, nicht verfügbar (plus Befund, Task 9).
        self::assertFalse((new FlatRateShipping())->isAvailable($this->context(), ['net' => null]));
        self::assertFalse((new FlatRateShipping())->isAvailable($this->context(), []));
    }

    public function testOhneBetragWirdNichtMitNullGerechnet(): void
    {
        $this->expectException(\LogicException::class);

        (new FlatRateShipping())->calculate($this->context(), []);
    }

    public function testEinePauschalePrueftKeinAlter(): void
    {
        $capabilities = (new FlatRateShipping())->capabilities([]);

        self::assertSame(0, $capabilities->ageCheck);
        self::assertFalse($capabilities->needsDateOfBirth);
    }

    private function context(): ShippingContext
    {
        return new ShippingContext([new ShippingLine(1, '1', '10.0000', 1)], 'AT', 1, 'EUR', [], 0);
    }
}

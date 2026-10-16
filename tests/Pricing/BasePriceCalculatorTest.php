<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;

class BasePriceCalculatorTest extends TestCase
{
    private BasePriceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BasePriceCalculator();
    }

    public function testCalculatesThePricePerLitreForA250MlBottle(): void
    {
        // 4.50 EUR for 0.25 l, reference 1 l -> 18.00 per litre
        self::assertSame('18.0000', $this->calculator->calculate('4.5000', '0.2500', '1.0000'));
    }

    public function testCalculatesThePricePer100Grams(): void
    {
        // 3.20 EUR for 0.5 kg, reference 0.1 kg -> 0.64 per 100 g
        self::assertSame('0.6400', $this->calculator->calculate('3.2000', '0.5000', '0.1000'));
    }

    public function testReturnsNullWhenTheContentIsZero(): void
    {
        // Not an edge case: piece goods have no content, and that is the
        // normal case. Dividing here would take down every product page
        // without a unit of measure.
        self::assertNull($this->calculator->calculate('4.5000', '0.0000', '1.0000'));
    }

    public function testReturnsNullWhenTheReferenceIsZero(): void
    {
        self::assertNull($this->calculator->calculate('4.5000', '0.2500', '0.0000'));
    }

    public function testRoundsHalfUpToFourDecimals(): void
    {
        // 10 / 3 -> 3.33333... must not throw and must not silently truncate
        self::assertSame('3.3333', $this->calculator->calculate('10.0000', '3.0000', '1.0000'));
    }
}

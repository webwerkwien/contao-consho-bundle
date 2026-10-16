<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;

class DeliveryStateCalculatorTest extends TestCase
{
    private DeliveryStateCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DeliveryStateCalculator();
    }

    public function testNothingDeliveredMeansOpen(): void
    {
        self::assertSame(
            DeliveryState::Open,
            $this->calculator->calculate(['a' => '2.0000', 'b' => '1.0000'], []),
        );
    }

    public function testSomeItemsDeliveredMeansPartial(): void
    {
        self::assertSame(
            DeliveryState::Partial,
            $this->calculator->calculate(['a' => '2.0000', 'b' => '1.0000'], ['a' => '2.0000']),
        );
    }

    public function testAPartialQuantityOfOneItemMeansPartial(): void
    {
        self::assertSame(
            DeliveryState::Partial,
            $this->calculator->calculate(['a' => '5.0000'], ['a' => '3.0000']),
        );
    }

    public function testEverythingDeliveredMeansComplete(): void
    {
        self::assertSame(
            DeliveryState::Complete,
            $this->calculator->calculate(['a' => '2.0000', 'b' => '1.0000'], ['a' => '2.0000', 'b' => '1.0000']),
        );
    }

    public function testDeliveringMoreThanOrderedStillCountsAsComplete(): void
    {
        // A replacement unit sent alongside must not leave the order stuck at
        // "partial" forever.
        self::assertSame(
            DeliveryState::Complete,
            $this->calculator->calculate(['a' => '2.0000'], ['a' => '3.0000']),
        );
    }

    public function testAnOrderWithoutItemsIsComplete(): void
    {
        self::assertSame(DeliveryState::Complete, $this->calculator->calculate([], []));
    }
}

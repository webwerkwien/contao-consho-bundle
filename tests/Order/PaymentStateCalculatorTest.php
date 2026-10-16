<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;

class PaymentStateCalculatorTest extends TestCase
{
    private PaymentStateCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PaymentStateCalculator();
    }

    public function testNoPaymentsMeansOpen(): void
    {
        self::assertSame(PaymentState::Open, $this->calculator->calculate('100.0000', []));
    }

    public function testAPartialAmountMeansPartial(): void
    {
        self::assertSame(PaymentState::Partial, $this->calculator->calculate('100.0000', ['30.0000']));
    }

    public function testInstalmentsAddUp(): void
    {
        self::assertSame(PaymentState::Paid, $this->calculator->calculate('100.0000', ['30.0000', '70.0000']));
    }

    public function testTheExactAmountMeansPaid(): void
    {
        self::assertSame(PaymentState::Paid, $this->calculator->calculate('100.0000', ['100.0000']));
    }

    public function testMoreThanTheTotalMeansOverpaid(): void
    {
        self::assertSame(PaymentState::Overpaid, $this->calculator->calculate('100.0000', ['120.0000']));
    }

    public function testAFullRefundMeansRefunded(): void
    {
        self::assertSame(
            PaymentState::Refunded,
            $this->calculator->calculate('100.0000', ['100.0000', '-100.0000']),
        );
    }

    public function testAPartialRefundFallsBackToPartial(): void
    {
        self::assertSame(
            PaymentState::Partial,
            $this->calculator->calculate('100.0000', ['100.0000', '-40.0000']),
        );
    }

    public function testFourDecimalsDoNotDriftIntoPartial(): void
    {
        /*
         * The reason brick/math is a dependency. Summed as floats, three times
         * 33.3333 gives 99.99990000000001 — the order would read as overpaid,
         * and nobody would ever find out why.
         */
        self::assertSame(
            PaymentState::Paid,
            $this->calculator->calculate('99.9999', ['33.3333', '33.3333', '33.3333']),
        );
    }
}

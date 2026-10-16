<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Order\StatusColour;

class StatusColourTest extends TestCase
{
    public function testOpenPaymentIsGrey(): void
    {
        $this->assertSame(StatusColour::Open, StatusColour::forPayment(PaymentState::Open));
    }

    public function testPartialPaymentIsOrange(): void
    {
        $this->assertSame(StatusColour::Progress, StatusColour::forPayment(PaymentState::Partial));
    }

    public function testPaidIsGreen(): void
    {
        $this->assertSame(StatusColour::Done, StatusColour::forPayment(PaymentState::Paid));
    }

    /**
     * The one payment state that has to catch an eye: money arrived that has to
     * go back out again.
     */
    public function testOverpaidIsRed(): void
    {
        $this->assertSame(StatusColour::Attention, StatusColour::forPayment(PaymentState::Overpaid));
    }

    /**
     * A reversed order is settled, not broken. Red here would leave every
     * cancelled order shouting for attention forever.
     */
    public function testRefundedIsNotRed(): void
    {
        $this->assertNotSame(StatusColour::Attention, StatusColour::forPayment(PaymentState::Refunded));
    }

    public function testDeliveryNeverAsksForAttention(): void
    {
        foreach (DeliveryState::cases() as $state) {
            $this->assertNotSame(
                StatusColour::Attention,
                StatusColour::forDelivery($state),
                // DeliveryState knows Open, Partial and Complete and nothing
                // else, so red cannot occur here. Recorded as a test so that
                // adding a failure state to the enum makes someone decide its
                // colour rather than let it default to grey.
                'Delivery has no failure state — adding one means mapping it here.',
            );
        }
    }

    public function testEveryColourNamesAContaoVariable(): void
    {
        foreach (StatusColour::cases() as $colour) {
            $this->assertMatchesRegularExpression('/^--[a-z]+$/', $colour->cssVariable());
        }
    }
}

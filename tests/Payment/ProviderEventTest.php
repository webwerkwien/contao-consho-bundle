<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Payment\ProviderEvent;
use Webwerkwien\ContaoConshoBundle\Payment\ProviderEventKind;

class ProviderEventTest extends TestCase
{
    public function testChargebackAmountIsScaledAndPositive(): void
    {
        $event = ProviderEvent::chargeback(' chb_abc ', '-12.5', 'eur', ' AC04 Account closed ', 1700000000);

        self::assertSame(ProviderEventKind::Chargeback, $event->kind);
        self::assertSame('chb_abc', $event->reference);
        self::assertSame('12.5000', $event->amount);
        self::assertSame('EUR', $event->currency);
        self::assertSame('AC04 Account closed', $event->reason);
        self::assertSame(0, $event->reversedAt);
        self::assertSame('', $event->refundState);
    }

    public function testReversedChargebackKeepsTheTime(): void
    {
        self::assertSame(1700000500, ProviderEvent::chargeback('chb_1', '5', 'EUR', '', 1700000000, 1700000500)->reversedAt);
    }

    public function testRefundCarriesItsState(): void
    {
        $event = ProviderEvent::refund('re_1', '5', 'EUR', 'refunded', 1700000000);

        self::assertSame(ProviderEventKind::Refund, $event->kind);
        self::assertSame('refunded', $event->refundState);
        self::assertSame('', $event->reason);
    }

    public function testUnknownRefundStateIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProviderEvent::refund('re_1', '5', 'EUR', 'queued', 1700000000);
    }

    public function testRefundNeedsAReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProviderEvent::refund('  ', '5', 'EUR', 'refunded', 1700000000);
    }

    public function testZeroAmountIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProviderEvent::chargeback('chb_x', '0', 'EUR', '', 1700000000);
    }

    public function testCurrencyIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProviderEvent::chargeback('chb_x', '5', '', '', 1700000000);
    }
}

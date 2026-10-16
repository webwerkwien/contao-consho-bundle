<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationOutcome;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationResult;

class NotificationResultTest extends TestCase
{
    public function testBezahltTraegtBetragUndReferenz(): void
    {
        $result = NotificationResult::paid('12.5', 'tx_1');

        self::assertSame(NotificationOutcome::Paid, $result->outcome);
        self::assertSame('12.5000', $result->amount);
        self::assertSame('tx_1', $result->reference);
    }

    public function testDasMandatIstFreiwilligUndGetrimmt(): void
    {
        self::assertSame('', NotificationResult::paid('1', 'tx_1')->mandate);
        self::assertSame('cus_1|pm_2', NotificationResult::paid('1', 'tx_1', " cus_1|pm_2\n")->mandate);
        self::assertSame('', NotificationResult::failed()->mandate);
    }

    public function testBezahltMitNullIstKeinZahlungseingang(): void
    {
        // Der Kern schreibt aus „paid" eine Zeile in tl_consho_payment (C-11).
        $this->expectException(\InvalidArgumentException::class);

        NotificationResult::paid('0', 'tx_1');
    }

    public function testIgnoriertUndFehlgeschlagenHabenKeinenBetrag(): void
    {
        self::assertSame(NotificationOutcome::Ignored, NotificationResult::ignored()->outcome);
        self::assertSame('', NotificationResult::ignored()->amount);
        self::assertSame('', NotificationResult::ignored()->reference);

        self::assertSame(NotificationOutcome::Failed, NotificationResult::failed()->outcome);
        self::assertSame('', NotificationResult::failed()->amount);
        self::assertSame('', NotificationResult::failed()->reference);
    }

    public function testEinBetragDerAufNullGerundetWirdIstKeinZahlungseingang(): void
    {
        // Vor der Rundung nicht null, danach schon — die Reihenfolge in paid() entscheidet.
        $this->expectException(\InvalidArgumentException::class);

        NotificationResult::paid('0.00004', 'tx_1');
    }
}

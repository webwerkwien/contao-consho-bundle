<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationOutcome;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationResult;
use Webwerkwien\ContaoConshoBundle\Payment\ProviderEvent;

class NotificationResultTest extends TestCase
{
    public function testBezahltTraegtBetragWaehrungBezugUndReferenz(): void
    {
        $result = NotificationResult::paid('12.5', ' eur ', ' cs_1 ', 'tx_1');

        self::assertSame(NotificationOutcome::Paid, $result->outcome);
        self::assertSame('12.5000', $result->amount);
        self::assertSame('EUR', $result->currency);
        self::assertSame('cs_1', $result->for);
        self::assertSame('tx_1', $result->reference);
    }

    public function testDasMandatIstFreiwilligUndGetrimmt(): void
    {
        self::assertSame('', NotificationResult::paid('1', 'EUR', 'cs_1', 'tx_1')->mandate);
        self::assertSame('cus_1|pm_2', NotificationResult::paid('1', 'EUR', 'cs_1', 'tx_1', " cus_1|pm_2\n")->mandate);
        self::assertSame('', NotificationResult::failed('cs_1')->mandate);
    }

    public function testBezahltMitNullIstKeinZahlungseingang(): void
    {
        // The core writes a row in tl_consho_payment from "paid" (C-11).
        $this->expectException(\InvalidArgumentException::class);

        NotificationResult::paid('0', 'EUR', 'cs_1', 'tx_1');
    }

    /**
     * A-6: the core checks what a message is about; a message that does not
     * say cannot be checked.
     */
    public function testOhneBezugKeineMeldung(): void
    {
        foreach ([static fn () => NotificationResult::paid('1', 'EUR', ' ', 'tx_1'), static fn () => NotificationResult::failed('')] as $build) {
            try {
                $build();
                self::fail('ohne Bezug angenommen');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->addToAssertionCount(1);
    }

    public function testIgnoriertUndFehlgeschlagenHabenKeinenBetrag(): void
    {
        self::assertSame(NotificationOutcome::Ignored, NotificationResult::ignored()->outcome);
        self::assertSame('', NotificationResult::ignored()->amount);
        self::assertSame('', NotificationResult::ignored()->reference);

        self::assertSame(NotificationOutcome::Failed, NotificationResult::failed('cs_1')->outcome);
        self::assertSame('', NotificationResult::failed('cs_1')->amount);
        self::assertSame('', NotificationResult::failed('cs_1')->reference);
        self::assertSame('cs_1', NotificationResult::failed('cs_1')->for);
    }

    public function testEventsAreAddedToACopy(): void
    {
        $paid = NotificationResult::paid('10', 'EUR', 'tr_1', 'tr_1', 'mdt_1');
        $event = ProviderEvent::chargeback('chb_1', '10', 'EUR', '', 1700000000);

        $with = $paid->withEvents($event);

        self::assertSame([], $paid->events);
        self::assertSame([$event], $with->events);
        self::assertSame('10.0000', $with->amount);
        self::assertSame('EUR', $with->currency);
        self::assertSame('tr_1', $with->for);
        self::assertSame('mdt_1', $with->mandate);
        self::assertSame(NotificationOutcome::Paid, $with->outcome);
    }

    public function testEinBetragDerAufNullGerundetWirdIstKeinZahlungseingang(): void
    {
        // Not zero before rounding, zero after — the order of steps in paid() decides.
        $this->expectException(\InvalidArgumentException::class);

        NotificationResult::paid('0.00004', 'EUR', 'cs_1', 'tx_1');
    }
}

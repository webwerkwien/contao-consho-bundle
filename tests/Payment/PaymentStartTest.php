<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStart;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStartOutcome;

class PaymentStartTest extends TestCase
{
    public function testAbgeschlossenTraegtHinweisUndDetailsGetrennt(): void
    {
        $start = PaymentStart::completed('<p>Bitte überweisen.</p>', ['iban' => 'AT611904300234573201']);

        self::assertSame(PaymentStartOutcome::Completed, $start->outcome);
        self::assertSame('<p>Bitte überweisen.</p>', $start->notice);
        self::assertSame(['iban' => 'AT611904300234573201'], $start->details);
        self::assertSame('', $start->url);
    }

    public function testWeiterleitungBrauchtEineAdresse(): void
    {
        self::assertSame('https://pay.example/x', PaymentStart::redirect('https://pay.example/x')->url);

        $this->expectException(\InvalidArgumentException::class);

        PaymentStart::redirect('');
    }

    public function testFehlschlagNenntDenGrund(): void
    {
        $start = PaymentStart::failed('declined');

        self::assertSame(PaymentStartOutcome::Failed, $start->outcome);
        self::assertSame('declined', $start->reason);
    }
}

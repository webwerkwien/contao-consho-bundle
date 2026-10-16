<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustment;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustmentOrigin;

class PaymentAdjustmentTest extends TestCase
{
    public function testDieHerkunftTraegtDasVorzeichenNichtDerBetrag(): void
    {
        // C5: Nachlass oder Gebühr — die Rechnung beschriftet danach.
        $adjustment = new PaymentAdjustment('2', PaymentAdjustmentOrigin::Discount);

        self::assertSame('2.0000', $adjustment->net);
        self::assertSame(PaymentAdjustmentOrigin::Discount, $adjustment->origin);

        $this->expectException(\InvalidArgumentException::class);

        new PaymentAdjustment('-2', PaymentAdjustmentOrigin::Discount);
    }

    public function testEineNullAnpassungWirdAbgelehnt(): void
    {
        // Nichts hinzuzufügen oder abzuziehen heißt null zurückgeben, keine Null-Anpassung.
        $this->expectException(\InvalidArgumentException::class);

        new PaymentAdjustment('0', PaymentAdjustmentOrigin::Discount);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Voucher;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherCode;

class VoucherCodeTest extends TestCase
{
    public function testErzeugtSechzehnZeichenOhneVerwechselbare(): void
    {
        for ($i = 0; $i < 200; ++$i) {
            $code = VoucherCode::generate();

            self::assertSame(16, \strlen($code));
            self::assertSame(0, preg_match('/[01OIL]/', $code), $code);
            self::assertSame($code, VoucherCode::normalise($code));
        }
    }

    /**
     * Lower-case input is converted to upper case.
     */
    public function testDieEingabeWirdInGrossbuchstabenUmgewandelt(): void
    {
        self::assertSame('ABCDEFGHJKMNPQRS', VoucherCode::normalise(' abcd-efgh jkmn-pqrs '));
    }

    public function testWasKeinCodeSeinKannIstLeer(): void
    {
        self::assertSame('', VoucherCode::normalise('ABCD-EFGH-JKMN-PQR'), 'zu kurz');
        self::assertSame('', VoucherCode::normalise('ABCD-EFGH-JKMN-PQRST'), 'zu lang');
        self::assertSame('', VoucherCode::normalise('ABCD-EFGH-JKMN-PQR0'), 'Null gibt es nicht');
        self::assertSame('', VoucherCode::normalise('HERBST'), 'ein Rabattcode');
    }

    public function testAnzeigeInViererGruppenUndVerdeckt(): void
    {
        self::assertSame('ABCD-EFGH-JKMN-PQRS', VoucherCode::format('ABCDEFGHJKMNPQRS'));
        self::assertSame('…PQRS', VoucherCode::masked('ABCDEFGHJKMNPQRS'));
    }
}

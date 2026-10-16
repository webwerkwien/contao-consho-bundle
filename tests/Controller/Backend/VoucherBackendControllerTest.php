<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\VoucherBackendController;

/**
 * WG13: Was das Backend als Betrag und Datum annimmt.
 */
class VoucherBackendControllerTest extends TestCase
{
    public function testBetraegeMitKommaPunktUndVorzeichen(): void
    {
        self::assertSame('12.5000', VoucherBackendController::amount('12,50'));
        self::assertSame('12.5000', VoucherBackendController::amount(' 12.5 '));
        self::assertSame('-5.0000', VoucherBackendController::amount('-5'));
        self::assertSame('1000.0000', VoucherBackendController::amount('1 000'));
    }

    public function testKeinBetragIstKeinBetrag(): void
    {
        foreach (['', 'zehn', '12,505', '1.000,00', '1e3', '--5', '12,'] as $typed) {
            self::assertNull(VoucherBackendController::amount($typed), $typed);
        }
    }

    public function testDasDatumAlsMitternachtDerInstallation(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            self::assertSame((new \DateTimeImmutable('2027-03-31 00:00:00', new \DateTimeZone('Europe/Vienna')))->getTimestamp(), VoucherBackendController::day('2027-03-31'));
            self::assertNull(VoucherBackendController::day(''), 'leer = unbefristet');
            self::assertFalse(VoucherBackendController::day('2027-02-30'));
            self::assertFalse(VoucherBackendController::day('31.03.2027'));
        } finally {
            date_default_timezone_set($previous);
        }
    }
}

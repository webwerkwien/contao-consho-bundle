<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Voucher;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Voucher\SinglePurpose;

/**
 * EZ-4: the tax an amount paid with a single-purpose voucher carried is the
 * gross less its rounded net, as a line is split.
 */
final class SinglePurposeTest extends TestCase
{
    public function testDieSteuerIstBruttoMinusGerundetesNetto(): void
    {
        $voucher = new SinglePurpose(1, '20', 'at');

        // 11.25 / 1.2 = 9.375 → 9.38 net, so 1.87 tax (not 1.875 → 1.88).
        self::assertSame('1.8700', $voucher->taxIn('11.25', 2));
        self::assertSame('3.7500', $voucher->taxIn('22.50', 2));
        self::assertSame('0.0000', $voucher->taxIn('0', 2));
        // Without decimals (JPY): 1000 / 1.2 = 833.33… → 833.
        self::assertSame('167.0000', $voucher->taxIn('1000', 0));
    }
}

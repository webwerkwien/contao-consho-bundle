<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingQuote;

class ShippingQuoteTest extends TestCase
{
    public function testDerBetragHatVierStellen(): void
    {
        self::assertSame('4.0833', (new ShippingQuote('4.08333'))->net);
        self::assertSame('0.0000', (new ShippingQuote('0'))->net);
    }

    public function testEinNegativerVersandbetragIstUngueltig(): void
    {
        // A discount does not belong in the shipping (H), it would be an amount of its own.
        $this->expectException(\InvalidArgumentException::class);

        new ShippingQuote('-1');
    }
}

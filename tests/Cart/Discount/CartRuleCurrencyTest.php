<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart\Discount;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartRuleCurrency;

/**
 * CW-4b (H3): die Währungspflicht der Warenkorbregel ohne Anfrage — dieselbe
 * Regel für Maske und Befehl.
 */
class CartRuleCurrencyTest extends TestCase
{
    public function testEinFesterBetragBrauchtEineWaehrung(): void
    {
        self::assertTrue(CartRuleCurrency::isMissing('', 'amount', ''));
        self::assertTrue(CartRuleCurrency::isMissing('  ', 'amount', ''));
        self::assertFalse(CartRuleCurrency::isMissing('EUR', 'amount', ''));
    }

    public function testEinMindestbestellwertBrauchtEineWaehrung(): void
    {
        self::assertTrue(CartRuleCurrency::isMissing('', 'percent', '50'));
        self::assertTrue(CartRuleCurrency::isMissing('', 'percent', '0,01'));
        self::assertFalse(CartRuleCurrency::isMissing('', 'percent', '0'));
        self::assertFalse(CartRuleCurrency::isMissing('', 'percent', '0.0000'));
        self::assertFalse(CartRuleCurrency::isMissing('', 'percent', ''));
        self::assertFalse(CartRuleCurrency::isMissing('', 'percent', null));
        self::assertFalse(CartRuleCurrency::isMissing('', 'percent', 'viel'));
    }

    public function testProzentOhneMindestwertBrauchtKeine(): void
    {
        self::assertFalse(CartRuleCurrency::isMissing('', 'percent', '0'));
        self::assertFalse(CartRuleCurrency::isMissing('', 'shipping', ''));
    }
}

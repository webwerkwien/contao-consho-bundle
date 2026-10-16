<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;

/**
 * Was eine Adresse als Hinweis mitbringen darf (C-3c): nur Ablehnungen der
 * Kaufschaltfläche. „Hinzugefügt" und „zu viele Codes" stünden sonst auf jeder
 * Seite, deren Link jemand so weitergibt — und im Seiten-Cache.
 */
class CartNoticeTest extends TestCase
{
    public function testEineAblehnungWirdGelesen(): void
    {
        self::assertSame(CartNotice::OutOfStock, CartNotice::refusalFrom('out_of_stock'));
    }

    public function testHinzugefuegtUndZuVieleCodesNicht(): void
    {
        self::assertNull(CartNotice::refusalFrom('added'));
        self::assertNull(CartNotice::refusalFrom('too_many_codes'));
    }

    public function testKeinTextKeinHinweis(): void
    {
        self::assertNull(CartNotice::refusalFrom(['out_of_stock']));
        self::assertNull(CartNotice::refusalFrom('nonsense'));
    }
}

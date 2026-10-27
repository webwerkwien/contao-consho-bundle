<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;

/**
 * What an address may bring along as a notice (C-3c): only refusals of the
 * buy button. "Added" and "too many codes" would otherwise appear on every
 * page whose link someone passes on like that — and in the page cache.
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

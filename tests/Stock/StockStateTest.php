<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Stock;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Stock\StockState;

/**
 * Block G, G7: die eine Regel für den Zustand, und was ein Produkt mit offener
 * Variantenwahl behaupten darf.
 */
class StockStateTest extends TestCase
{
    public function testDieRegel(): void
    {
        $warning = BigDecimal::of(2);

        self::assertSame(StockState::Available, StockState::of(BigDecimal::of(3), $warning, false));
        self::assertSame(StockState::Few, StockState::of(BigDecimal::of(2), $warning, false));
        self::assertSame(StockState::Few, StockState::of(BigDecimal::of('0.5'), $warning, false));
        self::assertSame(StockState::Unavailable, StockState::of(BigDecimal::zero(), $warning, false));
        self::assertSame(StockState::Backorder, StockState::of(BigDecimal::of(-1), $warning, true));
        self::assertSame(StockState::Available, StockState::of(BigDecimal::of(1), BigDecimal::zero(), false), 'Schwelle 0: nie „wenige"');
    }

    public function testDasBesteZaehlt(): void
    {
        self::assertSame(StockState::Few, StockState::best([StockState::Unavailable, StockState::Few, StockState::Backorder]));
        self::assertSame(StockState::Backorder, StockState::best([StockState::Unavailable, StockState::Backorder]));
        self::assertSame(StockState::Unavailable, StockState::best([StockState::Unavailable]));
        self::assertSame(StockState::Available, StockState::best([]));
    }
}

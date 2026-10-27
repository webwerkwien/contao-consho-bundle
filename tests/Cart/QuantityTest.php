<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\Quantity;

class QuantityTest extends TestCase
{
    public function testEineGanzeZahlWirdAufVierStellenGebracht(): void
    {
        self::assertSame('3.0000', Quantity::normalise('3', '1', '1'));
    }

    public function testUnterDerMindestmengeIstUngueltig(): void
    {
        self::assertNull(Quantity::normalise('1', '2', '1'));
    }

    public function testNullUndNegativSindUngueltig(): void
    {
        self::assertNull(Quantity::normalise('0', '1', '1'));
        self::assertNull(Quantity::normalise('-1', '1', '1'));
    }

    public function testKeineZahlIstUngueltig(): void
    {
        self::assertNull(Quantity::normalise('drei', '1', '1'));
        self::assertNull(Quantity::normalise('', '1', '1'));
    }

    public function testEineMengeNebenDerSchrittweiteWirdAufgerundet(): void
    {
        // Rounded up, not refused: the visitor types 7, gets 8 and
        // sees that in the cart — being turned away does not help them.
        self::assertSame('8.0000', Quantity::normalise('7', '2', '2'));
        self::assertSame('2.0000', Quantity::normalise('2', '2', '2'));
    }

    public function testDieSchrittweiteZaehltAbDerMindestmenge(): void
    {
        // Minimum quantity 5, step 3 allows 5, 8, 11 — not 6.
        self::assertSame('8.0000', Quantity::normalise('6', '5', '3'));
        self::assertSame('5.0000', Quantity::normalise('5', '5', '3'));
    }

    public function testOhneSchrittweiteBleibtDieMengeWieSieIst(): void
    {
        self::assertSame('2.5000', Quantity::normalise('2.5', '0', '0'));
    }

    public function testMindestmengeOhneSchrittweitePrueftNurDieMindestmenge(): void
    {
        // Without a step size there is nothing to round up to — all that counts is whether
        // the minimum quantity is reached.
        self::assertSame('7.0000', Quantity::normalise('7', '5', '0'));
        self::assertSame('5.0000', Quantity::normalise('5', '5', '0'));
        self::assertNull(Quantity::normalise('3', '5', '0'));
    }

    public function testSchrittweiteOhneMindestmengeBeginntBeiDerSchrittweiteSelbst(): void
    {
        // A deliberate decision (see the class comment): without a minimum quantity
        // the steps count from the step size (2, 4, 6 …), not from 0 —
        // a quantity of 0 would already be invalid through the zero check anyway.
        self::assertSame('2.0000', Quantity::normalise('1', '0', '2'));
        self::assertSame('2.0000', Quantity::normalise('2', '0', '2'));
        self::assertSame('4.0000', Quantity::normalise('3', '0', '2'));
    }

    public function testEineUnsinnigGrosseMengeIstUngueltig(): void
    {
        // decimal(10,4) holds six digits before the decimal point — see
        // contao/dca/tl_consho_cart_item.php. Not decimal(12,4)
        // with eight digits; what counts is the column.
        self::assertNull(Quantity::normalise('100000000', '1', '1'));
        self::assertNull(Quantity::normalise('1000000', '1', '1'));
        self::assertSame('999999.0000', Quantity::normalise('999999', '1', '1'));
    }

    public function testMehrAlsVierNachkommastellenSindUngueltig(): void
    {
        // Not silently rounded to 1.0000: a quantity the column cannot
        // hold is not a quantity. The caller gets null and from it
        // the notice, instead of a server error from a MathException.
        self::assertNull(Quantity::normalise('1.00005', '0', '0'));
    }
}

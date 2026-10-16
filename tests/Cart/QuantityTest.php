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
        // Aufgerundet, nicht abgelehnt: der Besucher tippt 7, bekommt 8 und
        // sieht das im Warenkorb — abgewiesen zu werden hilft ihm nicht.
        self::assertSame('8.0000', Quantity::normalise('7', '2', '2'));
        self::assertSame('2.0000', Quantity::normalise('2', '2', '2'));
    }

    public function testDieSchrittweiteZaehltAbDerMindestmenge(): void
    {
        // Mindestmenge 5, Schritt 3 erlaubt 5, 8, 11 — nicht 6.
        self::assertSame('8.0000', Quantity::normalise('6', '5', '3'));
        self::assertSame('5.0000', Quantity::normalise('5', '5', '3'));
    }

    public function testOhneSchrittweiteBleibtDieMengeWieSieIst(): void
    {
        self::assertSame('2.5000', Quantity::normalise('2.5', '0', '0'));
    }

    public function testMindestmengeOhneSchrittweitePrueftNurDieMindestmenge(): void
    {
        // Ohne Schrittweite gibt es nichts zum Aufrunden — es zählt nur, ob
        // die Mindestmenge erreicht ist.
        self::assertSame('7.0000', Quantity::normalise('7', '5', '0'));
        self::assertSame('5.0000', Quantity::normalise('5', '5', '0'));
        self::assertNull(Quantity::normalise('3', '5', '0'));
    }

    public function testSchrittweiteOhneMindestmengeBeginntBeiDerSchrittweiteSelbst(): void
    {
        // Bewusste Entscheidung (siehe Klassenkommentar): ohne Mindestmenge
        // zählen die Schritte ab der Schrittweite (2, 4, 6 …), nicht ab 0 —
        // eine Menge von 0 wäre ohnehin schon durch die Null-Prüfung ungültig.
        self::assertSame('2.0000', Quantity::normalise('1', '0', '2'));
        self::assertSame('2.0000', Quantity::normalise('2', '0', '2'));
        self::assertSame('4.0000', Quantity::normalise('3', '0', '2'));
    }

    public function testEineUnsinnigGrosseMengeIstUngueltig(): void
    {
        // decimal(10,4) fasst sechs Vorkommastellen — siehe
        // contao/dca/tl_consho_cart_item.php. Der Plan nannte decimal(12,4)
        // und acht Stellen; maßgeblich ist die Spalte.
        self::assertNull(Quantity::normalise('100000000', '1', '1'));
        self::assertNull(Quantity::normalise('1000000', '1', '1'));
        self::assertSame('999999.0000', Quantity::normalise('999999', '1', '1'));
    }

    public function testMehrAlsVierNachkommastellenSindUngueltig(): void
    {
        // Nicht still auf 1.0000 gerundet: eine Menge, die die Spalte nicht
        // fassen kann, ist keine Menge. Der Aufrufer bekommt null und daraus
        // den Hinweis, statt eines Serverfehlers aus einer MathException.
        self::assertNull(Quantity::normalise('1.00005', '0', '0'));
    }
}

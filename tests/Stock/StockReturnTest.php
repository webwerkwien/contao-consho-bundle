<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Stock;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockAllocation;
use Webwerkwien\ContaoConshoBundle\Stock\StockBooking;
use Webwerkwien\ContaoConshoBundle\Stock\StockReturn;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block G, G9: Goods back into stock — never more than the row's purchase
 * click took, and not twice across cancellation and return together.
 *
 * Throughout with the real classes: the purchase click (StockAllocation)
 * deducts, then the goods come back.
 */
class StockReturnTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_stock', 'tl_consho_stock_movement', 'tl_consho_order', 'tl_consho_order_item', 'tl_consho_withdrawal_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product', ['id' => 1, 'stockTracked' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 2]);
        (new StockRows($this->connection))->sync(1, 100);
        $this->connection->update('tl_consho_stock', ['quantity' => '10.0000'], ['article' => 1]);

        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7']);
        $this->connection->insert('tl_consho_order', ['id' => 8, 'orderNumber' => 'WEB-8']);
        $this->connection->insert('tl_consho_order_item', ['id' => 10, 'pid' => 7, 'lineType' => 'product', 'product' => 1, 'quantity' => '3.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 11, 'pid' => 7, 'lineType' => 'product', 'product' => 2, 'quantity' => '1.0000']);
        (new StockAllocation($this->connection, new Availability($this->connection), $this->booking()))->bookOrder(7, 1000);
    }

    public function testDasStornoBringtDieWareZurueckGenauEinmal(): void
    {
        self::assertSame('7.0000', $this->stock());

        $this->returns()->afterCancellation(7, 2000);
        $this->returns()->afterCancellation(7, 2001);

        self::assertSame('10.0000', $this->stock());
        self::assertSame(['order', 'cancellation'], $this->reasons());
        // The cap stays; what the cancellation gave back is kept apart.
        self::assertSame(['3.0000', '3.0000'], array_map(StockBooking::scaled(...), array_values((array) $this->connection->fetchAssociative('SELECT stockBooked, stockCancelled FROM tl_consho_order_item WHERE id = 10'))));
    }

    /**
     * G-8: The cancellation from the back end was logged with user 0 ("Consho"),
     * although a back-end user had set it.
     */
    public function testDasStornoVermerktDenBenutzer(): void
    {
        $this->returns()->afterCancellation(7, 2000, 5);

        self::assertSame(5, (int) $this->connection->fetchOne("SELECT user FROM tl_consho_stock_movement WHERE reason = 'cancellation'"));
    }

    public function testEineBestellungVonVorDemBestandBringtNichtsZurueck(): void
    {
        $this->connection->insert('tl_consho_order_item', ['id' => 20, 'pid' => 8, 'lineType' => 'product', 'product' => 1, 'quantity' => '5.0000']);

        $this->returns()->afterCancellation(8, 2000);

        self::assertSame('7.0000', $this->stock());
    }

    public function testDieRuecksendungMitHaekchenUndKorrektur(): void
    {
        $this->withdrawalItem(30, returned: '2');

        self::assertSame('2.0000', $this->returns()->forWithdrawalItem(30, true, 2000, 5));
        self::assertSame('9.0000', $this->stock());

        // Corrected to 1: books the difference, not the row again.
        $this->connection->update('tl_consho_withdrawal_item', ['returnedQuantity' => '1.0000'], ['id' => 30]);
        self::assertSame('1.0000', $this->returns()->forWithdrawalItem(30, true, 2001, 5));
        self::assertSame('8.0000', $this->stock());

        // Checkbox off: what was booked back goes out again too.
        self::assertSame('0.0000', $this->returns()->forWithdrawalItem(30, false, 2002, 5));
        self::assertSame('7.0000', $this->stock());

        // Saved unchanged: no further movement.
        $this->returns()->forWithdrawalItem(30, false, 2003, 5);
        self::assertSame(['order', 'return', 'return', 'return'], $this->reasons());

        $movement = $this->connection->fetchAssociative("SELECT user, withdrawalId, orderId FROM tl_consho_stock_movement WHERE reason = 'return' ORDER BY id LIMIT 1");
        self::assertSame([5, 99, 7], array_map(intval(...), array_values((array) $movement)));
    }

    public function testNieMehrAlsDieZeileGenommenHat(): void
    {
        $this->withdrawalItem(30, returned: '9');

        self::assertSame('3.0000', $this->returns()->forWithdrawalItem(30, true, 2000));
        self::assertSame('10.0000', $this->stock());
    }

    public function testStornoNachRuecksendungZaehltDieRuecksendungAb(): void
    {
        $this->withdrawalItem(30, returned: '2');
        $this->returns()->forWithdrawalItem(30, true, 2000);

        $this->returns()->afterCancellation(7, 2001);

        self::assertSame('10.0000', $this->stock(), 'nicht 12: zwei Stück kamen schon über die Rücksendung');
    }

    /**
     * G: Return, cancellation, then "edit withdrawal" saved once more —
     * with the zeroed cap that booked goods OUT of stock.
     */
    public function testNachDemStornoBuchtDieRuecksendungNichtMehr(): void
    {
        $this->withdrawalItem(30, returned: '1');
        $this->returns()->forWithdrawalItem(30, true, 2000);
        $this->connection->update('tl_consho_order', ['cancelledAt' => 2001], ['id' => 7]);
        $this->returns()->afterCancellation(7, 2001);
        self::assertSame('10.0000', $this->stock());

        self::assertSame('1.0000', $this->returns()->forWithdrawalItem(30, true, 2002));
        self::assertSame('10.0000', $this->stock());
        self::assertSame(['order', 'return', 'cancellation'], $this->reasons());

        // The returned item is damaged — taking the checkbox off takes it out
        // again even after the cancellation …
        self::assertSame('0.0000', $this->returns()->forWithdrawalItem(30, false, 2003));
        self::assertSame('9.0000', $this->stock());

        // … and set again it comes back, never more than the return held.
        $this->connection->update('tl_consho_withdrawal_item', ['returnedQuantity' => '3.0000'], ['id' => 30]);
        self::assertSame('1.0000', $this->returns()->forWithdrawalItem(30, true, 2004));
        self::assertSame('10.0000', $this->stock());
    }

    private function withdrawalItem(int $id, string $returned): void
    {
        $this->connection->insert('tl_consho_withdrawal_item', ['id' => $id, 'pid' => 99, 'orderItem' => 10, 'quantity' => $returned, 'acceptedQuantity' => $returned, 'returnedQuantity' => $returned]);
    }

    private function returns(): StockReturn
    {
        return new StockReturn($this->connection, $this->booking());
    }

    private function booking(): StockBooking
    {
        return new StockBooking($this->connection);
    }

    private function stock(): string
    {
        return StockBooking::scaled($this->connection->fetchOne('SELECT quantity FROM tl_consho_stock WHERE article = 1'));
    }

    /**
     * @return list<string>
     */
    private function reasons(): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn('SELECT reason FROM tl_consho_stock_movement ORDER BY id'));
    }
}

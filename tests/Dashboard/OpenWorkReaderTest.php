<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dashboard;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Dashboard\OpenWorkReader;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalSignals;

/**
 * Dashboard DB3: "To do" counts with the same rule as the signals of the
 * order list, per shop.
 */
final class OpenWorkReaderTest extends TestCase
{
    private const NOW = 1_791_000_000;

    private const DAY = 86_400;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_shop', 'tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_complaint'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Eins']);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Zwei']);
        $this->connection->insert('tl_consho_order_status', ['id' => 9, 'isCancelled' => 1]);

        // 1 prepayment, not paid: unpaid, nothing to deliver.
        $this->order(1, 1);
        // 2 invoice, due date passed: unpaid, overdue, to deliver.
        $this->order(2, 1, ['paymentDueAt' => self::NOW - self::DAY]);
        // 3 paid, not delivered: to deliver.
        $this->order(3, 1, ['paymentSettledAt' => self::NOW], pay: '50.0000');
        // 4 paid and delivered: nothing.
        $this->order(4, 1, ['paymentSettledAt' => self::NOW], pay: '50.0000', ship: true);
        // 5 cancelled via the status, cancelledAt empty: nothing.
        $this->order(5, 1, ['orderStatus' => 9]);
        // 6 in the other shop, paid, not delivered.
        $this->order(6, 2, ['paymentSettledAt' => self::NOW], pay: '50.0000');
        // 7 marked as paid, but only partly paid and delivered —
        // the preselection must not lose it.
        $this->order(7, 1, ['paymentSettledAt' => self::NOW], pay: '20.0000', ship: true);
    }

    public function testZaehltJeShopWieDieListe(): void
    {
        $work = $this->reader()->byShop(self::NOW);

        self::assertSame([1, 2], array_keys($work));
        self::assertSame(3, $work[1]->unpaid, 'Vorkasse, Rechnung, Teilzahlung');
        self::assertSame(1, $work[1]->overdue);
        self::assertSame(2, $work[1]->toDeliver, 'Rechnung und bezahlt — Vorkasse ohne Zahlung nicht');
        self::assertSame(0, $work[2]->unpaid);
        self::assertSame(1, $work[2]->toDeliver);
    }

    public function testWiderrufeUndReklamationen(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 3, 'number' => 1, 'state' => 'received', 'receivedAt' => self::NOW - 2 * self::DAY]);
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 4, 'number' => 1, 'state' => 'accepted', 'receivedAt' => self::NOW - 5 * self::DAY]);
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 7, 'number' => 1, 'state' => 'accepted', 'receivedAt' => self::NOW - 9 * self::DAY, 'refundedAt' => self::NOW]);
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 6, 'number' => 1, 'state' => 'rejected', 'receivedAt' => self::NOW - 12 * self::DAY]);
        $this->connection->insert('tl_consho_complaint', ['pid' => 4, 'receivedAt' => self::NOW]);
        $this->connection->insert('tl_consho_complaint', ['pid' => 4, 'receivedAt' => self::NOW, 'resolvedAt' => self::NOW]);

        $work = $this->reader()->byShop(self::NOW);

        self::assertSame(1, $work[1]->undecided);
        self::assertSame(1, $work[1]->refundOpen, 'erstattet zählt nicht');
        // Each tile its own deadline — one of them once showed the other's date.
        self::assertSame(WithdrawalSignals::refundDue(self::NOW - 2 * self::DAY), $work[1]->undecidedDue);
        self::assertSame(WithdrawalSignals::refundDue(self::NOW - 5 * self::DAY), $work[1]->refundDue);
        self::assertSame(1, $work[1]->complaints);
        self::assertTrue($work[2]->isEmpty() === false);
        self::assertSame(0, $work[2]->undecided, 'abgelehnt ist erledigt');

        self::assertSame([4], $this->reader()->ids(OpenWorkReader::COMPLAINT, self::NOW));
        self::assertCount(1, $this->reader()->ids(OpenWorkReader::UNDECIDED, self::NOW));
        self::assertCount(1, $this->reader()->ids(OpenWorkReader::REFUND_OPEN, self::NOW));
        self::assertSame([], $this->reader()->ids(OpenWorkReader::UNDECIDED, self::NOW, 2), 'je Shop');
        self::assertSame([], $this->reader()->ids(OpenWorkReader::COMPLAINT, self::NOW, 2));
    }

    /**
     * WA, A5: a withdrawal without an order counts as to be decided for the shop it
     * came in through — in the figure and in the filtered list.
     */
    public function testNichtZugeordnetZaehltBeimShopDesFormulars(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 0, 'shop' => 2, 'number' => 'W-00001', 'state' => 'received', 'receivedAt' => self::NOW - self::DAY]);

        $work = $this->reader()->byShop(self::NOW);

        self::assertSame(1, $work[2]->undecided);
        self::assertSame(0, $work[1]->undecided);
        self::assertCount(1, $this->reader()->ids(OpenWorkReader::UNDECIDED, self::NOW, 2));
        self::assertSame([], $this->reader()->ids(OpenWorkReader::UNDECIDED, self::NOW, 1));
    }

    /**
     * Complaints belong to their shop; of several deadlines the earliest
     * counts, even if it was entered later; due exactly now is not yet
     * overdue.
     */
    public function testShopFristUndGrenze(): void
    {
        $this->connection->insert('tl_consho_complaint', ['pid' => 6, 'receivedAt' => self::NOW]);
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 3, 'number' => 1, 'state' => 'received', 'receivedAt' => self::NOW - 1 * self::DAY]);
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 3, 'number' => 2, 'state' => 'received', 'receivedAt' => self::NOW - 6 * self::DAY]);
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 3, 'number' => 3, 'state' => 'received', 'receivedAt' => self::NOW - 3 * self::DAY]);
        $this->order(8, 1, ['paymentDueAt' => self::NOW]);

        $work = $this->reader()->byShop(self::NOW);

        self::assertSame(0, $work[1]->complaints);
        self::assertSame(1, $work[2]->complaints);
        self::assertSame(WithdrawalSignals::refundDue(self::NOW - 6 * self::DAY), $work[1]->undecidedDue);
        self::assertSame(1, $work[1]->overdue, 'Bestellung 8 ist genau jetzt fällig, nicht überfällig');
        self::assertSame(4, $work[1]->unpaid);
    }

    /**
     * The reader counts a line with an empty type as goods
     * (OrderLineType::stored()); the preselection must not lose it.
     */
    public function testEineZeileOhneTypIstWare(): void
    {
        $this->order(9, 2, ['paymentSettledAt' => self::NOW], pay: '50.0000');
        $this->connection->update('tl_consho_order_item', ['lineType' => ''], ['pid' => 9]);

        self::assertSame([6, 9], $this->reader()->ids(OpenWorkReader::TO_DELIVER, self::NOW, 2));
    }

    public function testDieListeHinterDerKachelIstDieGezaehlte(): void
    {
        self::assertSame([1, 2, 7], $this->reader()->ids(OpenWorkReader::UNPAID, self::NOW));
        self::assertSame([2], $this->reader()->ids(OpenWorkReader::OVERDUE, self::NOW));
        self::assertSame([2, 3, 6], $this->reader()->ids(OpenWorkReader::TO_DELIVER, self::NOW));
        self::assertSame([6], $this->reader()->ids(OpenWorkReader::TO_DELIVER, self::NOW, 2), 'je Shop');
        self::assertSame([], $this->reader()->ids('unknown', self::NOW));
    }

    /**
     * @param array<string, int> $fields
     */
    private function order(int $id, int $shop, array $fields = [], string $pay = '', bool $ship = false): void
    {
        $this->connection->insert('tl_consho_order', ['id' => $id, 'shop' => $shop, 'orderNumber' => 'WEB-'.$id, 'grandTotal' => '50.0000', 'currency' => 'EUR', 'language' => 'de', ...$fields]);
        $this->connection->insert('tl_consho_order_item', ['id' => $id, 'pid' => $id, 'quantity' => '1.0000', 'lineType' => 'product']);

        if ('' !== $pay) {
            $this->connection->insert('tl_consho_payment', ['pid' => $id, 'amount' => $pay]);
        }

        if ($ship) {
            $this->connection->insert('tl_consho_delivery', ['id' => $id, 'pid' => $id, 'reportedAt' => self::NOW]);
            $this->connection->insert('tl_consho_delivery_item', ['pid' => $id, 'orderItem' => $id, 'quantity' => '1.0000']);
        }
    }

    private function reader(): OpenWorkReader
    {
        return new OpenWorkReader($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));
    }
}

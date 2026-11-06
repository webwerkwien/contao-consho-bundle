<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryContents;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * RK-5 (spec 3.3): a replacement delivery, reported or not, plays no part in
 * what an ordinary delivery can still carry.
 */
final class DeliveryContentsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7', 'grandTotal' => '60']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Wanderstock', 'quantity' => '2']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 7, 'productName' => 'Rucksack', 'quantity' => '1']);

        // One stick shipped; a replacement for it reported, another drafted.
        $this->delivery(3, reported: true, complaint: 0, items: [1 => '1']);
        $this->delivery(4, reported: true, complaint: 9, items: [1 => '1']);
        $this->delivery(5, reported: false, complaint: 9, items: [1 => '1', 2 => '1']);
    }

    public function testErsatzlieferungenMindernNichtWasOffenIst(): void
    {
        self::assertSame([1 => 1.0, 2 => 1.0], array_map(floatval(...), $this->contents()->available(7, 6)));
    }

    public function testDieUebersichtZaehltNurGewoehnlicheLieferungen(): void
    {
        $items = $this->contents()->account(7, 6)['items'];

        self::assertSame(['1', '0'], array_map(static fn (array $item): string => (string) (float) $item['shipped'], $items));
        self::assertSame(['0', '0'], array_map(static fn (array $item): string => (string) (float) $item['planned'], $items));
    }

    /**
     * S2: the complained quantity less the complaint's other replacement
     * deliveries, reported or drafted — never another complaint's.
     */
    public function testWasEineErsatzlieferungNochTragenKann(): void
    {
        $this->complaint(9, [1 => '2', 2 => '1']);
        $this->delivery(6, reported: true, complaint: 8, items: [1 => '1']);

        self::assertSame([], $this->contents()->replaceable(9, 0), 'gemeldet 4 und Entwurf 5 tragen alles');
        self::assertSame([1 => 1.0, 2 => 1.0], array_map(floatval(...), $this->contents()->replaceable(9, 5)), 'für den Entwurf 5 selbst');
        self::assertSame([1 => 1.0], array_map(floatval(...), $this->contents()->replaceable(9, 4)));
    }

    /**
     * S6: covered once the reported replacements reach every complained
     * quantity; a draft does not count.
     */
    public function testErsetztErstWennAllesGemeldetIst(): void
    {
        $this->complaint(9, [1 => '2', 2 => '1']);
        self::assertFalse($this->contents()->replaced(9));

        $this->connection->update('tl_consho_delivery', ['reportedAt' => 1001], ['id' => 5]);
        self::assertTrue($this->contents()->replaced(9));
        self::assertFalse($this->contents()->replaced(77), 'eine Reklamation ohne Positionen');
    }

    /**
     * @param array<int, string> $items
     */
    private function complaint(int $id, array $items): void
    {
        foreach ($items as $itemId => $quantity) {
            $this->connection->insert('tl_consho_complaint_item', ['pid' => $id, 'orderItem' => $itemId, 'quantity' => $quantity, 'replacementQuantity' => $quantity]);
        }
    }

    /**
     * @param array<int, string> $items
     */
    private function delivery(int $id, bool $reported, int $complaint, array $items): void
    {
        $this->connection->insert('tl_consho_delivery', ['id' => $id, 'pid' => 7, 'reportedAt' => $reported ? 1000 : null, 'complaint' => $complaint]);
        $this->contents()->replace($id, $items, 100);
    }

    private function contents(): DeliveryContents
    {
        return new DeliveryContents($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Withdrawal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalAssignment;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalWindowReader;

/**
 * "Assign order" (WA, A4): a withdrawal without an order gets its order —
 * only in its own shop, only once, and with the flag it would have got on
 * receipt.
 */
final class WithdrawalAssignmentTest extends TestCase
{
    private const RECEIVED = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        $this->connection->insert('tl_consho_shop', ['id' => 1]);
        $this->connection->insert('tl_consho_shop', ['id' => 2]);
        $this->connection->insert('tl_consho_order', ['id' => 7, 'shop' => 1, 'orderNumber' => 'WEB-00073']);
        $this->connection->insert('tl_consho_order', ['id' => 8, 'shop' => 2, 'orderNumber' => 'WEB-00080']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Wanderstock', 'quantity' => '1']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 5, 'pid' => 0, 'shop' => 1, 'number' => 'W-00005', 'receivedAt' => self::RECEIVED, 'orderReference' => 'WEB-0073', 'name' => 'E']);
    }

    public function testDieBestellungWirdGesetztDieErklaerungBleibt(): void
    {
        $order = $this->assignment()->findOrder(5, ' WEB-00073 ');

        self::assertSame(7, (int) ($order['id'] ?? 0));

        $result = $this->assignment()->assign(5, 7);

        self::assertTrue($result->done);
        self::assertSame(['assigned', ['W-00005', 'WEB-00073']], [$result->key, $result->params]);

        $row = $this->connection->fetchAssociative('SELECT pid, number, receivedAt, orderReference, afterWindow FROM tl_consho_withdrawal WHERE id = 5');

        self::assertIsArray($row);
        self::assertSame([7, 'W-00005', self::RECEIVED, 'WEB-0073', 0], [(int) $row['pid'], $row['number'], (int) $row['receivedAt'], $row['orderReference'], (int) $row['afterWindow']]);
    }

    /** The order of another shop is the contract of another merchant. */
    public function testNurImEigenenShop(): void
    {
        self::assertNull($this->assignment()->findOrder(5, 'WEB-00080'));

        $result = $this->assignment()->assign(5, 8);

        self::assertSame('assignNotFound', $result->key);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_withdrawal WHERE id = 5'));
    }

    /** Two editors at once: the second finds it done instead of reassigning. */
    public function testNurEinmal(): void
    {
        self::assertTrue($this->assignment()->assign(5, 7)->done);

        $this->connection->insert('tl_consho_order', ['id' => 9, 'shop' => 1, 'orderNumber' => 'WEB-00090']);
        $second = $this->assignment()->assign(5, 9);

        self::assertSame('assignAlready', $second->key);
        self::assertSame(7, (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_withdrawal WHERE id = 5'));
    }

    /**
     * The flag says what it would have said on receipt: at the time of
     * RECEIPT the order's window already lay behind it.
     */
    public function testDerSpaeteEingangWirdMarkiert(): void
    {
        $shipped = self::RECEIVED - 40 * 86400;
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => 7, 'shippedAt' => $shipped, 'reportedAt' => $shipped]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1']);

        $result = $this->assignment()->assign(5, 7);

        self::assertSame('assignedLate', $result->key);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT afterWindow FROM tl_consho_withdrawal WHERE id = 5'));
    }

    /**
     * WA: what belongs to no order leaves the open tasks — rejected with an
     * internal reason, the declaration stays as it came.
     */
    public function testOhneBestellungAbweisen(): void
    {
        self::assertSame('dismissReasonMissing', $this->assignment()->dismiss(5, '  ', 1_790_100_000)->key);

        $result = $this->assignment()->dismiss(5, ' Doppelt, siehe W-00004 ', 1_790_100_000);

        self::assertTrue($result->done);
        self::assertSame(['dismissed', ['W-00005']], [$result->key, $result->params]);
        self::assertSame(
            ['0', 'rejected', 'Doppelt, siehe W-00004', '1790100000', 'WEB-0073', 'E'],
            array_map('strval', $this->connection->fetchNumeric('SELECT pid, state, reason, decidedAt, orderReference, name FROM tl_consho_withdrawal WHERE id = 5') ?: []),
        );

        self::assertSame('alreadyDecided', $this->assignment()->dismiss(5, 'nochmal', 1_790_200_000)->key, 'zweimal ist einmal');
        self::assertSame('alreadyDecided', $this->assignment()->assign(5, 7)->key, 'abgewiesen landet nicht still auf einer Bestellung');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_withdrawal WHERE id = 5'));
    }

    public function testZugeordnetWirdNichtOhneBestellungAbgewiesen(): void
    {
        $this->assignment()->assign(5, 7);

        self::assertSame('assignAlready', $this->assignment()->dismiss(5, 'Grund', 1_790_100_000)->key);
        self::assertSame('received', $this->connection->fetchOne('SELECT state FROM tl_consho_withdrawal WHERE id = 5'));
    }

    private function assignment(): WithdrawalAssignment
    {
        return new WithdrawalAssignment(
            $this->connection,
            new WithdrawalWindowReader($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator())),
        );
    }
}

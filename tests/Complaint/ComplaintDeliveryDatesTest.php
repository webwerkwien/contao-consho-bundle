<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Complaint;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Complaint\ComplaintDeliveryDates;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * RK-6, A6: the delivery dates of a complaint's lines.
 */
final class ComplaintDeliveryDatesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        $this->connection->insert('tl_consho_complaint', ['id' => 5, 'pid' => 7, 'number' => 'WEB-00077-R1']);
        $this->connection->insert('tl_consho_complaint_item', ['id' => 10, 'pid' => 5, 'orderItem' => 1, 'quantity' => '2']);
        $this->connection->insert('tl_consho_complaint_item', ['id' => 11, 'pid' => 5, 'orderItem' => 2, 'quantity' => '1']);
    }

    /**
     * Reported deliveries only, a replacement delivery not; the shipping
     * date before the report; a line in two deliveries has both.
     */
    public function testGemeldeteLieferungenOhneErsatz(): void
    {
        $this->delivery(40, ['shippedAt' => 300, 'reportedAt' => 350], [1]);
        $this->delivery(41, ['shippedAt' => 0, 'reportedAt' => 100], [1]);
        $this->delivery(42, ['shippedAt' => 500], [2]);
        $this->delivery(43, ['shippedAt' => 600, 'reportedAt' => 600, 'complaint' => 5], [2]);

        self::assertSame([10 => [100, 300]], (new ComplaintDeliveryDates($this->connection))->forComplaint(5));
    }

    public function testTageVorDemEingang(): void
    {
        $received = strtotime('2026-11-05 09:00');

        self::assertSame(2, ComplaintDeliveryDates::daysBefore(strtotime('2026-11-03 23:30'), $received));
        self::assertSame(0, ComplaintDeliveryDates::daysBefore(strtotime('2026-11-05 18:00'), $received));
        self::assertSame(-1, ComplaintDeliveryDates::daysBefore(strtotime('2026-11-06 00:10'), $received));
    }

    /**
     * @param array<string, int> $values
     * @param list<int>          $orderItems
     */
    private function delivery(int $id, array $values, array $orderItems): void
    {
        $this->connection->insert('tl_consho_delivery', ['id' => $id, 'pid' => 7] + $values);

        foreach ($orderItems as $orderItem) {
            $this->connection->insert('tl_consho_delivery_item', ['pid' => $id, 'orderItem' => $orderItem, 'quantity' => '1']);
        }
    }
}

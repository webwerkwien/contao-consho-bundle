<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Complaint;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Complaint\ReplacementDelivery;
use Webwerkwien\ContaoConshoBundle\Order\DbalPaymentChangeRepository;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryContents;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * "Ersatzlieferung anlegen" (RK-5, S2): a delivery with the complaint's
 * reference, prefilled with what is still to be replaced.
 */
final class ReplacementDeliveryTest extends TestCase
{
    private const NOW = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7', 'grandTotal' => '60']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Wanderstock', 'quantity' => '2']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 7, 'productName' => 'Rucksack', 'quantity' => '1']);
        $this->connection->insert('tl_consho_complaint', ['id' => 5, 'pid' => 7, 'number' => 'WEB-7-R1', 'state' => 'inProgress', 'remedy' => 'replacement', 'decidedAt' => self::NOW - 10]);
        // U8: the replacement decided for all of it.
        $this->connection->insert('tl_consho_complaint_item', ['pid' => 5, 'orderItem' => 1, 'quantity' => '2', 'replacementQuantity' => '2']);
        $this->connection->insert('tl_consho_complaint_item', ['pid' => 5, 'orderItem' => 2, 'quantity' => '1', 'replacementQuantity' => '1']);
    }

    public function testLegtDieLieferungMitDemRestAn(): void
    {
        $result = $this->service()->create(5, self::NOW);

        self::assertTrue($result->done, $result->key);
        self::assertSame('replacementCreated', $result->key);
        $deliveryId = (int) $result->params[1];
        $delivery = $this->connection->fetchAssociative('SELECT pid, complaint, shippedAt, reportedAt FROM tl_consho_delivery WHERE id = ?', [$deliveryId]);
        self::assertSame([7, 5, self::NOW, null], [(int) $delivery['pid'], (int) $delivery['complaint'], (int) $delivery['shippedAt'], $delivery['reportedAt']]);
        self::assertSame('WEB-7-R1', $result->params[0]);
        self::assertSame([1 => 2.0, 2 => 1.0], array_map(floatval(...), $this->contents()->current($deliveryId)));

        // Everything is in that draft now: a second one has nothing to carry.
        self::assertSame('nothingToReplace', $this->service()->create(5, self::NOW + 1)->key);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function keinAustausch(): iterable
    {
        yield 'nicht entschieden' => [['remedy' => '', 'decidedAt' => null, 'state' => 'open'], 'notReplacement'];
        yield 'Rücktritt' => [['remedy' => 'rescission'], 'notReplacement'];
        yield 'erledigt' => [['state' => 'resolved', 'resolvedAt' => self::NOW], 'alreadyDecided'];
        yield 'abgelehnt' => [['state' => 'rejected'], 'alreadyDecided'];
    }

    /**
     * @param array<string, mixed> $complaint
     *
     * @dataProvider keinAustausch
     */
    public function testNurFuerEinenFestgelegtenAustausch(array $complaint, string $key): void
    {
        $this->connection->update('tl_consho_complaint', $complaint, ['id' => 5]);

        self::assertSame($key, $this->service()->create(5, self::NOW)->key);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_delivery'));
    }

    public function testUnbekannteReklamation(): void
    {
        self::assertSame('unknown', $this->service()->create(99, self::NOW)->key);
    }

    private function service(): ReplacementDelivery
    {
        return new ReplacementDelivery($this->connection, new DbalPaymentChangeRepository($this->connection), $this->contents());
    }

    private function contents(): DeliveryContents
    {
        return new DeliveryContents($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));
    }
}

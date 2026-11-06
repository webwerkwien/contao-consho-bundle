<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Complaint;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block Reklamation (3.1): the complaint gets a number, a state and a remedy,
 * and lines of its own; a replacement delivery points back to it.
 */
final class ComplaintSchemaTest extends TestCase
{
    public function testComplaintWithNumberStateAndLines(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_complaint');
        DcaSchema::create($connection, 'tl_consho_complaint_item');

        $connection->insert('tl_consho_complaint', ['pid' => 7, 'number' => 'WEB-00077-R1', 'receivedAt' => 1]);
        $row = $connection->fetchAssociative('SELECT * FROM tl_consho_complaint');
        self::assertIsArray($row);
        self::assertSame('open', $row['state']);
        self::assertSame('', $row['remedy']);
        self::assertNull($row['decidedAt']);
        self::assertNull($row['rejectReason']);
        // RK-4: the refund fields, named as at a withdrawal, and nothing refunded yet.
        self::assertNull($row['refundedAt']);
        self::assertNull($row['refundAmount']);
        self::assertEquals(0, $row['refundShipping']);
        self::assertArrayNotHasKey('returnCosts', $row, 'return postage is settled outside the shop');
        self::assertEquals(0, $row['refundPayment']);
        self::assertEquals(0, $row['refundDocument']);

        $connection->insert('tl_consho_complaint_item', ['pid' => 1, 'orderItem' => 3, 'quantity' => '1.0000', 'defect' => 'Lid cracked']);
        $item = $connection->fetchAssociative('SELECT * FROM tl_consho_complaint_item');
        self::assertIsArray($item);
        self::assertEquals(0, $item['returnedQuantity']);
        self::assertEquals(0, $item['restockedQuantity']);
        self::assertNull($item['reduction']);
        // RK-4: nothing rescinded until decided; defective goods not back into stock (R6).
        self::assertEquals(0, $item['rescindedQuantity']);
        self::assertEquals(0, $item['restock']);

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert('tl_consho_complaint', ['pid' => 7, 'number' => 'WEB-00077-R1', 'receivedAt' => 2]);
    }

    public function testSameNumberUnderAnotherOrder(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_complaint');

        $connection->insert('tl_consho_complaint', ['pid' => 7, 'number' => 'WEB-00077-R1']);
        $connection->insert('tl_consho_complaint', ['pid' => 8, 'number' => 'WEB-00077-R1']);

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM tl_consho_complaint'));
    }

    public function testOrdinaryDeliveryHasNoComplaint(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_delivery');
        $connection->insert('tl_consho_delivery', ['pid' => 7]);

        self::assertSame(0, (int) $connection->fetchOne('SELECT complaint FROM tl_consho_delivery'));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderWriter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Every order is written with the shop's customer type and withdrawal period
 * frozen on it, so a later change at the shop does not move its window.
 */
class DbalOrderWriterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_order');

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'tstamp' => 1, 'customerType' => 'business', 'withdrawalDays' => 0, 'withdrawalGraceDays' => 0]);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'tstamp' => 1, 'customerType' => 'consumer', 'withdrawalDays' => 30, 'withdrawalGraceDays' => 5]);
    }

    public function testDieWerteDesShopsWerdenEingefroren(): void
    {
        $writer = new DbalOrderWriter($this->connection);

        self::assertSame(['business', 0, 0], $this->frozen($writer->createOrder(['shop' => 1, 'tstamp' => 1])));
        self::assertSame(['consumer', 30, 5], $this->frozen($writer->createOrder(['shop' => 2, 'tstamp' => 1])));

        // A later change at the shop leaves the order as it was.
        $this->connection->update('tl_consho_shop', ['withdrawalDays' => 14], ['id' => 2]);
        self::assertSame(['consumer', 30, 5], $this->frozen(2));
    }

    public function testWasDerAufruferMitgibtGilt(): void
    {
        $id = (new DbalOrderWriter($this->connection))->createOrder(['shop' => 2, 'tstamp' => 1, 'withdrawalDays' => 14]);

        self::assertSame(['consumer', 14, 5], $this->frozen($id));
    }

    public function testOhneShopBleibtNull(): void
    {
        $id = (new DbalOrderWriter($this->connection))->createOrder(['shop' => 9, 'tstamp' => 1]);

        $row = $this->connection->fetchAssociative('SELECT customerType, withdrawalDays FROM tl_consho_order WHERE id = ?', [$id]);
        self::assertSame(['customerType' => null, 'withdrawalDays' => null], $row);
    }

    /**
     * @return array{string, int, int}
     */
    private function frozen(int $orderId): array
    {
        $row = $this->connection->fetchAssociative('SELECT customerType, withdrawalDays, withdrawalGraceDays FROM tl_consho_order WHERE id = ?', [$orderId]);
        self::assertIsArray($row);

        return [(string) $row['customerType'], (int) $row['withdrawalDays'], (int) $row['withdrawalGraceDays']];
    }
}

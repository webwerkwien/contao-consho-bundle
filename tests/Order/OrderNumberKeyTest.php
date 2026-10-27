<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Order numbers are unique per shop, like the number ranges
 * (tl_consho_number_range.pid) and the documents.
 */
final class OrderNumberKeyTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        $this->connection->insert('tl_consho_order', ['id' => 1, 'shop' => 1, 'orderNumber' => 'WEB-00001']);
    }

    public function testEinKopierterShopDarfDieselbeNummerVergeben(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 2, 'shop' => 2, 'orderNumber' => 'WEB-00001']);

        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_order WHERE orderNumber = 'WEB-00001'"));
    }

    public function testImSelbenShopBleibtDieNummerEindeutig(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->insert('tl_consho_order', ['id' => 2, 'shop' => 1, 'orderNumber' => 'WEB-00001']);
    }
}

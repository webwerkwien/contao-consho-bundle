<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Bestellnummern sind je Shop eindeutig, wie die Nummernkreise
 * (tl_consho_number_range.pid) und die Belege. Michael, 2026-10-07:
 * „ja, Bestellnummer je Shop wie empfohlen".
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Migration\SoldOutMarksMigration;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\SoldOutMarks;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block availability, V1: once after the table arrives — by the
 * marker, not by the empty table.
 */
class SoldOutMarksMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
    }

    public function testOhneTabellenNicht(): void
    {
        self::assertFalse($this->migration()->shouldRun());
    }

    public function testLaeuftEinmalAuchWennNichtsAusverkauftIst(): void
    {
        $this->schema();
        $this->connection->insert('tl_consho_product', ['id' => 1, 'title' => 'Karte', 'published' => 1]);

        self::assertTrue($this->migration()->shouldRun());
        $this->migration()->run();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_sold_out'));
        self::assertFalse($this->migration()->shouldRun(), 'der Merker zählt, nicht die leere Tabelle');
    }

    public function testMarkiertBeimErstenLauf(): void
    {
        $this->schema();
        $this->connection->insert('tl_consho_product', ['id' => 1, 'title' => 'Stock', 'stockTracked' => 1, 'published' => 1]);

        $this->migration()->run();

        self::assertSame([1], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out')));
    }

    private function schema(): void
    {
        foreach ([
            'tl_consho_sold_out', 'tl_consho_projection_state', 'tl_consho_product', 'tl_consho_product_type', 'tl_consho_stock', 'tl_consho_key',
            'tl_consho_order', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item',
        ] as $table) {
            DcaSchema::create($this->connection, $table);
        }
    }

    private function migration(): SoldOutMarksMigration
    {
        return new SoldOutMarksMigration(
            $this->connection,
            new SoldOutMarks($this->connection, new Availability($this->connection)),
            new ProjectionState($this->connection),
        );
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cron;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cron\SoldOutCron;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\SoldOutMarks;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block Verfügbarkeit, V1: das stündliche Netz.
 */
class SoldOutCronTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
    }

    /**
     * Gemessen auf c5 beim Deploy (2026-10-09 11:21): Zwischen Entpacken und
     * contao:migrate lief der Cron, die Tabelle fehlte, und der Fehler nahm
     * den ganzen Cron-Lauf der Minute mit.
     */
    public function testOhneTabelleTutErNichts(): void
    {
        $this->cron()();

        $this->addToAssertionCount(1);
    }

    public function testBautDieMarkenNeuAuf(): void
    {
        foreach ([
            'tl_consho_sold_out', 'tl_consho_product', 'tl_consho_product_type', 'tl_consho_stock', 'tl_consho_key',
            'tl_consho_order', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item',
        ] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product', ['id' => 1, 'title' => 'Stock', 'stockTracked' => 1, 'published' => 1]);

        $this->cron()();

        self::assertSame([1], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out')));
    }

    private function cron(): SoldOutCron
    {
        return new SoldOutCron(new SoldOutMarks($this->connection, new Availability($this->connection)), $this->connection);
    }
}

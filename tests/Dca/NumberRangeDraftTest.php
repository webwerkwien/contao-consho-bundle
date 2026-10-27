<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #3 (version 0.1.3): with an order number in the shop, every further
 * "New" in the number ranges failed with
 * `Duplicate entry '1-order' for key 'pid_type'`.
 *
 * On "New", Contao immediately creates a draft, with only `pid` and the
 * column defaults — and the type had the default `order`. So the draft was
 * a second order number before anybody had chosen a type. The reporter's
 * workaround: create the order number last.
 *
 * The schema comes from the DCA file (DcaSchema); the draft is inserted the
 * way DC_Table::create() does it: without `type`.
 */
class NumberRangeDraftTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_number_range');
        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'tstamp' => 1_000, 'type' => 'order', 'prefix' => 'B-']);
    }

    public function testNeuNebenEinerBestellnummerScheitertNicht(): void
    {
        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'tstamp' => 0]);
        // A second open draft, say from a second tab.
        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'tstamp' => 0]);

        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_number_range WHERE pid = 1'));
    }

    public function testZweiBestellnummernImSelbenShopBleibenAusgeschlossen(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'tstamp' => 1_000, 'type' => 'order', 'prefix' => 'X-']);
    }
}

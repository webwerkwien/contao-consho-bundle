<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Migration\ComplaintNumberMigration;

/**
 * Block Reklamation (3.1): every complaint note becomes a complaint with a
 * number and a state, before the unique key (pid, number) can be created.
 */
final class ComplaintNumberMigrationTest extends TestCase
{
    public function testNumbersPerOrderByReceiptAndSetsTheState(): void
    {
        $connection = $this->before();
        $connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-00077']);
        $connection->insert('tl_consho_order', ['id' => 8, 'orderNumber' => 'WEB-00078']);
        $connection->insert('tl_consho_complaint', ['id' => 1, 'pid' => 7, 'receivedAt' => 200, 'note' => 'second']);
        $connection->insert('tl_consho_complaint', ['id' => 2, 'pid' => 7, 'receivedAt' => 100, 'note' => 'first', 'resolvedAt' => 150]);
        $connection->insert('tl_consho_complaint', ['id' => 3, 'pid' => 8, 'receivedAt' => 300, 'note' => 'other', 'resolvedAt' => 0]);

        $migration = new ComplaintNumberMigration($connection);
        self::assertTrue($migration->shouldRun());
        $migration->run();

        $rows = $connection->fetchAllAssociativeIndexed('SELECT id, number, state, note, resolvedAt FROM tl_consho_complaint');
        self::assertSame('WEB-00077-R1', $rows[2]['number'], 'by receipt, not by id');
        self::assertSame('WEB-00077-R2', $rows[1]['number']);
        self::assertSame('WEB-00078-R1', $rows[3]['number']);
        self::assertSame('resolved', $rows[2]['state']);
        self::assertSame('open', $rows[1]['state']);
        self::assertSame('open', $rows[3]['state'], 'resolvedAt 0 is open, as the dashboard counted it');
        self::assertSame('first', $rows[2]['note'], 'the text stays');
        self::assertEquals(150, $rows[2]['resolvedAt'], 'the date stays');
        self::assertFalse($migration->shouldRun(), 'never again');
    }

    public function testAComplaintWhoseOrderIsGoneStillGetsANumber(): void
    {
        $connection = $this->before();
        $connection->insert('tl_consho_complaint', ['id' => 1, 'pid' => 9, 'receivedAt' => 1, 'note' => 'x']);
        $connection->insert('tl_consho_complaint', ['id' => 2, 'pid' => 9, 'receivedAt' => 2, 'note' => 'y']);

        (new ComplaintNumberMigration($connection))->run();

        self::assertSame(['C9-R1', 'C9-R2'], $connection->fetchFirstColumn('SELECT number FROM tl_consho_complaint ORDER BY id'));
    }

    public function testDoesNotRunWithoutTheTable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertFalse((new ComplaintNumberMigration($connection))->shouldRun());
    }

    /**
     * The table as it was before the block: a note with dates, nothing else.
     */
    private function before(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE tl_consho_order (id INTEGER PRIMARY KEY, orderNumber VARCHAR(64))');
        $connection->executeStatement('CREATE TABLE tl_consho_complaint (id INTEGER PRIMARY KEY, pid INTEGER, tstamp INTEGER DEFAULT 0, receivedAt INTEGER, note TEXT, resolvedAt INTEGER DEFAULT NULL)');

        return $connection;
    }
}

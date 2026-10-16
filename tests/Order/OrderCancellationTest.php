<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\OrderCancellation;
use Webwerkwien\ContaoConshoBundle\Stock\StockReturn;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherReturn;

/**
 * Nachlese K6: Was ein Storno zurückbaut, steht an einer Stelle. Vorher
 * führten der automatische Statuswechsel und die Backend-Maske je eine eigene
 * Liste, und jeder neue Rückbau musste an beide.
 */
final class OrderCancellationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        DcaSchema::create($this->connection, 'tl_consho_order');
        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-1']);
    }

    public function testEinmalJeBestellungMitBenutzer(): void
    {
        $stock = $this->createMock(StockReturn::class);
        $stock->expects(self::once())->method('afterCancellation')->with(7, 1000, 3);
        $vouchers = $this->createMock(VoucherReturn::class);
        $vouchers->expects(self::once())->method('afterCancellation')->with(7, 1000);

        $cancellation = new OrderCancellation($this->connection, stock: $stock, vouchers: $vouchers);

        self::assertTrue($cancellation->record(7, 1000, 3));
        self::assertFalse($cancellation->record(7, 2000, 3), 'schon storniert');
        self::assertSame(1000, (int) $this->connection->fetchOne('SELECT cancelledAt FROM tl_consho_order WHERE id = 7'));
    }

    /**
     * Der Wächter: Außer OrderCancellation ruft niemand einen Rückbau auf.
     */
    public function testNurEineStelleRuftDieRueckbauten(): void
    {
        $callers = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && str_contains((string) file_get_contents($file->getPathname()), '->afterCancellation(')) {
                $callers[] = $file->getFilename();
            }
        }

        self::assertSame(['OrderCancellation.php'], $callers);
    }
}

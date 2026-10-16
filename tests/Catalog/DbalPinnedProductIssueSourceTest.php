<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalPinnedProductIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalPinnedProductIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement("CREATE TABLE tl_content (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT '', conshoProduct INTEGER NOT NULL DEFAULT 0)");
        DcaSchema::create($this->connection, 'tl_consho_product');
        $this->connection->insert('tl_consho_product', ['id' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);
    }

    public function testNothingToReportWhileEveryPinFindsItsProduct(): void
    {
        $this->pin(1);
        // Ein anderes Element mit derselben Spalte ist keine Anheftung.
        $this->connection->insert('tl_content', ['type' => 'text', 'conshoProduct' => 99]);

        self::assertSame([], (new DbalPinnedProductIssueSource($this->connection))->issues());
    }

    public function testCountsPinsOfDeletedProductsAndOfVariants(): void
    {
        $this->pin(1);
        $this->pin(99);
        $this->pin(2);

        $issues = (new DbalPinnedProductIssueSource($this->connection))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::PinnedProductMissing, $issues[0]->finding);
        self::assertSame(['count' => '2'], $issues[0]->context);
    }

    public function testItIsANoteThatCanBeAcknowledged(): void
    {
        // Der Shop funktioniert weiter — die Anheftung rendert nur nichts.
        self::assertFalse(ConfigurationFinding::PinnedProductMissing->isWarning());
    }

    private function pin(int $product): void
    {
        $this->connection->insert('tl_content', ['type' => 'consho_product_pin', 'conshoProduct' => $product]);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Migration\StockRowsMigration;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block G, Deploy auf c5: Produkte von vor G bekommen ihre Bestandszeilen —
 * ein Schlüsselvorrat aus F zählte sonst bis zum nächsten Speichern als
 * unbegrenzt.
 */
class StockRowsMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_stock'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'digital']);
        // Schlüssel am Hauptprodukt, Schlüssel nur an der Variante, Datei, digitales mit Häkchen (ergibt keine Zeile).
        $this->connection->insert('tl_consho_product', ['id' => 1, 'productType' => 1, 'digitalDelivery' => 'key']);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'productType' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'pid' => 2, 'digitalDelivery' => 'key']);
        $this->connection->insert('tl_consho_product', ['id' => 4, 'productType' => 1, 'digitalDelivery' => 'file']);
        $this->connection->insert('tl_consho_product', ['id' => 5, 'productType' => 1, 'stockTracked' => 1]);
        // Körperlich mit Bestand, körperlich ohne.
        $this->connection->insert('tl_consho_product', ['id' => 6, 'stockTracked' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 7]);
    }

    public function testLegtDieZeilenEinmalAn(): void
    {
        $migration = new StockRowsMigration($this->connection, new StockRows($this->connection));

        self::assertTrue($migration->shouldRun());
        self::assertTrue($migration->run()->isSuccessful());

        self::assertSame(
            [[1, 'keys'], [3, 'keys'], [6, 'manual']],
            array_map(static fn (array $row): array => [(int) $row['article'], (string) $row['source']], $this->connection->fetchAllAssociative('SELECT article, source FROM tl_consho_stock WHERE active = 1 ORDER BY article')),
        );
        self::assertFalse($migration->shouldRun(), 'kommt nicht bei jedem Lauf wieder');
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Stock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block G, G1: one stock row per article of a physical product with
 * "manage stock" — never deleted, only retired.
 */
class StockRowsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        DcaSchema::create($this->connection, 'tl_consho_stock');
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'physical']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'pid' => 0, 'productType' => 1, 'stockTracked' => 1]);
    }

    public function testEinProduktOhneVariantenIstSelbstDerArtikel(): void
    {
        $this->rows()->sync(1, 100);

        self::assertSame([[1, 1, 1]], $this->stock());
    }

    public function testMitVariantenHatJedeVarianteIhreZeile(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'pid' => 1]);

        // From the variant just as from the main product.
        $this->rows()->sync(3, 100);

        self::assertSame([[2, 1, 1], [3, 1, 1]], $this->stock());
    }

    public function testKommenVariantenDazuWirdDasHauptproduktStillgelegt(): void
    {
        $this->rows()->sync(1, 100);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);

        $this->rows()->sync(2, 101);

        self::assertSame([[1, 1, 0], [2, 1, 1]], $this->stock());
    }

    public function testAbgeschaltetBleibtDieZeileUndIhrBestand(): void
    {
        $this->rows()->sync(1, 100);
        $this->connection->update('tl_consho_stock', ['quantity' => '7.0000'], ['article' => 1]);
        $this->connection->update('tl_consho_product', ['stockTracked' => 0], ['id' => 1]);
        $this->rows()->sync(1, 101);

        self::assertSame([[1, 1, 0]], $this->stock());
        self::assertNull($this->rows()->activeRow(1));

        // Switched on again: the same row, the same stock.
        $this->connection->update('tl_consho_product', ['stockTracked' => 1], ['id' => 1]);
        $this->rows()->sync(1, 101);

        self::assertSame('7', rtrim(rtrim((string) $this->rows()->activeRow(1)['quantity'], '0'), '.'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock'));
    }

    public function testZweimalInDerselbenSekundeLegtKeineZweiteZeileAn(): void
    {
        $this->rows()->sync(1, 100);
        $this->rows()->sync(1, 100);

        self::assertSame([[1, 1, 1]], $this->stock());
    }

    public function testDigitaleWareFuehrtKeinenBestand(): void
    {
        $this->connection->update('tl_consho_product', ['productType' => 2], ['id' => 1]);

        $this->rows()->sync(1, 100);

        self::assertSame([], $this->stock());
    }

    public function testOhneTypKoerperlich(): void
    {
        $this->connection->update('tl_consho_product', ['productType' => 0], ['id' => 1]);

        $this->rows()->sync(1, 100);

        self::assertSame([[1, 1, 1]], $this->stock());
    }

    public function testGeloeschtWirdStillgelegtSamtVarianten(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);
        $this->rows()->sync(1, 100);

        $this->rows()->deactivate(1, 101);

        self::assertSame([[2, 1, 0]], $this->stock());
    }

    private function rows(): StockRows
    {
        return new StockRows($this->connection);
    }

    /**
     * @return list<array{int, int, int}>
     */
    private function stock(): array
    {
        return array_map(
            static fn (array $row): array => [(int) $row['article'], (int) $row['main'], (int) $row['active']],
            $this->connection->fetchAllAssociative('SELECT article, main, active FROM tl_consho_stock ORDER BY article'),
        );
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductShops;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductShopsRefused;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Connect stage 2, CW-3 (F3): the shop assignment of a product without the
 * mask. assign() writes what the mask has already limited through its
 * options; set() checks what a command brings without a form.
 */
class ProductShopsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'A']);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'B']);
        $this->connection->insert('tl_consho_shop', ['id' => 3, 'title' => 'C']);
        $this->connection->insert('tl_consho_product', ['id' => 5, 'pid' => 0]);
        $this->connection->insert('tl_consho_product', ['id' => 6, 'pid' => 5]);
    }

    public function testSetztUndNimmtWeg(): void
    {
        $this->shops()->set(5, [1, 2]);
        self::assertSame([1, 2], $this->shops()->of(5));

        $this->shops()->set(5, [2, 3]);
        self::assertSame([2, 3], $this->shops()->of(5));

        $this->shops()->set(5, []);
        self::assertSame([], $this->shops()->of(5));
    }

    /**
     * A difference, not a rewrite: unchanged rows stay in place.
     */
    public function testUnveraenderteZuordnungBleibtStehen(): void
    {
        $this->shops()->set(5, [1]);
        $id = $this->connection->fetchOne('SELECT id FROM tl_consho_product_shop WHERE pid = 5 AND shop = 1');

        $this->shops()->set(5, [1, 2]);

        self::assertSame($id, $this->connection->fetchOne('SELECT id FROM tl_consho_product_shop WHERE pid = 5 AND shop = 1'));
    }

    public function testEinUnbekannterShopWirdAbgelehnt(): void
    {
        $this->expectException(ProductShopsRefused::class);
        $this->expectExceptionMessage('9');

        $this->shops()->set(5, [1, 9]);
    }

    /**
     * Specification A 3.7: a variant may narrow the shops of its main product,
     * never widen them.
     */
    public function testEineVarianteNurInnerhalbDesHauptprodukts(): void
    {
        $this->shops()->set(5, [1, 2]);
        $this->shops()->set(6, [2]);
        self::assertSame([2], $this->shops()->of(6));

        $this->expectException(ProductShopsRefused::class);
        $this->expectExceptionMessage('3');

        $this->shops()->set(6, [2, 3]);
    }

    public function testEinUnbekanntesProduktWirdAbgelehnt(): void
    {
        $this->expectException(ProductShopsRefused::class);

        $this->shops()->set(99, [1]);
    }

    public function testDoppelteUndNullWerdenIgnoriert(): void
    {
        $this->shops()->assign(5, [1, 1, 0]);

        self::assertSame([1], $this->shops()->of(5));
    }

    private function shops(): ProductShops
    {
        return new ProductShops($this->connection);
    }
}

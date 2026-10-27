<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductShops;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ProductShopsListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The shop selection of the product form: options, loading, saving — since
 * CW-3 via ProductShops.
 */
class ProductShopsListenerTest extends TestCase
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
        $this->connection->insert('tl_consho_product', ['id' => 5, 'pid' => 0]);
        $this->connection->insert('tl_consho_product', ['id' => 6, 'pid' => 5]);
    }

    public function testSpeichertDieAuswahlUndLaedtSie(): void
    {
        $this->listener()->save(serialize(['2', '1']), $this->dc(5));
        self::assertSame(['1', '2'], $this->listener()->load(null, $this->dc(5)));

        $this->listener()->save(serialize(['2']), $this->dc(5));
        self::assertSame(['2'], $this->listener()->load(null, $this->dc(5)));
    }

    public function testEineVarianteBekommtNurDieShopsDesHauptprodukts(): void
    {
        $this->listener()->save(serialize(['2']), $this->dc(5));

        self::assertSame([2 => 'B'], $this->listener()->options($this->dc(6)));
        self::assertSame([1 => 'A', 2 => 'B'], $this->listener()->options($this->dc(5)));
    }

    private function listener(): ProductShopsListener
    {
        return new ProductShopsListener($this->connection, new ProductShops($this->connection));
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}

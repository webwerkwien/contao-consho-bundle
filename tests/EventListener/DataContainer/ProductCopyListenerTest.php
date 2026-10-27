<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ProductCopyListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-5: "Save and duplicate" did not take the shop assignment along —
 * the copy was published and reachable nowhere.
 */
final class ProductCopyListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_shop', 'tl_consho_product_property', 'tl_consho_product_option'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product', ['id' => 4, 'pid' => 0, 'title' => 'E-Book']);
        $this->connection->insert('tl_consho_product', ['id' => 20, 'pid' => 4, 'title' => 'E-Book, 250 Seiten']);

        $this->connection->insert('tl_consho_product_shop', ['pid' => 4, 'shop' => 1]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 4, 'shop' => 2]);
        $this->connection->insert('tl_consho_product_property', ['pid' => 4, 'optionId' => 7]);
        $this->connection->insert('tl_consho_product_option', ['pid' => 4, 'optionId' => 9]);
        // Control case: another product.
        $this->connection->insert('tl_consho_product_shop', ['pid' => 5, 'shop' => 3]);
    }

    public function testDieKopieNimmtShopsEigenschaftenUndAchsenMit(): void
    {
        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 4 : null);

        (new ProductCopyListener($this->connection))->onCopy(11, $dc);

        self::assertSame([1, 2], $this->values('tl_consho_product_shop', 'shop', 11));
        self::assertSame([7], $this->values('tl_consho_product_property', 'optionId', 11));
        self::assertSame([9], $this->values('tl_consho_product_option', 'optionId', 11));
        self::assertSame([1, 2], $this->values('tl_consho_product_shop', 'shop', 4), 'das Original bleibt');
    }

    /**
     * Abo-6: a copied variant does not take its axis values along — otherwise
     * there would be two identical variants under one main product.
     */
    public function testEineKopierteVarianteOhneAchsenwerte(): void
    {
        $this->connection->insert('tl_consho_product_option', ['pid' => 20, 'optionId' => 9]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 20, 'shop' => 1]);

        (new ProductCopyListener($this->connection))->copy(20, 21, 100);

        self::assertSame([], $this->values('tl_consho_product_option', 'optionId', 21));
        self::assertSame([1], $this->values('tl_consho_product_shop', 'shop', 21));
    }

    public function testEinZweiterAufrufVerdoppeltNichts(): void
    {
        $listener = new ProductCopyListener($this->connection);
        $listener->copy(4, 11, 100);
        $listener->copy(4, 11, 200);

        self::assertSame([1, 2], $this->values('tl_consho_product_shop', 'shop', 11));
    }

    /**
     * @return list<int>
     */
    private function values(string $table, string $column, int $pid): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(\sprintf('SELECT %s FROM %s WHERE pid = ? ORDER BY %1$s', $column, $table), [$pid]));
    }
}

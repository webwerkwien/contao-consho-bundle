<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductProperties;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductPropertiesRefused;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Connect Stufe 2, CW-4: Eigenschaften und Variantenachsen ohne Maske.
 * set…() prüft, was dort die Optionsliste sicherstellt; die Dublettenregel der
 * Varianten gilt auf beiden Wegen (ProductPropertiesListenerTest).
 */
class ProductPropertiesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_property', 'tl_consho_product_option', 'tl_consho_property_option'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product', ['id' => 5, 'pid' => 0]);
        $this->connection->insert('tl_consho_product', ['id' => 6, 'pid' => 5]);
        $this->connection->insert('tl_consho_product', ['id' => 7, 'pid' => 5]);

        foreach ([21, 22, 31] as $id) {
            $this->connection->insert('tl_consho_property_option', ['id' => $id, 'pid' => 1, 'title' => 'Wert '.$id]);
        }
    }

    public function testSetztUndNimmtWeg(): void
    {
        $this->properties()->setProperties(5, [22, 21]);
        self::assertSame([21, 22], $this->properties()->propertiesOf(5));

        $this->properties()->setProperties(5, [31]);
        self::assertSame([31], $this->properties()->propertiesOf(5));
        self::assertSame([], $this->properties()->variantOptionsOf(5), 'zwei Tabellen, zwei Aussagen');
    }

    public function testEinUnbekannterWertWirdAbgelehnt(): void
    {
        $this->expectExceptionObject(new ProductPropertiesRefused('There is no property value 99.'));

        $this->properties()->setProperties(5, [21, 99]);
    }

    public function testEinUnbekanntesProduktWirdAbgelehnt(): void
    {
        $this->expectExceptionObject(new ProductPropertiesRefused('There is no product 42.'));

        $this->properties()->setVariantOptions(42, [21]);
    }

    public function testZweiSchwesternMitDenselbenWertenWerdenAbgelehnt(): void
    {
        $this->properties()->setVariantOptions(6, [21, 31]);

        $this->expectException(ProductPropertiesRefused::class);
        $this->expectExceptionMessage('6');

        $this->properties()->setVariantOptions(7, [31, 21]);
    }

    public function testLeerRaeumtAb(): void
    {
        $this->properties()->setVariantOptions(6, [21]);
        $this->properties()->setVariantOptions(6, []);

        self::assertSame([], $this->properties()->variantOptionsOf(6));
    }

    private function properties(): ProductProperties
    {
        return new ProductProperties($this->connection);
    }
}

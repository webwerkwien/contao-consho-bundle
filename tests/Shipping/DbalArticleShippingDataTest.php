<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Shipping\DbalArticleShippingData;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * E-4, V1: Shipping weight before product weight, variant before base
 * product — within each level first, then across the levels. Dimensions
 * field by field.
 */
class DbalArticleShippingDataTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_type');

        // Base product with shipping and product weight and dimensions.
        $this->connection->insert('tl_consho_product', ['id' => 1, 'weight' => '0.8', 'shippingWeight' => '1.0', 'length' => '30', 'width' => '20', 'height' => '5']);
        // Variant without values of its own.
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);
        // Variant with its own PRODUCT weight and its own length.
        $this->connection->insert('tl_consho_product', ['id' => 3, 'pid' => 1, 'weight' => '1.2', 'length' => '40']);
        // Single product with product weight only.
        $this->connection->insert('tl_consho_product', ['id' => 4, 'weight' => '2.5']);
        // Single product without any data.
        $this->connection->insert('tl_consho_product', ['id' => 5]);
    }

    public function testDieRegeln(): void
    {
        $data = (new DbalArticleShippingData($this->connection))->of([1, 2, 3, 4, 5]);

        self::assertSame(['weight' => '1.0000', 'length' => '30.0000', 'width' => '20.0000', 'height' => '5.0000', 'kind' => 'physical', 'singlePurpose' => false], $data[1], 'Versandgewicht vor Produktgewicht');
        self::assertSame($data[1], $data[2], 'eine Variante ohne Werte erbt alles');

        // The variant is packed: its own product weight beats the base product's
        // shipping weight.
        self::assertSame(['weight' => '1.2000', 'length' => '40.0000', 'width' => '20.0000', 'height' => '5.0000', 'kind' => 'physical', 'singlePurpose' => false], $data[3]);

        self::assertSame('2.5000', $data[4]['weight'], 'ersatzweise das Produktgewicht');
        self::assertSame(['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000', 'kind' => 'physical', 'singlePurpose' => false], $data[5], 'ohne Produkttyp körperlich');
    }

    /**
     * F-1, F13: physical or digital by product type — for a variant that of
     * the base product, its own only where the base product has none.
     * Without a type or with a deleted type: physical.
     */
    public function testKoerperlichOderDigitalNachDemProdukttyp(): void
    {
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'physical']);

        // E-book with a digital type, one variant without its own type, one with
        // a physical one — the base product decides.
        $this->connection->insert('tl_consho_product', ['id' => 10, 'productType' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 11, 'pid' => 10]);
        $this->connection->insert('tl_consho_product', ['id' => 12, 'pid' => 10, 'productType' => 2]);
        // Base product without a type, variant digital: then its own.
        $this->connection->insert('tl_consho_product', ['id' => 20]);
        $this->connection->insert('tl_consho_product', ['id' => 21, 'pid' => 20, 'productType' => 1]);
        // Type deleted.
        $this->connection->insert('tl_consho_product', ['id' => 30, 'productType' => 99]);
        // DL1: service; an unknown value counts as physical.
        $this->connection->insert('tl_consho_product_type', ['id' => 3, 'kind' => 'service']);
        $this->connection->insert('tl_consho_product_type', ['id' => 4, 'kind' => 'unbekannt']);
        $this->connection->insert('tl_consho_product', ['id' => 40, 'productType' => 3]);
        $this->connection->insert('tl_consho_product', ['id' => 50, 'productType' => 4]);

        $data = (new DbalArticleShippingData($this->connection))->of([10, 11, 12, 20, 21, 30, 40, 50]);

        self::assertSame('digital', $data[10]['kind']);
        self::assertSame('digital', $data[11]['kind'], 'die Variante erbt den Typ');
        self::assertSame('digital', $data[12]['kind'], 'der Typ des Grundprodukts gilt, wie auf der Produktseite');
        self::assertSame('physical', $data[20]['kind']);
        self::assertSame('digital', $data[21]['kind'], 'ihr eigener, wo das Grundprodukt keinen hat');
        self::assertSame('physical', $data[30]['kind'], 'gelöschter Typ: körperlich');
        self::assertSame('service', $data[40]['kind']);
        self::assertSame('physical', $data[50]['kind'], 'unbekannte Art: körperlich');
    }

    /**
     * EZ-1: the main product's switch, and only for a voucher — a variant
     * cannot switch it, a stray tick on another kind means nothing.
     */
    public function testEinzweckNurAmGutscheinUndVomGrundprodukt(): void
    {
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'voucher']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'service']);

        $this->connection->insert('tl_consho_product', ['id' => 10, 'productType' => 1, 'voucherSinglePurpose' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 11, 'pid' => 10]);
        $this->connection->insert('tl_consho_product', ['id' => 20, 'productType' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 21, 'pid' => 20, 'voucherSinglePurpose' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 30, 'productType' => 2, 'voucherSinglePurpose' => 1]);

        $data = (new DbalArticleShippingData($this->connection))->of([10, 11, 20, 21, 30]);

        self::assertTrue($data[10]['singlePurpose'] ?? null);
        self::assertTrue($data[11]['singlePurpose'] ?? null, 'die Variante erbt den Schalter');
        self::assertFalse($data[20]['singlePurpose'] ?? null);
        self::assertFalse($data[21]['singlePurpose'] ?? null, 'die Variante schaltet nicht selbst');
        self::assertFalse($data[30]['singlePurpose'] ?? null, 'nur ein Gutschein');
    }

    public function testOhneIdsKeineAbfrage(): void
    {
        self::assertSame([], (new DbalArticleShippingData($this->createMock(Connection::class)))->of([]));
    }
}

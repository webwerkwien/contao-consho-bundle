<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Shipping\DbalArticleShippingData;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * E-4, V1: Versandgewicht vor Produktgewicht, Variante vor Grundprodukt —
 * je Ebene zuerst, dann die Ebenen. Maße Feld für Feld.
 */
class DbalArticleShippingDataTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_type');

        // Grundprodukt mit Versand- und Produktgewicht und Maßen.
        $this->connection->insert('tl_consho_product', ['id' => 1, 'weight' => '0.8', 'shippingWeight' => '1.0', 'length' => '30', 'width' => '20', 'height' => '5']);
        // Variante ohne eigene Werte.
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);
        // Variante mit eigenem PRODUKTgewicht und eigener Länge.
        $this->connection->insert('tl_consho_product', ['id' => 3, 'pid' => 1, 'weight' => '1.2', 'length' => '40']);
        // Einzelprodukt nur mit Produktgewicht.
        $this->connection->insert('tl_consho_product', ['id' => 4, 'weight' => '2.5']);
        // Einzelprodukt ganz ohne Angaben.
        $this->connection->insert('tl_consho_product', ['id' => 5]);
    }

    public function testDieRegeln(): void
    {
        $data = (new DbalArticleShippingData($this->connection))->of([1, 2, 3, 4, 5]);

        self::assertSame(['weight' => '1.0000', 'length' => '30.0000', 'width' => '20.0000', 'height' => '5.0000', 'kind' => 'physical'], $data[1], 'Versandgewicht vor Produktgewicht');
        self::assertSame($data[1], $data[2], 'eine Variante ohne Werte erbt alles');

        // Die Variante wird verpackt: ihr eigenes Produktgewicht schlägt das
        // Versandgewicht des Grundprodukts.
        self::assertSame(['weight' => '1.2000', 'length' => '40.0000', 'width' => '20.0000', 'height' => '5.0000', 'kind' => 'physical'], $data[3]);

        self::assertSame('2.5000', $data[4]['weight'], 'ersatzweise das Produktgewicht');
        self::assertSame(['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000', 'kind' => 'physical'], $data[5], 'ohne Produkttyp körperlich');
    }

    /**
     * F-1, F13: körperlich oder digital nach dem Produkttyp — bei einer
     * Variante der des Grundprodukts, ihr eigener nur, wo das Grundprodukt
     * keinen hat. Ohne Typ oder mit gelöschtem Typ: körperlich.
     */
    public function testKoerperlichOderDigitalNachDemProdukttyp(): void
    {
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'physical']);

        // E-Book mit digitalem Typ, eine Variante ohne eigenen Typ, eine mit
        // körperlichem — das Grundprodukt entscheidet.
        $this->connection->insert('tl_consho_product', ['id' => 10, 'productType' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 11, 'pid' => 10]);
        $this->connection->insert('tl_consho_product', ['id' => 12, 'pid' => 10, 'productType' => 2]);
        // Grundprodukt ohne Typ, Variante digital: dann ihr eigener.
        $this->connection->insert('tl_consho_product', ['id' => 20]);
        $this->connection->insert('tl_consho_product', ['id' => 21, 'pid' => 20, 'productType' => 1]);
        // Typ gelöscht.
        $this->connection->insert('tl_consho_product', ['id' => 30, 'productType' => 99]);
        // DL1: Dienstleistung; ein unbekannter Wert zählt als körperlich.
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

    public function testOhneIdsKeineAbfrage(): void
    {
        self::assertSame([], (new DbalArticleShippingData($this->createMock(Connection::class)))->of([]));
    }
}

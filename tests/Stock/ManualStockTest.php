<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Stock;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Stock\ManualStock;
use Webwerkwien\ContaoConshoBundle\Stock\StockBooking;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Connect Stufe 2, CW-2: eine Buchung von Hand mit ausdrücklicher Menge und
 * Benutzer — ohne Maske, ohne Anfrage.
 */
class ManualStockTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        DcaSchema::create($this->connection, 'tl_consho_stock');
        DcaSchema::create($this->connection, 'tl_consho_stock_movement');
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'physical']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'pid' => 0, 'productType' => 1, 'stockTracked' => 1]);
    }

    public function testBuchtDieMengeMitBenutzerUndGrund(): void
    {
        $after = $this->stock()->adjust(1, BigDecimal::of('5'), 7, 200);

        self::assertSame('5.0000', $after);
        $movement = $this->connection->fetchAssociative('SELECT delta, reason, user, createdAt FROM tl_consho_stock_movement');
        self::assertIsArray($movement);
        self::assertSame('5.0000', StockBooking::scaled($movement['delta']));
        self::assertSame('manual', $movement['reason']);
        self::assertSame(7, (int) $movement['user']);
        self::assertSame(200, (int) $movement['createdAt']);
    }

    /**
     * Ohne vorheriges Speichern in der Maske gibt es noch keine Zeile — ein
     * Befehl kommt genau so an.
     */
    public function testLegtDieZeileBeiBedarfAn(): void
    {
        $this->stock()->adjust(1, BigDecimal::of('3'), 0, 200);
        $this->stock()->adjust(1, BigDecimal::of('-1'), 0, 201);

        self::assertSame('2.0000', StockBooking::scaled($this->connection->fetchOne('SELECT quantity FROM tl_consho_stock WHERE article = 1 AND active = 1')));
    }

    public function testNullBuchtNichtsUndNenntDenStand(): void
    {
        $this->stock()->adjust(1, BigDecimal::of('4'), 0, 200);

        self::assertSame('4.0000', $this->stock()->adjust(1, BigDecimal::zero(), 0, 201));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_movement'));
    }

    public function testOhneBestandKeineBuchung(): void
    {
        $this->connection->update('tl_consho_product', ['stockTracked' => 0], ['id' => 1]);

        self::assertNull($this->stock()->adjust(1, BigDecimal::of('5'), 0, 200));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_movement'));
    }

    /**
     * Ein Hauptprodukt mit Varianten ist kein Artikel: gebucht wird an der
     * Variante.
     */
    public function testHauptproduktMitVariantenKeineBuchung(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);

        self::assertNull($this->stock()->adjust(1, BigDecimal::of('5'), 0, 200));
        self::assertSame('5.0000', $this->stock()->adjust(2, BigDecimal::of('5'), 0, 200));
    }

    public function testUnbekannterArtikelKeineBuchung(): void
    {
        self::assertNull($this->stock()->adjust(99, BigDecimal::of('5'), 0, 200));
    }

    private function stock(): ManualStock
    {
        return new ManualStock(new StockRows($this->connection), new StockBooking($this->connection));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Stock;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\SoldOutMarks;
use Webwerkwien\ContaoConshoBundle\Stock\StockBooking;
use Webwerkwien\ContaoConshoBundle\Stock\StockReason;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block G, G2: Jede Änderung ist eine atomare Buchung mit einer Bewegung.
 */
class StockBookingTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_stock');
        DcaSchema::create($this->connection, 'tl_consho_stock_movement');
        $this->connection->insert('tl_consho_stock', ['id' => 5, 'article' => 1, 'main' => 1, 'quantity' => '10.0000', 'active' => 1]);
    }

    public function testBuchtUndProtokolliert(): void
    {
        $after = (new StockBooking($this->connection))->book(5, BigDecimal::of('-2.5'), StockReason::Order, 1000, 0, 42);

        self::assertSame('7.5000', $after);
        self::assertSame('7.5000', StockBooking::scaled($this->connection->fetchOne('SELECT quantity FROM tl_consho_stock WHERE id = 5')));

        $movement = $this->connection->fetchAssociative('SELECT * FROM tl_consho_stock_movement');
        self::assertIsArray($movement);
        self::assertSame(5, (int) $movement['pid']);
        self::assertSame('-2.5000', StockBooking::scaled($movement['delta']));
        self::assertSame('7.5000', StockBooking::scaled($movement['quantityAfter']));
        self::assertSame('order', $movement['reason']);
        self::assertSame(42, (int) $movement['orderId']);
        self::assertSame(1000, (int) $movement['createdAt']);
    }

    /**
     * Der Kern von G3: gebucht wird auf den Stand in der Datenbank, nicht auf
     * eine Zahl, die vorher gelesen wurde.
     */
    public function testRechnetAufDenAktuellenStand(): void
    {
        $booking = new StockBooking($this->connection);
        $this->connection->executeStatement('UPDATE tl_consho_stock SET quantity = quantity - 2 WHERE id = 5');

        self::assertSame('13.0000', $booking->book(5, BigDecimal::of(5), StockReason::Manual, 1000, 7));
        self::assertSame(7, (int) $this->connection->fetchOne('SELECT user FROM tl_consho_stock_movement'));
    }

    /**
     * Produktseite und Listen liegen im Seiten-Cache, ein Kauf ist kein
     * Speichern im Backend. Entwertet wird, wenn sich der sichtbare Zustand
     * ändert — nicht bei jedem Verkauf.
     */
    public function testDerCacheWirdBeimWechselDesZustandsEntwertet(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_product');
        $this->connection->insert('tl_consho_product', ['id' => 1, 'stockTracked' => 1, 'stockWarning' => '3.0000']);

        $log = new class {
            /** @var list<list<string>> */
            public array $tags = [];
        };
        $tags = $this->createMock(CatalogCacheTags::class);
        $tags->method('forProduct')->willReturnCallback(static fn (int $id): array => ['product.'.$id]);
        $tags->method('invalidate')->willReturnCallback(static function (array $list) use ($log): void {
            $log->tags[] = array_values(array_map(strval(...), $list));
        });

        $booking = new StockBooking($this->connection, $tags);

        $booking->book(5, BigDecimal::of(-2), StockReason::Manual, 1000);
        self::assertSame([], $log->tags, '10 → 8: weiter lieferbar');

        $booking->take(5, BigDecimal::of(5), false, StockReason::Order, 1001);
        self::assertSame([['product.1']], $log->tags, '8 → 3: nur noch wenige');

        $booking->take(5, BigDecimal::of(3), false, StockReason::Order, 1002);
        self::assertSame([['product.1'], ['product.1']], $log->tags, '3 → 0: nicht verfügbar');

        $booking->book(5, BigDecimal::of(10), StockReason::Manual, 1003);
        self::assertSame([['product.1'], ['product.1'], ['product.1']], $log->tags, '0 → 10: wieder lieferbar');
    }

    /**
     * Block Verfügbarkeit, V1: Beim Wechsel des Zustands wird die Marke
     * nachgeführt — auch der Wareneingang einer Einkaufsliste geht hier durch.
     */
    public function testDieMarkeFolgtDemZustand(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        DcaSchema::create($this->connection, 'tl_consho_sold_out');
        $this->connection->insert('tl_consho_product', ['id' => 1, 'stockTracked' => 1]);

        $booking = new StockBooking($this->connection, null, new SoldOutMarks($this->connection, new Availability($this->connection)));

        $booking->take(5, BigDecimal::of(10), false, StockReason::Order, 1000);
        self::assertSame([1], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out')));

        $booking->book(5, BigDecimal::of(6), StockReason::Receipt, 1001);
        self::assertSame([], $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out'));
    }

    public function testRolltMitDerAeusserenTransaktionZurueck(): void
    {
        $this->connection->beginTransaction();
        (new StockBooking($this->connection))->book(5, BigDecimal::of(-1), StockReason::Order, 1000);
        $this->connection->rollBack();

        self::assertSame('10.0000', StockBooking::scaled($this->connection->fetchOne('SELECT quantity FROM tl_consho_stock WHERE id = 5')));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_movement'));
    }
}

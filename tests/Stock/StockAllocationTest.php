<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Stock;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\StockShortAtPurchase;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockAllocation;
use Webwerkwien\ContaoConshoBundle\Stock\StockBooking;
use Webwerkwien\ContaoConshoBundle\Stock\SoldOutMarks;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Stock\StockState;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block G, G4–G7: Availability and the deduction at the purchase click.
 *
 * Product 1 (physical, stock managed, threshold 2) without variants;
 * product 2 physical without stock; product 3 digital.
 */
class StockAllocationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_stock', 'tl_consho_stock_movement', 'tl_consho_order_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'physical']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'productType' => 1, 'stockTracked' => 1, 'stockWarning' => '2.0000']);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'productType' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'productType' => 2, 'stockTracked' => 1]);
        (new StockRows($this->connection))->sync(1, 100);
        $this->setStock(1, '5');
    }

    public function testZustaende(): void
    {
        $availability = new Availability($this->connection);

        self::assertSame(StockState::Available, $availability->for(1)->state);
        self::assertSame('5.0000', $availability->for(1)->available);
        self::assertNull($availability->for(2)->available, 'ohne Bestand: unbegrenzt');
        self::assertNull($availability->for(3)->available, 'digital: unbegrenzt, auch mit Häkchen');

        $this->setStock(1, '2');
        self::assertSame(StockState::Few, $availability->for(1)->state);

        $this->setStock(1, '0');
        self::assertSame(StockState::Unavailable, $availability->for(1)->state);
        self::assertFalse($availability->for(1)->state->purchasable());

        $this->connection->update('tl_consho_product', ['stockBackorder' => 1], ['id' => 1]);
        self::assertSame(StockState::Backorder, $availability->for(1)->state);
        self::assertTrue($availability->for(1)->covers('3'));
    }

    public function testFehltDieZeileZaehltSieAlsNull(): void
    {
        $this->connection->delete('tl_consho_stock', ['article' => 1]);

        self::assertSame(StockState::Unavailable, (new Availability($this->connection))->for(1)->state);
    }

    public function testKnappWirdJeArtikelSummiert(): void
    {
        $availability = new Availability($this->connection);

        self::assertSame([], $availability->short([[1, '3'], [2, '99']]));
        self::assertSame([1], $availability->short([[1, '3'], [1, '3']]), 'Einmalkauf und Abo ziehen aus einem Bestand');
    }

    public function testDerKaufklickBuchtAbUndMerktEsSichAnDerZeile(): void
    {
        $this->line(10, 7, 1, '2');
        $this->line(11, 7, 2, '4');

        $this->allocation()->bookOrder(7, 1000);

        self::assertSame('3.0000', $this->stock(1));
        self::assertSame('2.0000', StockBooking::scaled($this->connection->fetchOne('SELECT stockBooked FROM tl_consho_order_item WHERE id = 10')));
        self::assertSame('0.0000', StockBooking::scaled($this->connection->fetchOne('SELECT stockBooked FROM tl_consho_order_item WHERE id = 11')), 'ohne Bestand nichts gebucht');

        $movement = $this->connection->fetchAssociative('SELECT delta, reason, orderId FROM tl_consho_stock_movement');
        self::assertIsArray($movement);
        self::assertSame(['-2.0000', 'order', 7], [StockBooking::scaled($movement['delta']), $movement['reason'], (int) $movement['orderId']]);
    }

    /**
     * The last item: the second purchase fails on the condition in the UPDATE —
     * not on a lock, which SQLite does not even know.
     */
    public function testZweiKaeufeUmDasLetzteStueck(): void
    {
        $this->setStock(1, '1');
        $this->line(10, 7, 1, '1');
        $this->line(20, 8, 1, '1');

        $this->allocation()->bookOrder(7, 1000);

        try {
            $this->allocation()->bookOrder(8, 1001);
            self::fail('Das letzte Stück wurde zweimal verkauft.');
        } catch (StockShortAtPurchase) {
        }

        self::assertSame('0.0000', $this->stock(1));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_movement'));
    }

    public function testZweiZeilenDesselbenArtikelsZaehlenZusammen(): void
    {
        $this->line(10, 7, 1, '3');
        $this->line(11, 7, 1, '3');

        $this->expectException(StockShortAtPurchase::class);

        $this->allocation()->bookOrder(7, 1000);
    }

    public function testAuchOhneBestandVerkaufenGehtUnterNull(): void
    {
        $this->connection->update('tl_consho_product', ['stockBackorder' => 1], ['id' => 1]);
        $this->line(10, 7, 1, '8');

        $this->allocation()->bookOrder(7, 1000);

        self::assertSame('-3.0000', $this->stock(1));
    }

    public function testOhneZeileWirdAbgelehnt(): void
    {
        $this->connection->delete('tl_consho_stock', ['article' => 1]);
        $this->line(10, 7, 1, '1');

        $this->expectException(StockShortAtPurchase::class);

        $this->allocation()->bookOrder(7, 1000);
    }

    public function testOhneZeileAberAuchOhneBestandVerkaufenGehtEsWeiter(): void
    {
        $this->connection->delete('tl_consho_stock', ['article' => 1]);
        $this->connection->update('tl_consho_product', ['stockBackorder' => 1], ['id' => 1]);
        $this->line(10, 7, 1, '1');

        $this->allocation()->bookOrder(7, 1000);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_movement'));
    }

    public function testVersandzeilenZaehlenNicht(): void
    {
        $this->connection->insert('tl_consho_order_item', ['id' => 10, 'pid' => 7, 'lineType' => 'shipping', 'product' => 1, 'quantity' => '9.0000']);

        $this->allocation()->bookOrder(7, 1000);

        self::assertSame('5.0000', $this->stock(1));
    }

    /**
     * Block availability: Two articles that sell out with this purchase are
     * not marked row by row in the order of the cart, but at the end in ONE
     * call — refresh() sorts in ascending order, like the hourly run.
     */
    public function testDieMarkenAmEndeInEinemAufruf(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_sold_out');
        $this->connection->insert('tl_consho_product', ['id' => 4, 'productType' => 1, 'stockTracked' => 1]);
        (new StockRows($this->connection))->sync(4, 100);
        $this->setStock(4, '1');
        $this->line(10, 7, 4, '1');
        $this->line(11, 7, 1, '5');

        $availability = new Availability($this->connection);
        $marks = new class($this->connection, $availability) extends SoldOutMarks {
            /** @var list<list<int>> */
            public array $calls = [];

            public function refresh(array $articleIds, int|null $now = null): bool
            {
                $this->calls[] = $articleIds;

                return parent::refresh($articleIds, $now);
            }
        };

        (new StockAllocation($this->connection, $availability, new StockBooking($this->connection, soldOut: $marks), soldOut: $marks))->bookOrder(7, 1000);

        self::assertSame([4, 1], end($marks->calls), 'der letzte Aufruf trägt beide — gesammelt, nicht je Zeile');
        self::assertSame([1, 4], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out ORDER BY article')));
    }

    private function allocation(): StockAllocation
    {
        return new StockAllocation($this->connection, new Availability($this->connection), new StockBooking($this->connection));
    }

    private function line(int $id, int $order, int $article, string $quantity): void
    {
        $this->connection->insert('tl_consho_order_item', ['id' => $id, 'pid' => $order, 'lineType' => 'product', 'product' => $article, 'quantity' => $quantity]);
    }

    private function setStock(int $article, string $quantity): void
    {
        $this->connection->update('tl_consho_stock', ['quantity' => $quantity], ['article' => $article]);
    }

    private function stock(int $article): string
    {
        return StockBooking::scaled($this->connection->fetchOne('SELECT quantity FROM tl_consho_stock WHERE article = ?', [$article]));
    }
}

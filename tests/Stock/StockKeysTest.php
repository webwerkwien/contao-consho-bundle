<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Stock;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Order\StockShortAtPurchase;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\SoldOutMarks;
use Webwerkwien\ContaoConshoBundle\Stock\StockAllocation;
use Webwerkwien\ContaoConshoBundle\Stock\StockBooking;
use Webwerkwien\ContaoConshoBundle\Stock\StockIssueSource;
use Webwerkwien\ContaoConshoBundle\Stock\StockReturn;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Stock\StockState;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block G, G-5 (G10, G11): Der Schlüsselvorrat ist der Bestand eines
 * digitalen Artikels — freie Codes abzüglich dessen, was bestellt und noch
 * nicht ausgeliefert ist.
 *
 * Produkt 1: digital, Auslieferung „Schlüssel", Schwelle 1. Produkt 2: digital,
 * Datei — unbegrenzt.
 */
class StockKeysTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach ([
            'tl_consho_product', 'tl_consho_product_type', 'tl_consho_stock', 'tl_consho_stock_movement', 'tl_consho_key',
            'tl_consho_order', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item',
        ] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'title' => 'Spiel', 'productType' => 1, 'digitalDelivery' => 'key', 'stockWarning' => '1.0000', 'published' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'title' => 'E-Book', 'productType' => 1, 'digitalDelivery' => 'file', 'published' => 1]);
        (new StockRows($this->connection))->sync(1, 100);
        (new StockRows($this->connection))->sync(2, 100);
        $this->codes(3);
    }

    public function testDerVorratIstDerBestand(): void
    {
        self::assertSame([['keys', 1]], array_map(
            static fn (array $row): array => [(string) $row['source'], (int) $row['article']],
            $this->connection->fetchAllAssociative('SELECT source, article FROM tl_consho_stock WHERE active = 1'),
        ));

        self::assertSame('3.0000', $this->availability()->for(1)->available);
        self::assertSame(StockState::Available, $this->availability()->for(1)->state);
        self::assertNull($this->availability()->for(2)->available, 'Datei: unbegrenzt');
    }

    public function testOffenZaehltAbAusgeliefertUndStorniertNicht(): void
    {
        $this->order(7, '2');
        self::assertSame('1.0000', $this->availability()->for(1)->available);
        self::assertSame(StockState::Few, $this->availability()->for(1)->state);
        self::assertSame('3.0000', $this->availability()->of([1], 7)[1]->available, 'die eigene Bestellung zählt nicht');

        // Ausgeliefert: Code vergeben, nicht mehr offen — verfügbar bleibt gleich.
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => 7, 'reportedAt' => 500]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 70, 'quantity' => '2.0000']);
        $this->connection->executeStatement('UPDATE tl_consho_key SET entitlement = 9 WHERE id IN (1, 2)');
        self::assertSame('1.0000', $this->availability()->for(1)->available);

        // Storniert vor der Auslieferung: nicht mehr offen.
        $this->order(8, '1');
        self::assertSame(StockState::Unavailable, $this->availability()->for(1)->state);
        $this->connection->update('tl_consho_order', ['cancelledAt' => 600], ['id' => 8]);
        self::assertSame('1.0000', $this->availability()->for(1)->available);
    }

    public function testEinAngenommenerWiderrufVorDerAuslieferungGibtFrei(): void
    {
        $this->order(7, '2');
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => 7, 'state' => 'accepted']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 70, 'acceptedQuantity' => '2.0000']);

        self::assertSame('3.0000', $this->availability()->for(1)->available);
    }

    /**
     * Review G, S2: ausgeliefert UND widerrufen zählt nicht minus eins und
     * verdeckt keine offene Zeile.
     */
    public function testJedeZeileZaehltMindestensNull(): void
    {
        $this->connection->executeStatement('DELETE FROM tl_consho_key WHERE id > 1');
        $this->order(7, '1');
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => 7, 'reportedAt' => 500]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 70, 'quantity' => '1.0000']);
        $this->connection->executeStatement('UPDATE tl_consho_key SET entitlement = 9');
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => 7, 'state' => 'accepted']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 70, 'acceptedQuantity' => '1.0000']);
        $this->connection->insert('tl_consho_key', ['pid' => 1, 'code' => 'NEU']);

        $this->order(8, '1');

        self::assertSame('0.0000', $this->availability()->for(1)->available, 'der eine freie Code gehört Bestellung 8');
    }

    /**
     * Review G, B2 und zweites Review: gesperrt wird nur die Bestandszeile.
     * Sperrende Lesevorgänge auf Bestellzeilen verklemmen sich mit jedem
     * gleichzeitigen Kauf (sie sperren die noch nicht festgeschriebenen
     * Zeilen des anderen). Gesehen wird der Vorgänger über READ COMMITTED
     * (OrderPlacerTest::testDerKaufklickLaeuftInReadCommitted).
     */
    public function testNurDieBestandszeileWirdGesperrt(): void
    {
        SqliteWithoutRowLocks::takeLocked();
        $this->order(7, '1');

        $this->allocation()->bookOrder(7, 1000);

        $locked = SqliteWithoutRowLocks::takeLocked();
        self::assertCount(1, $locked);
        self::assertStringContainsString('FROM tl_consho_stock', $locked[0]);
    }

    public function testLizenzVerlaengernBrauchtKeinenCode(): void
    {
        $this->connection->executeStatement('DELETE FROM tl_consho_key');
        $this->order(9, '1', renews: 5);

        self::assertSame('0.0000', $this->availability()->for(1)->available, 'die Verlängerung ist nicht offen');

        $this->allocation()->bookOrder(9, 1000);
        $this->addToAssertionCount(1);
    }

    public function testDerLetzteCodeGehtNurEinmal(): void
    {
        $this->codes(0);
        $this->connection->executeStatement('DELETE FROM tl_consho_key WHERE id > 1');

        $this->order(7, '1');
        $this->allocation()->bookOrder(7, 1000);

        $this->order(8, '1');
        $this->expectException(StockShortAtPurchase::class);
        $this->allocation()->bookOrder(8, 1001);
    }

    public function testDerKaufEntwertetDenCacheWennDerZustandWechselt(): void
    {
        $tags = $this->createMock(CatalogCacheTags::class);
        $tags->method('forProduct')->willReturn(['product.1']);
        $tags->expects(self::once())->method('invalidate')->with(['product.1']);

        // 3 → 2: lieferbar bleibt lieferbar, kein Entwerten.
        $this->order(7, '1');
        $this->allocation($tags)->bookOrder(7, 1000);

        // 2 → 0: nicht verfügbar.
        $this->order(8, '2');
        $this->allocation($tags)->bookOrder(8, 1001);
    }

    /**
     * Block Verfügbarkeit, V1: Der letzte Code verkauft — der Artikel fällt
     * aus „Nur verfügbare"; ein Storno holt ihn zurück.
     */
    public function testKaufUndStornoFuehrenDieMarkeNach(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_sold_out');
        $marks = new SoldOutMarks($this->connection, $this->availability());

        $this->order(7, '3');
        (new StockAllocation($this->connection, $this->availability(), new StockBooking($this->connection), null, $marks))->bookOrder(7, 1000);
        self::assertSame([1], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out')));

        $this->connection->update('tl_consho_order', ['cancelledAt' => 600], ['id' => 7]);
        (new StockReturn($this->connection, new StockBooking($this->connection), null, $marks))->afterCancellation(7, 600);
        self::assertSame([], $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out'));
    }

    public function testEinStornoGibtCodesFreiUndEntwertetDenCache(): void
    {
        $this->order(7, '3');
        self::assertSame(StockState::Unavailable, $this->availability()->for(1)->state);

        $tags = $this->createMock(CatalogCacheTags::class);
        $tags->method('forProduct')->willReturn(['product.1']);
        $tags->expects(self::once())->method('invalidate')->with(['product.1']);

        $this->connection->update('tl_consho_order', ['cancelledAt' => 600], ['id' => 7]);
        (new StockReturn($this->connection, new StockBooking($this->connection), $tags))->afterCancellation(7, 600);

        self::assertSame(StockState::Available, $this->availability()->for(1)->state);
    }

    public function testDerBefundNenntKnappeUndLeere(): void
    {
        self::assertSame([], (new StockIssueSource($this->connection, $this->availability()))->issues());

        $this->order(7, '2');
        $issues = (new StockIssueSource($this->connection, $this->availability()))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::StockLow, $issues[0]->finding);
        self::assertSame(['count' => '1', 'products' => 'Spiel (1)'], $issues[0]->context);
        // Dieselbe Liste zählt das Dashboard (DB3), nach Artikel.
        self::assertSame([1 => 'Spiel (1)'], (new StockIssueSource($this->connection, $this->availability()))->low());

        $this->connection->update('tl_consho_product', ['published' => 0], ['id' => 1]);
        self::assertSame([], (new StockIssueSource($this->connection, $this->availability()))->issues(), 'nur veröffentlichte');
    }

    public function testWechseltDieAuslieferungWirdDieZeileStillgelegt(): void
    {
        $this->connection->update('tl_consho_product', ['digitalDelivery' => 'file'], ['id' => 1]);
        (new StockRows($this->connection))->sync(1, 200);

        self::assertNull($this->availability()->for(1)->available);
    }

    public function testDieVarianteMitEigenerAuslieferung(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 3, 'pid' => 2, 'title' => 'Lizenz', 'digitalDelivery' => 'key', 'published' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 4, 'pid' => 2, 'title' => 'PDF', 'published' => 1]);
        (new StockRows($this->connection))->sync(3, 200);

        self::assertSame('0.0000', $this->availability()->for(3)->available);
        self::assertNull($this->availability()->for(4)->available, 'erbt „Datei" vom Hauptprodukt');
    }

    private function codes(int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->connection->insert('tl_consho_key', ['pid' => 1, 'code' => 'CODE-'.$i]);
        }
    }

    private function order(int $id, string $quantity, int $renews = 0): void
    {
        $this->connection->insert('tl_consho_order', ['id' => $id, 'orderNumber' => 'WEB-'.$id]);
        $this->connection->insert('tl_consho_order_item', ['id' => $id * 10, 'pid' => $id, 'lineType' => 'product', 'kind' => 'digital', 'product' => 1, 'quantity' => $quantity, 'renews' => $renews]);
    }

    private function availability(): Availability
    {
        return new Availability($this->connection);
    }

    private function allocation(CatalogCacheTags|null $tags = null): StockAllocation
    {
        return new StockAllocation($this->connection, $this->availability(), new StockBooking($this->connection), $tags);
    }
}

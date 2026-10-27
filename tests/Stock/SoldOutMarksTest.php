<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Stock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Stock\ArticleAvailability;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockState;
use Webwerkwien\ContaoConshoBundle\Stock\SoldOutMarks;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block availability, V1: tl_consho_sold_out holds exactly the articles that
 * Availability calls "currently not available".
 *
 * Product 1: physical, stock managed, without variants.
 * Product 2: physical, stock managed, variants 21 and 22.
 * Product 3: physical, stock not managed.
 */
class SoldOutMarksTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach ([
            'tl_consho_product', 'tl_consho_product_type', 'tl_consho_stock', 'tl_consho_stock_movement', 'tl_consho_key',
            'tl_consho_order', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item',
            'tl_consho_sold_out',
        ] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product', ['id' => 1, 'title' => 'Wanderstock', 'stockTracked' => 1, 'published' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'title' => 'Shirt', 'stockTracked' => 1, 'published' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 21, 'pid' => 2, 'title' => 'S', 'published' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 22, 'pid' => 2, 'title' => 'M', 'published' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'title' => 'Karte', 'published' => 1]);

        foreach ([1, 2, 3] as $main) {
            (new StockRows($this->connection))->sync($main, 100);
        }

        $this->stock(1, '0');
        $this->stock(21, '0');
        $this->stock(22, '4');
    }

    public function testMarkiertGenauDieNichtVerfuegbaren(): void
    {
        self::assertTrue($this->marks()->rebuild(200));
        self::assertSame([1, 21], $this->stored());
    }

    public function testEinHauptproduktMitVariantenTraegtNieEineZeile(): void
    {
        // All variants empty: the state is on the variants (V3), not on the
        // main product — that one has no stock of its own.
        $this->stock(22, '0');
        $this->marks()->rebuild(200);

        self::assertSame([1, 21, 22], $this->stored());
    }

    /**
     * Block availability: A purchase only marks at the end, once and in
     * ascending order — in the same order as the hourly run. Until then
     * refresh() writes nothing; if the purchase fails, not afterwards either.
     */
    public function testImKaufErstAmEndeUndNurWennErGelingt(): void
    {
        $marks = $this->marks();

        $marks->deferred(function () use ($marks): void {
            self::assertFalse($marks->refresh([21]));
            self::assertFalse($marks->refresh([1]));
            self::assertSame([], $this->stored(), 'noch nichts geschrieben');
        });

        self::assertSame([1, 21], $this->stored());

        $this->connection->executeStatement('DELETE FROM tl_consho_sold_out');

        try {
            $marks->deferred(static function () use ($marks): void {
                $marks->refresh([1, 21]);

                throw new \RuntimeException('zu wenig Bestand');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->stored(), 'ein gescheiterter Kauf markiert nichts');
        self::assertTrue($marks->refresh([21, 1]), 'und danach arbeitet refresh() wieder sofort');
    }

    public function testAufBestellungIstNichtAusverkauft(): void
    {
        $this->connection->update('tl_consho_product', ['stockBackorder' => 1], ['id' => 1]);
        $this->marks()->rebuild(200);

        self::assertSame([21], $this->stored());
    }

    public function testRefreshNimmtNurDieGenanntenArtikel(): void
    {
        $this->marks()->rebuild(200);
        $this->stock(1, '5');
        $this->stock(22, '0');

        self::assertTrue($this->marks()->refresh([1], 300));
        self::assertSame([21], $this->stored(), 'Artikel 22 wurde nicht genannt');

        self::assertFalse($this->marks()->refresh([1], 300), 'nichts mehr zu tun');
    }

    public function testRefreshMitDemHauptproduktErreichtDieVariantenNicht(): void
    {
        // Whoever means variants names them — CatalogRefreshListener does that.
        $this->stock(22, '0');

        self::assertFalse($this->marks()->refresh([2], 300));
        self::assertSame([], $this->stored());
    }

    public function testEinGeloeschterArtikelVerliertSeineZeile(): void
    {
        $this->marks()->rebuild(200);
        $this->connection->delete('tl_consho_product', ['id' => 1]);

        self::assertTrue($this->marks()->refresh([1], 300));
        self::assertSame([21], $this->stored());
    }

    public function testRebuildRaeumtZeilenOhneArtikelAuf(): void
    {
        $this->marks()->rebuild(200);
        $this->connection->insert('tl_consho_sold_out', ['article' => 999]);

        self::assertTrue($this->marks()->rebuild(300));
        self::assertSame([1, 21], $this->stored());
    }

    public function testVerifySchreibtNichtsUndNenntBeideRichtungen(): void
    {
        $this->connection->insert('tl_consho_sold_out', ['article' => 22]);
        $this->connection->insert('tl_consho_sold_out', ['article' => 999]);

        self::assertSame(['missing' => [1, 21], 'surplus' => [22, 999]], $this->marks()->verify());
        self::assertSame([22, 999], $this->stored());
    }

    public function testEineGleichzeitigGesetzteMarkeReisstNichtsMit(): void
    {
        // Between reading and writing another process has marked article 1 —
        // the mark is there, the caller (purchase, cancellation) carries on.
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturnOnConsecutiveCalls([1], []);
        $connection->expects(self::once())->method('insert')->willThrowException($this->createMock(UniqueConstraintViolationException::class));

        $availability = $this->createMock(Availability::class);
        $availability->method('of')->willReturn([1 => new ArticleAvailability(StockState::Unavailable, '0.0000')]);

        // No throw; "changed" stays true — the list has to be invalidated anew
        // either way, whoever set the mark.
        self::assertTrue((new SoldOutMarks($connection, $availability))->refresh([1], 300));
    }

    /**
     * Ascending across block boundaries too. Within a block soldOutAmong()
     * already sorts — the sorting in refresh() only decides which block writes
     * first. What is read is the write order (`id`), not a list sorted by
     * `article`.
     */
    public function testSchreibtUeberBlockgrenzenAufsteigend(): void
    {
        $ids = range(1001, 1600);

        foreach ($ids as $id) {
            $this->connection->insert('tl_consho_product', ['id' => $id, 'title' => 'A'.$id, 'published' => 1]);
        }

        $availability = $this->createStub(Availability::class);
        $availability->method('of')->willReturnCallback(static fn (array $of): array => array_fill_keys($of, new ArticleAvailability(StockState::Unavailable, '0.0000')));

        (new SoldOutMarks($this->connection, $availability))->refresh(array_reverse($ids), 300);

        self::assertSame($ids, array_map(intval(...), $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out ORDER BY id')));
    }

    public function testEinGeleerterSchluesselvorratIstAusverkauft(): void
    {
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product', ['id' => 4, 'title' => 'Spiel', 'productType' => 1, 'digitalDelivery' => 'key', 'published' => 1]);
        (new StockRows($this->connection))->sync(4, 100);

        $this->marks()->rebuild(200);
        self::assertContains(4, $this->stored());

        $this->connection->insert('tl_consho_key', ['pid' => 4, 'code' => 'A']);
        $this->marks()->refresh([4], 300);
        self::assertNotContains(4, $this->stored());
    }

    private function marks(): SoldOutMarks
    {
        return new SoldOutMarks($this->connection, new Availability($this->connection));
    }

    private function stock(int $article, string $quantity): void
    {
        $this->connection->update('tl_consho_stock', ['quantity' => $quantity], ['article' => $article]);
    }

    /**
     * @return list<int>
     */
    private function stored(): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_sold_out ORDER BY article'));
    }
}

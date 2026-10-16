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
 * Block Verfügbarkeit, V1: tl_consho_sold_out hält genau die Artikel, die
 * Availability „derzeit nicht verfügbar" nennt.
 *
 * Produkt 1: physisch, Bestand geführt, ohne Varianten.
 * Produkt 2: physisch, Bestand geführt, Varianten 21 und 22.
 * Produkt 3: physisch, Bestand nicht geführt.
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
        // Alle Varianten leer: der Zustand steht an den Varianten (V3), nicht
        // am Hauptprodukt — das hat selbst keinen Bestand.
        $this->stock(22, '0');
        $this->marks()->rebuild(200);

        self::assertSame([1, 21, 22], $this->stored());
    }

    /**
     * Review Verfügbarkeit, 2: Ein Kauf markiert erst am Ende, einmal und
     * aufsteigend — in derselben Reihenfolge wie der stündliche Lauf. Bis dahin
     * schreibt refresh() nichts; scheitert der Kauf, auch danach nicht.
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
        // Wer Varianten meint, nennt sie — CatalogRefreshListener tut das.
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
        // Zwischen Lesen und Schreiben hat ein anderer Vorgang Artikel 1
        // markiert — die Marke ist da, der Aufrufer (Kauf, Storno) läuft weiter.
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturnOnConsecutiveCalls([1], []);
        $connection->expects(self::once())->method('insert')->willThrowException($this->createMock(UniqueConstraintViolationException::class));

        $availability = $this->createMock(Availability::class);
        $availability->method('of')->willReturn([1 => new ArticleAvailability(StockState::Unavailable, '0.0000')]);

        // Kein Wurf; „geändert" bleibt wahr — die Liste ist so oder so neu zu
        // entwerten, wer immer die Marke gesetzt hat.
        self::assertTrue((new SoldOutMarks($connection, $availability))->refresh([1], 300));
    }

    /**
     * Review 2026-10-10, W3: Aufsteigend auch über Blockgrenzen hinweg. Innerhalb
     * eines Blocks sortiert schon soldOutAmong() — die Sortierung in refresh()
     * entscheidet nur, welcher Block zuerst schreibt. Gelesen wird die
     * Schreibreihenfolge (`id`), nicht eine nach `article` sortierte Liste.
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Stock;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\PurchaseBackendController;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\SupplierListener;
use Webwerkwien\ContaoConshoBundle\Stock\PurchaseLists;
use Webwerkwien\ContaoConshoBundle\Stock\StockBooking;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block G, G-7: Purchasing and purchase lists.
 *
 * Hiking pole (1): reorder level 5, target stock 20, pack size 6, stock 3.
 * Backpack (2): reorder level 2, no target stock, single items, stock 10.
 * Key (3): digital, delivery by key — never in purchasing (E4).
 */
class PurchaseListsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_stock', 'tl_consho_stock_movement', 'tl_consho_purchase_list', 'tl_consho_purchase_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'title' => 'Wanderstock', 'sku' => 'WS', 'supplierSku' => 'L-77', 'stockTracked' => 1, 'stockWarning' => '5.0000', 'stockTarget' => '20.0000', 'packUnit' => 6, 'supplier' => 'Bergsport KG']);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'title' => 'Rucksack', 'stockTracked' => 1, 'stockWarning' => '2.0000', 'supplier' => 'Taschen &amp; Co']);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'title' => 'Lizenz', 'productType' => 2, 'digitalDelivery' => 'key']);

        foreach ([1, 2, 3] as $id) {
            (new StockRows($this->connection))->sync($id, 100);
        }

        $this->stock(1, '3');
        $this->stock(2, '10');
    }

    public function testDerVorschlagFuelltAufDenSollbestandInGanzenPackungen(): void
    {
        $lines = $this->byArticle($this->lists()->candidates());

        self::assertSame([1, 2], array_keys($lines), 'Schlüssel nie im Einkauf (E4)');
        // 20 − 3 = 17, rounded up to whole packs of 6: 18.
        self::assertSame('18.0000', $lines[1]['proposed']);
        self::assertSame('Bergsport KG', $lines[1]['supplier']);
        self::assertSame('L-77', $lines[1]['supplierSku']);
        self::assertNull($lines[2]['proposed'], 'über dem Meldebestand: nicht vorgeschlagen, aber wählbar');

        // Without a target stock: one pack, here one item.
        $this->stock(2, '2');
        self::assertSame('1.0000', $this->byArticle($this->lists()->candidates())[2]['proposed']);
    }

    /**
     * E1: What is on order does not come again — and counts towards the stock
     * when the reorder level is asked.
     */
    public function testBestelltesWirdAbgezogen(): void
    {
        $this->lists()->create([1 => '12.0000'], 7, 1000);

        $line = $this->byArticle($this->lists()->candidates())[1];
        self::assertSame('12.0000', $line['ordered']);
        // 3 + 12 > 5: no longer below the reorder level.
        self::assertNull($line['proposed']);
    }

    public function testBestelltesUnterDemMeldebestandWirdVomVorschlagAbgezogen(): void
    {
        $this->stock(1, '0');
        $this->lists()->create([1 => '2'], 7, 1000);

        // 0 + 2 ≤ 5: still suggested, 20 − 0 − 2 = 18 → three packs of 6.
        self::assertSame('18.0000', $this->byArticle($this->lists()->candidates())[1]['proposed']);
    }

    public function testDieListeWirdEingefrorenGespeichert(): void
    {
        $listId = $this->lists()->create([1 => '18', 2 => '0', 99 => '5'], 7, 1000);

        $list = $this->connection->fetchAssociative('SELECT * FROM tl_consho_purchase_list WHERE id = ?', [$listId]);
        self::assertIsArray($list);
        self::assertSame(['EK-0001', 1, 'Bergsport KG', 'open', 7], [$list['number'], (int) $list['lineCount'], $list['suppliers'], $list['state'], (int) $list['user']]);

        $this->connection->update('tl_consho_product', ['title' => 'Umbenannt'], ['id' => 1]);
        $items = $this->lists()->items($listId);
        self::assertCount(1, $items, 'null und unbekannte Artikel nicht');
        self::assertSame(['Wanderstock', 'WS', 'L-77', 6], [$items[0]['title'], $items[0]['sku'], $items[0]['supplierSku'], (int) $items[0]['packUnit']]);

        self::assertSame(0, $this->lists()->create([2 => '0'], 7, 1000), 'nichts gewählt: keine Liste');
    }

    public function testEingangTeilweiseUndGanz(): void
    {
        $listId = $this->lists()->create([1 => '18', 2 => '4'], 7, 1000);
        [$stick, $bag] = array_map(static fn (array $item): int => (int) $item['id'], $this->lists()->items($listId));

        self::assertNull($this->lists()->receive($listId, [$stick => '12', $bag => '4'], [], 9, 2000));
        self::assertSame('15.0000', $this->stockOf(1));
        self::assertSame('14.0000', $this->stockOf(2));
        self::assertSame(['partial', 'received'], $this->itemStates($listId));
        self::assertSame('partial', $this->connection->fetchOne('SELECT state FROM tl_consho_purchase_list WHERE id = ?', [$listId]));
        self::assertSame('6.0000', (string) ($this->lists()->ordered([1])[1] ?? '0')->toScale(4));

        $movement = $this->connection->fetchAssociative("SELECT reason, purchaseList, user FROM tl_consho_stock_movement WHERE reason = 'receipt' ORDER BY id LIMIT 1");
        self::assertSame(['receipt', $listId, 9], [$movement['reason'] ?? null, (int) ($movement['purchaseList'] ?? 0), (int) ($movement['user'] ?? 0)]);

        // More than is open: rejected, nothing booked.
        self::assertSame('invalidQuantity', $this->lists()->receive($listId, [$stick => '7'], [], 9, 2001));
        self::assertSame('15.0000', $this->stockOf(1));

        self::assertNull($this->lists()->receive($listId, [$stick => '6'], [], 9, 2002));
        self::assertSame('done', $this->connection->fetchOne('SELECT state FROM tl_consho_purchase_list WHERE id = ?', [$listId]));
    }

    /**
     * G-7: the checkbox "rest not deliverable" next to the
     * pre-filled quantity — that would have booked goods that never came.
     */
    public function testNichtLieferbarMitDemGanzenRestIstEinWiderspruch(): void
    {
        $listId = $this->lists()->create([1 => '18'], 7, 1000);
        $item = (int) $this->lists()->items($listId)[0]['id'];

        self::assertSame('unavailableWithRest', $this->lists()->receive($listId, [$item => '18'], [$item => true], 9, 2000));
        self::assertSame('3.0000', $this->stockOf(1));

        // Part came, the rest did not: booked and closed.
        self::assertNull($this->lists()->receive($listId, [$item => '6'], [$item => true], 9, 2001));
        self::assertSame('9.0000', $this->stockOf(1));
        self::assertSame(['unavailable'], $this->itemStates($listId));
    }

    /**
     * G-7: without a stock row, no silent "received".
     */
    public function testOhneBestandszeileWirdNichtsAlsEingegangenVermerkt(): void
    {
        $listId = $this->lists()->create([1 => '6'], 7, 1000);
        $item = (int) $this->lists()->items($listId)[0]['id'];
        $this->connection->delete('tl_consho_stock', ['article' => 1]);

        self::assertSame('noStock', $this->lists()->receive($listId, [$item => '6'], [], 9, 2000));
        self::assertSame(['open'], $this->itemStates($listId));
    }

    /**
     * E3: The supplier cannot deliver — the rest no longer counts as
     * ordered, the article comes back in the next purchase.
     */
    public function testNichtLieferbarBringtDenArtikelZurueck(): void
    {
        $listId = $this->lists()->create([1 => '18'], 7, 1000);
        $item = (int) $this->lists()->items($listId)[0]['id'];
        self::assertNull($this->byArticle($this->lists()->candidates())[1]['proposed']);

        self::assertNull($this->lists()->receive($listId, [$item => '0'], [$item => true], 9, 2000));

        self::assertSame(['unavailable'], $this->itemStates($listId));
        self::assertSame('done', $this->connection->fetchOne('SELECT state FROM tl_consho_purchase_list WHERE id = ?', [$listId]));
        self::assertSame([], $this->lists()->ordered([1]));
        self::assertSame('18.0000', $this->byArticle($this->lists()->candidates())[1]['proposed']);
    }

    public function testMengenAusStueckOderPackungen(): void
    {
        $candidates = [['article' => 1, 'packUnit' => 6], ['article' => 2, 'packUnit' => 1]];

        self::assertSame([1 => '18.0000', 2 => '2.5000'], PurchaseBackendController::quantities([1 => '1', 2 => '1'], [1 => '99', 2 => '2,5'], [1 => '3'], $candidates));
        self::assertSame([2 => '0.0000'], PurchaseBackendController::quantities([2 => '1'], [], [], $candidates), 'leer ist null');
        self::assertSame([], PurchaseBackendController::quantities([], [2 => '5'], [], $candidates), 'nur übernommene Zeilen');
        self::assertNull(PurchaseBackendController::quantities([1 => '1'], [], [1 => '1.5'], $candidates), 'Packungen ganzzahlig');
        self::assertNull(PurchaseBackendController::quantities([2 => '1'], [2 => 'viel'], [], $candidates));
    }

    public function testDieAusgabeHatDieSpaltenFuerDenEinkaufAusserhalb(): void
    {
        $listId = $this->lists()->create([1 => '18'], 7, 1000);

        self::assertSame(
            [['Lieferant', 'Bestellnummer Lieferant', 'Artikelnummer', 'Artikel', 'Stück', 'VPE', 'Packungen'], ['Bergsport KG', 'L-77', 'WS', 'Wanderstock', '18', '6', '3']],
            PurchaseBackendController::table($this->lists()->items($listId), []),
        );
    }

    /**
     * E5 a: Upper/lower case and spaces are aligned to an existing name;
     * "KG" is a different name.
     */
    public function testDerLieferantWirdAngeglichen(): void
    {
        $listener = new SupplierListener($this->connection);

        self::assertSame(['Bergsport KG', 'Taschen &amp; Co'], $listener->options());
        self::assertSame('Bergsport KG', $listener->save('  bergsport   kg '));
        self::assertSame('Bergsport', $listener->save('Bergsport'));
        self::assertSame('', $listener->save('   '));
    }

    public function testDerBestandKenntSeinenLieferanten(): void
    {
        self::assertSame('Bergsport KG', $this->connection->fetchOne('SELECT supplier FROM tl_consho_stock WHERE article = 1'));

        $this->connection->update('tl_consho_product', ['supplier' => 'Neu'], ['id' => 1]);
        (new StockRows($this->connection))->sync(1, 200);

        self::assertSame('Neu', $this->connection->fetchOne('SELECT supplier FROM tl_consho_stock WHERE article = 1'));
    }

    private function lists(): PurchaseLists
    {
        return new PurchaseLists($this->connection, new StockBooking($this->connection));
    }

    private function stock(int $article, string $quantity): void
    {
        $this->connection->update('tl_consho_stock', ['quantity' => $quantity], ['article' => $article]);
    }

    private function stockOf(int $article): string
    {
        return StockBooking::scaled($this->connection->fetchOne('SELECT quantity FROM tl_consho_stock WHERE article = ?', [$article]));
    }

    /**
     * @return list<string>
     */
    private function itemStates(int $listId): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn('SELECT state FROM tl_consho_purchase_item WHERE pid = ? ORDER BY id', [$listId]));
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<int, array<string, mixed>>
     */
    private function byArticle(array $lines): array
    {
        return array_column($lines, null, 'article');
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PriceRefreshTriggerListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ProductRefreshTriggerListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ShopSyncTriggerListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\VariantDeleteListener;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

class RefreshTriggerListenerTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    private CatalogRefreshQueue $queue;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        // Preiszeile 7 gehört zu Produkt 3.
        $this->insertPrice(['id' => 7, 'pid' => 3, 'net' => '10.0000']);

        $this->queue = new CatalogRefreshQueue();
    }

    public function testSavingAPriceReportsItsProduct(): void
    {
        $this->prices()->onSubmit($this->dc(7));

        self::assertSame([3], $this->queue->take());
    }

    public function testCopyingAPriceReportsTheProductOfTheNewRow(): void
    {
        // Mit dem Produkt kopiert: die neue Zeile hängt an der Kopie, nicht am Original.
        $this->insertPrice(['id' => 8, 'pid' => 20, 'net' => '10.0000']);

        $this->prices()->onCopy(8, $this->dc(7));

        self::assertSame([20], $this->queue->take());
    }

    public function testDeletingAPriceReportsItsProductWhileTheRowStillExists(): void
    {
        $this->prices()->onDelete($this->dc(7), 1);

        self::assertSame([3], $this->queue->take());
    }

    public function testRestoringAPriceReportsItsProduct(): void
    {
        $this->prices()->onUndo('tl_consho_price', ['id' => 9, 'pid' => 4], $this->dc(0));

        self::assertSame([4], $this->queue->take());
    }

    public function testRestoringAPriceVersionReportsItsProduct(): void
    {
        /*
         * $data so, wie Versions::restore() es übergibt: OHNE id, pid, ptable und
         * sorting — der Kern entfernt sie vor dem Callback. Der erste Test hier
         * übergab pid mit und war grün, während auf c5 nichts neu gerechnet wurde.
         */
        $this->prices()->onRestoreVersion('tl_consho_price', 7, 2, ['tstamp' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'gross' => '12.0000']);

        self::assertSame([3], $this->queue->take());
    }

    public function testAPriceThatIsGoneReportsNothing(): void
    {
        $this->prices()->onSubmit($this->dc(99));

        self::assertSame([], $this->queue->take());
    }

    public function testEveryProductChangeReportsTheProduct(): void
    {
        $products = $this->products();

        $products->onSubmit($this->dc(1));
        $products->onCopy(2, $this->dc(1));
        $products->onDelete($this->dc(5), 1);
        $products->onUndo('tl_consho_product', ['id' => 6, 'pid' => 0], $this->dc(0));
        // B-10: Eine zurückgeholte Version kann Veröffentlichung oder Zeitfenster
        // ändern. $data wie vom Kern: ohne id und pid.
        $products->onRestoreVersion('tl_consho_product', 8, 3, ['tstamp' => 1, 'published' => 1]);

        self::assertSame([1, 2, 5, 6, 8], $this->queue->take());
    }

    /**
     * Abnahme 2026-10-14 auf c5: Hauptprodukt 27 gelöscht, die Zeile seiner
     * Variante 28 blieb in tl_consho_product_list_price stehen. Gemeldet war nur
     * die 27 — und am Ende des Requests, wenn der Projektor sie zu ihren
     * Varianten erweitert, hat VariantDeleteListener die 28 schon gelöscht.
     * Deshalb meldet das ondelete die Varianten selbst, solange es sie gibt.
     */
    public function testDeletingAMainProductReportsItsVariantsWhileTheyStillExist(): void
    {
        $this->insertProduct(['id' => 5, 'pid' => 0]);
        $this->insertProduct(['id' => 6, 'pid' => 5]);
        $this->insertProduct(['id' => 7, 'pid' => 5]);
        $this->insertProduct(['id' => 9, 'pid' => 0]);

        $this->products()->onDelete($this->dc(5), 1);

        self::assertSame([5, 6, 7], $this->queue->take());
    }

    /**
     * Die Varianten stehen nur vor VariantDeleteListener noch in der Tabelle:
     * Contao ruft die höhere Priorität zuerst.
     */
    public function testTheProductDeleteReportRunsBeforeTheVariantsAreDeleted(): void
    {
        $priority = static fn (string $class): int => (new \ReflectionMethod($class, 'onDelete'))->getAttributes(AsCallback::class)[0]->getArguments()['priority'] ?? 0;

        self::assertGreaterThan($priority(VariantDeleteListener::class), $priority(ProductRefreshTriggerListener::class));
    }

    public function testShopAndRootPageChangesRequestAShopSyncAndNoProduct(): void
    {
        $shops = new ShopSyncTriggerListener($this->queue);

        foreach ([
            static fn () => $shops->onSubmit(self::dcWithId(1)),
            static fn () => $shops->onCopy(2, self::dcWithId(1)),
            static fn () => $shops->onDelete(self::dcWithId(1), 1),
            static fn () => $shops->onUndo('tl_page', ['id' => 1], self::dcWithId(0)),
            static fn () => $shops->onRestoreVersion('tl_consho_shop', 1, 2, []),
        ] as $call) {
            $call();

            self::assertTrue($this->queue->takeShopSync());
            self::assertFalse($this->queue->takeShopSync(), 'Die Anforderung ist nach dem Abholen weg.');
        }

        self::assertSame([], $this->queue->take());
    }

    public function testTheQueueHoldsEachProductOnceAndResetsEverything(): void
    {
        // Ein Produkt mit zehn Preisen meldet sich beim Kopieren zehnmal.
        $this->queue->add(3);
        $this->queue->add(3);
        $this->queue->add(5);
        $this->queue->add(0);

        self::assertSame([3, 5], $this->queue->take());
        self::assertSame([], $this->queue->take());

        $this->queue->add(4);
        $this->queue->requestShopSync();
        $this->queue->reset();

        self::assertSame([], $this->queue->take());
        self::assertFalse($this->queue->takeShopSync());
    }

    private function products(): ProductRefreshTriggerListener
    {
        return new ProductRefreshTriggerListener($this->connection, $this->queue);
    }

    private function prices(): PriceRefreshTriggerListener
    {
        return new PriceRefreshTriggerListener($this->connection, $this->queue);
    }

    private function dc(int $id): DC_Table
    {
        return self::dcWithId($id);
    }

    /**
     * Wie copyChildren() ihn baut: ohne Konstruktor, nur die id gesetzt.
     */
    private static function dcWithId(int $id): DC_Table
    {
        $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
        $dc->id = $id;

        return $dc;
    }
}

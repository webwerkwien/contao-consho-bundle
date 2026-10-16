<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\TaxRateRefreshTriggerListener;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Ein geänderter Steuersatz ändert das Anzeige-Brutto jedes Artikels dieser
 * Klasse (C25) — gemeldet werden die Artikel, gerechnet wird am Ende des Requests.
 */
class TaxRateRefreshTriggerListenerTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    private CatalogRefreshQueue $queue;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_tax_rate');

        // Klasse 1: Produkt 1 und seine Variante 10. Klasse 2: Produkt 2.
        $this->insertProduct(['id' => 1, 'pid' => 0, 'taxClass' => 1]);
        $this->insertProduct(['id' => 10, 'pid' => 1, 'taxClass' => 1]);
        $this->insertProduct(['id' => 2, 'pid' => 0, 'taxClass' => 2]);
        $this->connection->insert('tl_consho_tax_rate', ['id' => 7, 'pid' => 1, 'country' => 'AT', 'rate' => '20.0000']);
        $this->connection->insert('tl_consho_tax_rate', ['id' => 8, 'pid' => 2, 'country' => 'AT', 'rate' => '10.0000']);

        $this->queue = new CatalogRefreshQueue();
    }

    public function testSavingARateReportsEveryArticleOfItsClass(): void
    {
        $this->listener()->onSubmit($this->dc(7));

        self::assertSame([1, 10], $this->queue->take());
    }

    public function testCopyingARateReportsTheClassOfTheNewRow(): void
    {
        $this->listener()->onCopy(8, $this->dc(7));

        self::assertSame([2], $this->queue->take());
    }

    public function testDeletingARateReportsWhileTheRowStillExists(): void
    {
        $this->listener()->onDelete($this->dc(7), 1);

        self::assertSame([1, 10], $this->queue->take());
    }

    public function testRestoringARateReportsTheClassFromTheRow(): void
    {
        $this->listener()->onUndo('tl_consho_tax_rate', ['id' => 9, 'pid' => 2], $this->dc(0));

        self::assertSame([2], $this->queue->take());
    }

    public function testRestoringARateVersionReportsItsClass(): void
    {
        // $data ohne id und pid, wie Versions::restore() es übergibt.
        $this->listener()->onRestoreVersion('tl_consho_tax_rate', 7, 2, ['rate' => '19.0000']);

        self::assertSame([1, 10], $this->queue->take());
    }

    public function testDeletingATaxClassReportsItsArticles(): void
    {
        // Die Sätze gehen als Kindzeilen mit, ohne ihr eigenes ondelete.
        $this->listener()->onDeleteClass($this->dc(2), 1);

        self::assertSame([2], $this->queue->take());
    }

    public function testRestoringATaxClassReportsItsArticles(): void
    {
        $this->listener()->onUndoClass('tl_consho_tax_class', ['id' => 1], $this->dc(0));

        self::assertSame([1, 10], $this->queue->take());
    }

    public function testAnUnknownRateReportsNothing(): void
    {
        $this->listener()->onSubmit($this->dc(99));

        self::assertSame([], $this->queue->take());
    }

    private function listener(): TaxRateRefreshTriggerListener
    {
        return new TaxRateRefreshTriggerListener($this->connection, $this->queue);
    }

    /**
     * Wie copyChildren() ihn baut: ohne Konstruktor, nur die id gesetzt.
     */
    private function dc(int $id): DC_Table
    {
        $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
        $dc->id = $id;

        return $dc;
    }
}

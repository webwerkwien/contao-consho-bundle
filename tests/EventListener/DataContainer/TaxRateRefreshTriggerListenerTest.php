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
 * A changed tax rate changes the display gross of every item of this class
 * (C25) — the items are reported, the calculation happens at the end of the
 * request.
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

        // Class 1: product 1 and its variant 10. Class 2: product 2.
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
        // $data without id and pid, as Versions::restore() passes it.
        $this->listener()->onRestoreVersion('tl_consho_tax_rate', 7, 2, ['rate' => '19.0000']);

        self::assertSame([1, 10], $this->queue->take());
    }

    public function testDeletingATaxClassReportsItsArticles(): void
    {
        // The rates go along as child rows, without their own ondelete.
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
     * As copyChildren() builds it: without a constructor, only the id set.
     */
    private function dc(int $id): DC_Table
    {
        $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
        $dc->id = $id;

        return $dc;
    }
}

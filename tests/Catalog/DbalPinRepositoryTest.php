<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalPinRepository;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Column types as on a real installation (SHOW COLUMNS): invisible tinyint,
 * start/stop varchar(10) with '' as "not set", ptable varchar.
 */
class DbalPinRepositoryTest extends TestCase
{
    private const NOW = 1_700_000_000;
    private const LIST = 50;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            "CREATE TABLE tl_content (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pid INTEGER NOT NULL DEFAULT 0,
                ptable TEXT NOT NULL DEFAULT 'tl_article',
                type TEXT NOT NULL DEFAULT 'text',
                sorting INTEGER NOT NULL DEFAULT 0,
                invisible INTEGER NOT NULL DEFAULT 0,
                start TEXT NOT NULL DEFAULT '',
                stop TEXT NOT NULL DEFAULT '',
                conshoProduct INTEGER NOT NULL DEFAULT 0
            )",
        );
        DcaSchema::create($this->connection, 'tl_consho_product');
    }

    public function testThePinsInTheDraggedOrder(): void
    {
        $this->pin(product: 7, sorting: 256);
        $this->pin(product: 3, sorting: 128);
        $this->pin(product: 9, sorting: 384);

        self::assertSame([3, 7, 9], $this->repository()->productIdsOf(self::LIST, self::NOW));
    }

    public function testOnlyPinsNestedInThisListing(): void
    {
        $this->pin(product: 1);
        $this->pin(product: 2, pid: 51);
        // Same pid, but in article 50 instead of in element 50 — dynamicPtable.
        $this->pin(product: 3, ptable: 'tl_article');
        $this->pin(product: 4, type: 'text');
        $this->pin(product: 0);

        self::assertSame([1], $this->repository()->productIdsOf(self::LIST, self::NOW));
    }

    public function testInvisibleAndOutOfPeriodPinsAreLeftOut(): void
    {
        $this->pin(product: 1);
        $this->pin(product: 2, invisible: true);
        $this->pin(product: 3, start: (string) (self::NOW + 60));
        $this->pin(product: 4, stop: (string) self::NOW);
        $this->pin(product: 5, start: (string) (self::NOW - 60), stop: (string) (self::NOW + 60));

        self::assertSame([1, 5], $this->repository()->productIdsOf(self::LIST, self::NOW));
    }

    public function testThePreviewShowsThemAll(): void
    {
        // Like ContentModel::findPublishedByPidAndTable() in the preview.
        $this->pin(product: 1);
        $this->pin(product: 2, invisible: true);
        $this->pin(product: 3, start: (string) (self::NOW + 60));

        self::assertSame([1, 2, 3], $this->repository()->productIdsOf(self::LIST, self::NOW, preview: true));
    }

    public function testTheNextChangeIsTheEarliestFutureStartOrStop(): void
    {
        $this->pin(product: 1, start: (string) (self::NOW - 600), stop: (string) (self::NOW + 900));
        $this->pin(product: 2, start: (string) (self::NOW + 300));
        $this->pin(product: 3, start: (string) (self::NOW + 100), invisible: true);
        $this->pin(product: 4, start: (string) (self::NOW + 50), pid: 51);

        self::assertSame(self::NOW + 300, $this->repository()->nextChange(self::LIST, self::NOW));
    }

    public function testNoNextChangeWithoutAnyScheduledPin(): void
    {
        $this->pin(product: 1);
        $this->pin(product: 2, stop: (string) (self::NOW - 1));

        self::assertNull($this->repository()->nextChange(self::LIST, self::NOW));
    }

    public function testLabelsOfMainProducts(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 1, 'title' => 'Kräutersalz', 'sku' => 'KS-1']);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'title' => 'Essig']);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'pid' => 1, 'title' => 'Variante']);

        self::assertSame('Kräutersalz (KS-1)', $this->repository()->labelOf(1));
        self::assertNull($this->repository()->labelOf(3));
        self::assertNull($this->repository()->labelOf(99));
        self::assertSame([2 => 'Essig', 1 => 'Kräutersalz (KS-1)'], $this->repository()->mainProductOptions());
    }

    private function repository(): DbalPinRepository
    {
        return new DbalPinRepository($this->connection);
    }

    private function pin(int $product, int $sorting = 0, int $pid = self::LIST, string $ptable = 'tl_content', string $type = DbalPinRepository::TYPE, bool $invisible = false, string $start = '', string $stop = ''): void
    {
        $this->connection->insert('tl_content', [
            'pid' => $pid,
            'ptable' => $ptable,
            'type' => $type,
            'sorting' => $sorting,
            'invisible' => $invisible ? 1 : 0,
            'start' => $start,
            'stop' => $stop,
            'conshoProduct' => $product,
        ]);
    }
}

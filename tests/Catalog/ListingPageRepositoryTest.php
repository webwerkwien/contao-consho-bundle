<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingPageRepository;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class ListingPageRepositoryTest extends TestCase
{
    // Ten digits like a real timestamp: start and stop are varchar(10) in
    // Contao, so the comparison runs as a string.
    private const NOW = 1_700_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Types and defaults as in core-bundle 5.7 (tl_page, tl_article,
        // tl_content) and in Consho's tl_content DCA.
        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->executeStatement(
            "CREATE TABLE tl_article (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                sorting INTEGER NOT NULL DEFAULT 0,
                published INTEGER NOT NULL DEFAULT 0,
                start VARCHAR(10) NOT NULL DEFAULT '',
                stop VARCHAR(10) NOT NULL DEFAULT ''
            )",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_content (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                ptable VARCHAR(64) NOT NULL DEFAULT 'tl_article',
                sorting INTEGER NOT NULL DEFAULT 0,
                tstamp INTEGER NOT NULL DEFAULT 0,
                type VARCHAR(64) NOT NULL DEFAULT 'text',
                invisible INTEGER NOT NULL DEFAULT 0,
                start VARCHAR(10) NOT NULL DEFAULT '',
                stop VARCHAR(10) NOT NULL DEFAULT '',
                conshoFilter BLOB NULL,
                conshoShowCounts INTEGER NOT NULL DEFAULT 1,
                conshoFollowsAddress INTEGER NOT NULL DEFAULT 1,
                conshoPinsOnly INTEGER NOT NULL DEFAULT 0
            )",
        );

        $this->connection->insert('tl_page', ['id' => 12, 'title' => 'Kräuterbitter']);
        $this->connection->insert('tl_page', ['id' => 13, 'title' => 'Tees']);
    }

    public function testFindsTheListingInAnArticleOfThePage(): void
    {
        $this->article(100, page: 12);
        $this->listing(636, article: 100, preset: serialize(['11', '21']), showCounts: false);

        $listing = $this->repository()->firstOnPage(12, self::NOW);

        self::assertNotNull($listing);
        self::assertSame(636, $listing->id);
        self::assertSame(12, $listing->pageId);
        self::assertSame([11, 21], $listing->preset);
        self::assertFalse($listing->showCounts);
        self::assertTrue($listing->followsAddress);
    }

    public function testTakesTheFirstByArticleOrderThenElementOrder(): void
    {
        // The second element is in the FIRST article — the article order
        // decides before the element order, as on the page.
        $this->article(101, page: 12, sorting: 256);
        $this->article(100, page: 12, sorting: 128);
        $this->listing(640, article: 101, sorting: 64);
        $this->listing(641, article: 100, sorting: 512);
        $this->listing(642, article: 100, sorting: 256);

        self::assertSame(642, $this->repository()->firstOnPage(12, self::NOW)?->id);
    }

    public function testSkipsAListingThatDoesNotFollowTheAddress(): void
    {
        // A curated list before the category list must not take the filter
        // for itself.
        $this->article(100, page: 12);
        $this->listing(640, article: 100, sorting: 64, follows: false);
        $this->listing(641, article: 100, sorting: 128);

        self::assertSame(641, $this->repository()->firstOnPage(12, self::NOW)?->id);
    }

    public function testSkipsAnInvisibleListing(): void
    {
        $this->article(100, page: 12);
        $this->listing(640, article: 100, invisible: true);

        self::assertNull($this->repository()->firstOnPage(12, self::NOW));
    }

    public function testSkipsAnUnsavedListing(): void
    {
        // tstamp 0: the row exists but was never actually saved (#2708) — the
        // same guard as ContentModel::findPublishedByPidAndTable().
        $this->article(100, page: 12);
        $this->listing(640, article: 100, tstamp: 0);

        self::assertNull($this->repository()->firstOnPage(12, self::NOW));
    }

    public function testSkipsAListingOutsideItsWindow(): void
    {
        $this->article(100, page: 12);
        $this->listing(640, article: 100, start: (string) (self::NOW + 60));
        $this->listing(641, article: 100, stop: (string) self::NOW);

        self::assertNull($this->repository()->firstOnPage(12, self::NOW));
    }

    public function testFindsAListingInsideItsWindow(): void
    {
        $this->article(100, page: 12);
        $this->listing(640, article: 100, start: (string) (self::NOW - 60), stop: (string) (self::NOW + 60));

        self::assertSame(640, $this->repository()->firstOnPage(12, self::NOW)?->id);
    }

    public function testSkipsAListingInAnUnpublishedArticle(): void
    {
        $this->article(100, page: 12, published: false);
        $this->listing(640, article: 100);

        self::assertNull($this->repository()->firstOnPage(12, self::NOW));
    }

    public function testSkipsAListingInAnArticleOutsideItsWindow(): void
    {
        $this->article(100, page: 12, start: (string) (self::NOW + 60));
        $this->article(101, page: 12, stop: (string) self::NOW);
        $this->listing(640, article: 100);
        $this->listing(641, article: 101);

        self::assertNull($this->repository()->firstOnPage(12, self::NOW));
    }

    public function testDoesNotFindAListingOfAnotherPage(): void
    {
        $this->article(100, page: 13);
        $this->listing(640, article: 100);

        self::assertNull($this->repository()->firstOnPage(12, self::NOW));
    }

    public function testDoesNotFindAnElementOfAnotherType(): void
    {
        $this->article(100, page: 12);
        $this->listing(640, article: 100, type: 'text');

        self::assertNull($this->repository()->firstOnPage(12, self::NOW));
    }

    public function testDoesNotTakeAnArticleIdForAPageId(): void
    {
        // An element in a layout section has ptable tl_theme, its pid
        // is the ID of the layout. If it happens to match an article ID,
        // the element still belongs to no page.
        $this->article(100, page: 12);
        $this->listing(640, article: 100, ptable: 'tl_theme');

        self::assertNull($this->repository()->firstOnPage(12, self::NOW));
    }

    public function testAnEmptyPresetIsAnEmptyList(): void
    {
        $this->article(100, page: 12);
        $this->listing(640, article: 100, preset: null);

        self::assertSame([], $this->repository()->firstOnPage(12, self::NOW)?->preset);
    }

    public function testFindsAListingByIdEvenIfItDoesNotFollowTheAddress(): void
    {
        // The caller decides what a non-following list means for it.
        $this->article(100, page: 12);
        $this->listing(99, article: 100, preset: serialize(['11']), follows: false);

        $listing = $this->repository()->byId(99);

        self::assertNotNull($listing);
        self::assertSame(99, $listing->id);
        self::assertSame(12, $listing->pageId);
        self::assertSame([11], $listing->preset);
        self::assertFalse($listing->followsAddress);
    }

    public function testByIdRefusesAnElementOfAnotherType(): void
    {
        $this->article(100, page: 12);
        $this->listing(99, article: 100, type: 'text');

        self::assertNull($this->repository()->byId(99));
    }

    public function testByIdOfAListingInALayoutSectionBelongsToNoPage(): void
    {
        $this->listing(99, article: 3, ptable: 'tl_theme');

        self::assertSame(0, $this->repository()->byId(99)?->pageId);
    }

    public function testListsEveryPageWithAFollowingListingOnce(): void
    {
        $this->article(100, page: 12);
        $this->listing(640, article: 100, sorting: 64);
        $this->listing(641, article: 100, sorting: 128);

        $this->article(101, page: 13);
        $this->listing(642, article: 101, follows: false);

        self::assertSame([12 => 'Kräuterbitter'], $this->repository()->pagesWithList(self::NOW));
    }

    public function testListsNoPageWhoseListingIsNotVisible(): void
    {
        $this->article(100, page: 12, published: false);
        $this->listing(640, article: 100);

        $this->article(101, page: 13);
        $this->listing(641, article: 101, invisible: true);

        self::assertSame([], $this->repository()->pagesWithList(self::NOW));
    }

    private function article(int $id, int $page, int $sorting = 0, bool $published = true, string $start = '', string $stop = ''): void
    {
        $this->connection->insert('tl_article', [
            'id' => $id, 'pid' => $page, 'sorting' => $sorting, 'published' => (int) $published, 'start' => $start, 'stop' => $stop,
        ]);
    }

    private function listing(
        int $id,
        int $article,
        int $sorting = 0,
        ?string $preset = null,
        bool $showCounts = true,
        bool $follows = true,
        bool $invisible = false,
        string $start = '',
        string $stop = '',
        string $type = 'consho_product_list',
        string $ptable = 'tl_article',
        int $tstamp = self::NOW,
    ): void {
        $this->connection->insert('tl_content', [
            'id' => $id,
            'pid' => $article,
            'ptable' => $ptable,
            'sorting' => $sorting,
            'tstamp' => $tstamp,
            'type' => $type,
            'invisible' => (int) $invisible,
            'start' => $start,
            'stop' => $stop,
            'conshoFilter' => $preset,
            'conshoShowCounts' => (int) $showCounts,
            'conshoFollowsAddress' => (int) $follows,
        ]);
    }

    public function testAllVisibleListingsInArticlesLayoutSectionsAndNestedElements(): void
    {
        // B-8, consho:catalog:verify: every visible list counts, wherever it is.
        $this->article(100, page: 12);
        $this->article(101, page: 13, published: false);
        $this->connection->insert('tl_content', ['id' => 1, 'pid' => 100, 'type' => 'consho_product_list', 'tstamp' => self::NOW, 'conshoFilter' => serialize(['11'])]);
        // Article unpublished.
        $this->connection->insert('tl_content', ['id' => 2, 'pid' => 101, 'type' => 'consho_product_list', 'tstamp' => self::NOW]);
        // Layout section: belongs to no page, so it reaches everywhere.
        $this->connection->insert('tl_content', ['id' => 3, 'pid' => 7, 'ptable' => 'tl_theme', 'type' => 'consho_product_list', 'tstamp' => self::NOW, 'conshoPinsOnly' => 1]);
        $this->connection->insert('tl_content', ['id' => 4, 'pid' => 100, 'type' => 'consho_product_list', 'tstamp' => self::NOW, 'invisible' => 1]);
        $this->connection->insert('tl_content', ['id' => 5, 'pid' => 100, 'type' => 'text', 'tstamp' => self::NOW]);
        // Never saved (#2708).
        $this->connection->insert('tl_content', ['id' => 6, 'pid' => 100, 'type' => 'consho_product_list', 'tstamp' => 0]);
        $this->connection->insert('tl_content', ['id' => 7, 'pid' => 100, 'type' => 'consho_product_list', 'tstamp' => self::NOW, 'start' => (string) (self::NOW + 60)]);

        $all = $this->repository()->allVisible(self::NOW);

        self::assertSame([1, 3], array_map(static fn ($listing): int => $listing->id, $all));
        self::assertSame(12, $all[0]->pageId);
        self::assertSame([11], $all[0]->preset);
        self::assertFalse($all[0]->pinsOnly);
        self::assertSame(0, $all[1]->pageId);
        self::assertTrue($all[1]->pinsOnly);
    }

    private function repository(): ListingPageRepository
    {
        return new ListingPageRepository($this->connection);
    }
}

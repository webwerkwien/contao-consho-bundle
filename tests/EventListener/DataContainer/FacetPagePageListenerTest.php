<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingPageRepository;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\FacetPagePageListener;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

class FacetPagePageListenerTest extends TestCase
{
    private Connection $connection;

    /** @var array<int, PageModel> */
    private array $pages = [];

    /** @var array<int, ShopModel> */
    private array $shops = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, title TEXT NOT NULL DEFAULT '')",
        );
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
                tstamp INTEGER NOT NULL DEFAULT 1,
                type VARCHAR(64) NOT NULL DEFAULT 'text',
                invisible INTEGER NOT NULL DEFAULT 0,
                start VARCHAR(10) NOT NULL DEFAULT '',
                stop VARCHAR(10) NOT NULL DEFAULT '',
                conshoFilter BLOB NULL,
                conshoShowCounts INTEGER NOT NULL DEFAULT 1,
                conshoFollowsAddress INTEGER NOT NULL DEFAULT 1
            )",
        );

        $this->shops[7] = $this->createMock(ShopModel::class);

        $this->root(1, 'Kräuterladen', 'de', shop: 7);
        $this->root(2, 'Herb shop', 'en', shop: 7);
        $this->root(3, 'Blog', 'de', shop: 0);
    }

    public function testOffersPagesWithAListingGroupedByStartPoint(): void
    {
        // Dieselbe Kategorie gibt es je Sprache einmal; der Redakteur muss sehen,
        // für welche er den Titel schreibt.
        $this->listingPage(12, 'Kräuterbitter', root: 1, language: 'de');
        $this->listingPage(22, 'Herbal bitters', root: 2, language: 'en');

        // Startpunkte alphabetisch, Seiten darin nach Titel.
        self::assertSame(
            [
                'Herb shop (en)' => [22 => 'Herbal bitters'],
                'Kräuterladen (de)' => [12 => 'Kräuterbitter'],
            ],
            $this->options(),
        );
    }

    public function testLeavesOutAPageWhoseStartPointHasNoShop(): void
    {
        // Ohne Shop gibt es keine Produkte und keine Filter — eine Landingpage
        // dort wäre leer.
        $this->listingPage(12, 'Kräuterbitter', root: 1, language: 'de');
        $this->listingPage(32, 'Neuigkeiten', root: 3, language: 'de');

        self::assertSame(['Kräuterladen (de)' => [12 => 'Kräuterbitter']], $this->options());
    }

    public function testLeavesOutAPageWithoutAListing(): void
    {
        $this->connection->insert('tl_page', ['id' => 13, 'pid' => 1, 'title' => 'Impressum']);
        $this->detailsOf(13, root: 1, language: 'de');

        self::assertSame([], $this->options());
    }

    public function testLeavesOutAPageThatCannotBeLoaded(): void
    {
        $this->listingPage(12, 'Kräuterbitter', root: 1, language: 'de');
        unset($this->pages[12]);

        self::assertSame([], $this->options());
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function options(): array
    {
        $pages = $this->createMock(Adapter::class);
        $pages
            ->method('__call')
            ->willReturnCallback(fn (string $method, array $arguments): ?PageModel => $this->pages[$arguments[0]] ?? null)
        ;

        $shops = $this->createMock(Adapter::class);
        $shops
            ->method('__call')
            ->willReturnCallback(fn (string $method, array $arguments): ?ShopModel => $this->shops[$arguments[0]] ?? null)
        ;

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnMap([
            [PageModel::class, $pages],
            [ShopModel::class, $shops],
        ]);

        $listener = new FacetPagePageListener(
            new ListingPageRepository($this->connection),
            $framework,
            new ShopResolver($framework),
        );

        return $listener();
    }

    private function root(int $id, string $title, string $language, int $shop): void
    {
        $this->connection->insert('tl_page', ['id' => $id, 'title' => $title]);
        $this->pages[$id] = $this->pageMock($id, $id, $title, $language, $shop);
    }

    private function listingPage(int $id, string $title, int $root, string $language): void
    {
        $this->connection->insert('tl_page', ['id' => $id, 'pid' => $root, 'title' => $title]);
        $this->connection->insert('tl_article', ['id' => $id * 10, 'pid' => $id, 'published' => 1]);
        $this->connection->insert('tl_content', ['id' => $id * 100, 'pid' => $id * 10, 'type' => 'consho_product_list']);

        $this->detailsOf($id, $root, $language);
    }

    private function detailsOf(int $id, int $root, string $language): void
    {
        $rootTitle = (string) $this->connection->fetchOne('SELECT title FROM tl_page WHERE id = ?', [$root]);
        $this->pages[$id] = $this->pageMock($id, $root, $rootTitle, $language, 0);
    }

    private function pageMock(int $id, int $rootId, string $rootTitle, string $language, int $shop): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', $id],
            ['rootId', $rootId],
            ['rootTitle', $rootTitle],
            ['language', $language],
        ]);
        $page->method('row')->willReturn(['id' => $id, 'consho_shop' => $shop]);

        return $page;
    }
}

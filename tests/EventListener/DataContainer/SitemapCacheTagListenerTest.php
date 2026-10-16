<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\SitemapCacheTagListener;
use Webwerkwien\ContaoConshoBundle\Seo\SitemapCacheTags;

/**
 * Startpunkte: 1 mit Shop 1, 2 ohne Shop (eine fremde Website), 3 mit Shop 2,
 * dazu eine normale Seite 4 mit einer versehentlich gesetzten Shop-Spalte.
 */
class SitemapCacheTagListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0)",
        );
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'consho_shop' => 1]);
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'consho_shop' => 0]);
        $this->connection->insert('tl_page', ['id' => 3, 'type' => 'root', 'consho_shop' => 2]);
        $this->connection->insert('tl_page', ['id' => 4, 'type' => 'regular', 'consho_shop' => 1]);
    }

    public function testAProductChangeInvalidatesTheSitemapsOfTheStartPointsWithAShop(): void
    {
        // Wie Contao selbst: je Startpunkt, nie der ganze Tag — die fremde Website 2 bleibt unberührt.
        $tags = $this->listener()($this->dc(7), ['contao.db.tl_consho_product.7']);

        self::assertSame(['contao.db.tl_consho_product.7', 'contao.sitemap.1', 'contao.sitemap.3'], $tags);
        self::assertNotContains('contao.sitemap', $tags);
    }

    public function testAShopChangeInvalidatesOnlyItsOwnStartPoints(): void
    {
        self::assertSame(['contao.db.tl_consho_shop.2', 'contao.sitemap.3'], $this->listener()->onShop($this->dc(2), ['contao.db.tl_consho_shop.2']));
    }

    public function testATagIsNeverAddedTwice(): void
    {
        self::assertSame(['contao.sitemap.1', 'contao.sitemap.3'], $this->listener()($this->dc(7), ['contao.sitemap.1']));
    }

    public function testRestoringFromTheRecycleBinInvalidatesTheShopSitemapsDirectly(): void
    {
        // DC_Table::undo() entwertet die Tags von tl_undo, nicht die des wiederhergestellten
        // Datensatzes — auf c5 blieb ein wiederhergestellter Preis deshalb aus der Sitemap.
        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with(['contao.sitemap.1', 'contao.sitemap.3']);

        (new SitemapCacheTagListener(new SitemapCacheTags($this->connection, $tags)))->onUndo('tl_consho_price', ['id' => 12, 'pid' => 9], $this->dc(0));
    }

    public function testRestoringAShopInvalidatesOnlyItsOwnStartPoints(): void
    {
        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with(['contao.sitemap.3']);

        (new SitemapCacheTagListener(new SitemapCacheTags($this->connection, $tags)))->onShopUndo('tl_consho_shop', ['id' => 2], $this->dc(0));
    }

    public function testARestoredShopWithoutIdInvalidatesNothing(): void
    {
        // forShops(0) nennte die Startpunkte OHNE Shop — also fremde Websites.
        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::never())->method('invalidateTags');

        (new SitemapCacheTagListener(new SitemapCacheTags($this->connection, $tags)))->onShopUndo('tl_consho_shop', [], $this->dc(0));
    }

    public function testHangsOnEveryTableThatMovesAConshoAddress(): void
    {
        /*
         * Die Tabellen, deren Änderung eine Adresse in die Sitemap bringt oder
         * herausnimmt — beim Speichern UND beim Wiederherstellen aus dem
         * Papierkorb. Fehlt eine, steht die Sitemap bis zu einem Monat falsch,
         * und nichts schlägt dabei fehl.
         */
        $expected = ['tl_consho_facet_page', 'tl_consho_manufacturer', 'tl_consho_price', 'tl_consho_product', 'tl_consho_product_type', 'tl_consho_shop'];

        self::assertSame($expected, $this->tablesOf(['__invoke', 'onShop'], 'config.oninvalidate_cache_tags'));
        self::assertSame($expected, $this->tablesOf(['onUndo', 'onShopUndo'], 'config.onundo'));
    }

    /**
     * @param list<string> $methods
     *
     * @return list<string>
     */
    private function tablesOf(array $methods, string $target): array
    {
        $tables = [];

        foreach ($methods as $method) {
            foreach ((new \ReflectionMethod(SitemapCacheTagListener::class, $method))->getAttributes(AsCallback::class) as $attribute) {
                $callback = $attribute->newInstance();

                if ($target === $callback->target) {
                    $tables[] = $callback->table;
                }
            }
        }

        sort($tables);

        return $tables;
    }

    private function listener(): SitemapCacheTagListener
    {
        return new SitemapCacheTagListener(new SitemapCacheTags($this->connection));
    }

    private function dc(int $id): DC_Table
    {
        $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
        $dc->id = $id;

        return $dc;
    }
}

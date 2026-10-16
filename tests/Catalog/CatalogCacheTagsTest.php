<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Contao\CoreBundle\Cache\CacheTagManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;

/**
 * Produkt 7 mit Variante 8, Preis 20 am Produkt, Preis 21 an der Variante.
 */
class CatalogCacheTagsTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $this->insertProduct(['id' => 7, 'pid' => 0]);
        $this->insertProduct(['id' => 8, 'pid' => 7]);
        $this->insertPrice(['id' => 20, 'pid' => 7, 'currency' => 'EUR']);
        $this->insertPrice(['id' => 21, 'pid' => 8, 'currency' => 'EUR']);
    }

    public function testAMainProductInvalidatesTheListingsAndItsOwnPage(): void
    {
        self::assertSame([CatalogCacheTags::LISTINGS, 'contao.db.tl_consho_product.7'], $this->tags()->forProduct(7));
    }

    public function testAVariantInvalidatesThePageOfItsMainProduct(): void
    {
        // Der Kern hängt nur contao.db.tl_consho_product.8 an — die Detailseite trägt die 7.
        self::assertSame([CatalogCacheTags::LISTINGS, 'contao.db.tl_consho_product.7'], $this->tags()->forProduct(8));
    }

    public function testAnUnknownProductStillInvalidatesTheListings(): void
    {
        self::assertSame([CatalogCacheTags::LISTINGS], $this->tags()->forProduct(99));
        self::assertSame([CatalogCacheTags::LISTINGS], $this->tags()->forProduct(0));
    }

    public function testAPriceInvalidatesThePageOfItsMainProduct(): void
    {
        self::assertSame([CatalogCacheTags::LISTINGS, 'contao.db.tl_consho_product.7'], $this->tags()->forPrice(20));

        // Der Kern hängt über ptable den Tag der VARIANTE an, nicht den des Hauptprodukts.
        self::assertSame([CatalogCacheTags::LISTINGS, 'contao.db.tl_consho_product.7'], $this->tags()->forPrice(21));
    }

    public function testAnUnknownPriceStillInvalidatesTheListings(): void
    {
        self::assertSame([CatalogCacheTags::LISTINGS], $this->tags()->forPrice(99));
    }

    public function testTheMainProductOfARowInHand(): void
    {
        self::assertSame(7, CatalogCacheTags::mainOfRow(['id' => 7, 'pid' => 0]));
        self::assertSame(7, CatalogCacheTags::mainOfRow(['id' => 8, 'pid' => 7]));
        self::assertSame(0, CatalogCacheTags::mainOfRow([]));
    }

    public function testInvalidatesThroughTheTagManager(): void
    {
        $manager = $this->createMock(CacheTagManager::class);
        $manager->expects(self::once())->method('invalidateTags')->with([CatalogCacheTags::CATALOG]);

        (new CatalogCacheTags($this->connection, $manager))->invalidate([CatalogCacheTags::CATALOG]);
    }

    public function testNothingToInvalidateCallsNothing(): void
    {
        $manager = $this->createMock(CacheTagManager::class);
        $manager->expects(self::never())->method('invalidateTags');

        (new CatalogCacheTags($this->connection, $manager))->invalidate([]);
    }

    public function testMergeKeepsEachTagOnce(): void
    {
        self::assertSame(['a', 'b', 'c'], CatalogCacheTags::merge(['a', 'b'], ['b', 'c']));
    }

    private function tags(): CatalogCacheTags
    {
        return new CatalogCacheTags($this->connection);
    }
}

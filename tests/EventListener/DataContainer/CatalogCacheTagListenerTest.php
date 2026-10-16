<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\CatalogCacheTagListener;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * Produkt 7 mit Variante 8, Preis 21 an der Variante.
 */
class CatalogCacheTagListenerTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $this->insertProduct(['id' => 7, 'pid' => 0]);
        $this->insertProduct(['id' => 8, 'pid' => 7]);
        $this->insertPrice(['id' => 21, 'pid' => 8, 'currency' => 'EUR']);
    }

    public function testSavingAVariantAddsTheListingsAndItsMainProduct(): void
    {
        // So liefert der Kern: eigener Tag und Tabellen-Tag (Modus 1, keine ptable).
        $core = ['contao.db.tl_consho_product.8', 'contao.db.tl_consho_product'];

        self::assertSame(
            [...$core, CatalogCacheTags::LISTINGS, 'contao.db.tl_consho_product.7'],
            $this->listener()->onProduct($this->dc(8), $core),
        );
    }

    public function testSavingAPriceAddsTheListingsAndTheMainProduct(): void
    {
        // Über ptable hängt der Kern den Tag der Variante an.
        $core = ['contao.db.tl_consho_price.21', 'contao.db.tl_consho_product.8'];

        self::assertSame(
            [...$core, CatalogCacheTags::LISTINGS, 'contao.db.tl_consho_product.7'],
            $this->listener()->onPrice($this->dc(21), $core),
        );
    }

    public function testALandingPageMovesOnlyTheListings(): void
    {
        self::assertSame(['x', CatalogCacheTags::LISTINGS], $this->listener()->onFacetPage($this->dc(3), ['x']));
    }

    public function testASharedRecordMovesTheWholeCatalogue(): void
    {
        self::assertSame(['x', CatalogCacheTags::CATALOG], $this->listener()->onShared($this->dc(3), ['x']));
    }

    public function testRestoringAVariantInvalidatesTheListingsAndItsMainProduct(): void
    {
        $this->expectInvalidated([CatalogCacheTags::LISTINGS, 'contao.db.tl_consho_product.7'])
            ->onProductUndo('tl_consho_product', ['id' => 8, 'pid' => 7], $this->dc(0));
    }

    public function testRestoringAPriceInvalidatesThePageOfItsMainProduct(): void
    {
        $this->expectInvalidated([CatalogCacheTags::LISTINGS, 'contao.db.tl_consho_product.7'])
            ->onPriceUndo('tl_consho_price', ['id' => 21, 'pid' => 8], $this->dc(0));
    }

    public function testRestoringALandingPageOrASharedRecord(): void
    {
        $this->expectInvalidated([CatalogCacheTags::LISTINGS])->onFacetPageUndo('tl_consho_facet_page', ['id' => 3], $this->dc(0));
        $this->expectInvalidated([CatalogCacheTags::CATALOG])->onSharedUndo('tl_consho_manufacturer', ['id' => 3], $this->dc(0));
    }

    public function testSubmittingInvalidatesTheRecordAgain(): void
    {
        // Nur eine Übersetzung geändert → kein UPDATE → der Kern entwertet nichts.
        $dc = $this->createMock(DC_Table::class);
        $dc->expects(self::once())->method('invalidateCacheTags');

        $this->listener()->onSubmit($dc);
    }

    public function testHangsOnEveryTableItNeeds(): void
    {
        /*
         * Fehlt eine Tabelle, zeigt eine gecachte Seite den alten Stand bis zum
         * Ablauf — und nichts schlägt dabei fehl.
         */
        // tl_consho_subscription_rule seit Abo-2: Titel und Bedingungen einer
        // Regel stehen in der Modellwahl jeder Produktseite, die sie anbietet.
        // Versandarten und ihre Preise seit Connect-1: Das JSON-LD der
        // Produktseite nennt die Versandkosten (K3). Zusatzfelder seit ZW-4:
        // ihr Titel steht unter „Angaben" jeder Produktseite des Typs.
        self::assertSame(
            ['tl_consho_custom_field', 'tl_consho_facet_page', 'tl_consho_manufacturer', 'tl_consho_price', 'tl_consho_product', 'tl_consho_product_type', 'tl_consho_property_group', 'tl_consho_property_option', 'tl_consho_shipping_method', 'tl_consho_shipping_price', 'tl_consho_shop', 'tl_consho_subscription_rule', 'tl_consho_tax_class', 'tl_consho_tax_rate'],
            $this->tablesOf(['onProduct', 'onPrice', 'onFacetPage', 'onShared'], 'config.oninvalidate_cache_tags'),
        );
        self::assertSame(
            ['tl_consho_custom_field', 'tl_consho_facet_page', 'tl_consho_manufacturer', 'tl_consho_price', 'tl_consho_product', 'tl_consho_product_type', 'tl_consho_property_group', 'tl_consho_property_option', 'tl_consho_shipping_method', 'tl_consho_shipping_price', 'tl_consho_shop', 'tl_consho_subscription_rule', 'tl_consho_tax_class', 'tl_consho_tax_rate'],
            $this->tablesOf(['onProductUndo', 'onPriceUndo', 'onFacetPageUndo', 'onSharedUndo'], 'config.onundo'),
        );

        // Genau die Tabellen mit Feldern außerhalb des eigenen UPDATE — beim
        // Zusatzfeld die Übersetzung des Titels.
        self::assertSame(
            ['tl_consho_custom_field', 'tl_consho_manufacturer', 'tl_consho_product', 'tl_consho_product_type', 'tl_consho_property_group', 'tl_consho_property_option'],
            $this->tablesOf(['onSubmit'], 'config.onsubmit'),
        );
    }

    /**
     * @param list<string> $tags
     */
    private function expectInvalidated(array $tags): CatalogCacheTagListener
    {
        $manager = $this->createMock(CacheTagManager::class);
        $manager->expects(self::once())->method('invalidateTags')->with($tags);

        return new CatalogCacheTagListener(new CatalogCacheTags($this->connection, $manager));
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
            foreach ((new \ReflectionMethod(CatalogCacheTagListener::class, $method))->getAttributes(AsCallback::class) as $attribute) {
                $callback = $attribute->newInstance();

                if ($target === $callback->target) {
                    $tables[] = $callback->table;
                }
            }
        }

        sort($tables);

        return $tables;
    }

    private function listener(): CatalogCacheTagListener
    {
        return new CatalogCacheTagListener(new CatalogCacheTags($this->connection));
    }

    private function dc(int $id): DC_Table
    {
        $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
        $dc->id = $id;

        return $dc;
    }
}

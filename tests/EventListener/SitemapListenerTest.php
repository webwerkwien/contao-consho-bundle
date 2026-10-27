<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener;

use Contao\CoreBundle\Event\SitemapEvent;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalPinRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\FacetPageHits;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterResolver;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingPageRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\EventListener\SitemapListener;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRecord;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageResolver;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Seo\FilterUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * The listener with a real ProductQuery, a real ProductUrlGenerator and a
 * real FilterUrlGenerator against SQLite. Only Contao's framework is
 * doubled: page and shop model, ContentUrlGenerator.
 *
 * Base data: root page 1 (de, shop 1, EUR) with product page 123 and the
 * list pages 12 "kraeuter" and 13 "tees". Root page 2 without a shop with
 * list page 14. Contao has written the start page and page 12 into its
 * sitemap, page 13 not (say, because it is noindex).
 */
class SitemapListenerTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_790_000_000;
    private const HOST = 'https://example.org';

    private Connection $connection;

    private bool $hasProductPage = true;

    /**
     * The currency that the page model of root page 1 returns. Since C-1c it
     * sits on the root page and no longer on the shop; whoever changes it
     * changes it in tl_page as well, or the projection calculates against a
     * different one than the listener.
     */
    private string $currencyOfRootPage = 'EUR';

    /**
     * @var list<FacetPageRecord>
     */
    private array $landingPages = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        // ShopLanguages reads the languages from the root pages.
        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'language' => 'de', 'consho_shop' => 1, 'consho_currency' => 'EUR']);
        $this->insertShop(['id' => 1, 'title' => 'Shop', 'fallbackLanguage' => 'de', 'country' => 'AT']);

        $this->product(1, 'schwedenbitter');
        $this->product(2, 'ohne-preis', priced: false);
        $this->product(3, 'entwurf', published: false);

        // A variant has no URL of its own — not even with a price of its own.
        $this->insertProduct(['id' => 10, 'pid' => 1, 'title' => 'Variante', 'alias' => 'variante', 'published' => 1]);
        $this->insertListPrice(['pid' => 10, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);

        /*
         * B-8: a landing page only goes into the sitemap with hits. For that,
         * page 12 carries a list without a preset (article 120, element 50),
         * the combination "volume:250ml" resolves against group 1, product 1
         * has it.
         */
        $this->connection->executeStatement(
            "CREATE TABLE tl_article (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, sorting INTEGER NOT NULL DEFAULT 0, published INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_content (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pid INTEGER NOT NULL DEFAULT 0,
                ptable TEXT NOT NULL DEFAULT 'tl_article',
                type TEXT NOT NULL DEFAULT '',
                sorting INTEGER NOT NULL DEFAULT 0,
                tstamp INTEGER NOT NULL DEFAULT 0,
                invisible INTEGER NOT NULL DEFAULT 0,
                start TEXT NOT NULL DEFAULT '',
                stop TEXT NOT NULL DEFAULT '',
                conshoFilter BLOB,
                conshoShowCounts INTEGER NOT NULL DEFAULT 0,
                conshoFollowsAddress INTEGER NOT NULL DEFAULT 1,
                conshoProduct INTEGER NOT NULL DEFAULT 0
            )",
        );
        $this->connection->insert('tl_article', ['id' => 120, 'pid' => 12, 'published' => 1]);
        $this->connection->insert('tl_content', ['id' => 50, 'pid' => 120, 'type' => 'consho_product_list', 'tstamp' => 1]);
        $this->insertPropertyGroup(['id' => 1, 'title' => 'Volumen', 'alias' => 'volume', 'filterable' => 1]);
        $this->insertPropertyOption(['id' => 11, 'pid' => 1, 'title' => '250 ml', 'alias' => '250ml']);
        $this->insertPropertyOption(['id' => 12, 'pid' => 1, 'title' => '500 ml', 'alias' => '500ml']);
        $this->insertPropertyGroup(['id' => 2, 'title' => 'Sorte', 'alias' => 'sorte', 'filterable' => 1]);
        $this->insertPropertyOption(['id' => 21, 'pid' => 2, 'title' => 'Bitter', 'alias' => 'bitter']);
        $this->insertProductProperty(['pid' => 1, 'optionId' => 11]);

        $this->landingPages = [
            new FacetPageRecord(1, 12, 'schwedenbitter-250-ml', 'volume:250ml', 'Schwedenbitter 250 ml', ''),
            new FacetPageRecord(2, 13, 'tee-500-ml', 'volume:500ml', 'Tee 500 ml', ''),
            new FacetPageRecord(3, 14, 'fremd', 'volume:1l', 'Fremd', ''),
        ];

        // Since B-10, ProductQuery reads the visibility projection.
        $this->rebuildProjection();
    }

    /**
     * Since C-1c the projection is keyed by currency and reads it from tl_page.
     * Whoever changes the root pages rebuilds it afterwards — with a fresh
     * ShopLanguages, because that keeps its answers in memory.
     */
    private function rebuildProjection(): void
    {
        (new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        ))->rebuild(self::NOW);
    }

    public function testAddsTheVisiblePricedProductsOfAStartPointWithAShop(): void
    {
        $event = $this->event([1]);

        $this->listener()($event);

        $addresses = $this->addresses($event);

        self::assertContains(self::HOST.'/produkt/schwedenbitter.html', $addresses);
        self::assertNotContains(self::HOST.'/produkt/ohne-preis.html', $addresses, 'Ohne Preis nicht in die Sitemap (E1), wie in der Liste.');
        self::assertNotContains(self::HOST.'/produkt/entwurf.html', $addresses);
        self::assertNotContains(self::HOST.'/produkt/variante.html', $addresses, 'Eine Variante hat keine eigene Adresse.');
    }

    public function testDieSitemapFolgtDerWaehrungDesStartpunktsNichtDerDesShops(): void
    {
        /*
         * Spec 4.0: the shop stays on EUR and in Austria, the root page
         * switches to GBP. That turns the base data around —
         * "schwedenbitter" only has a euro price and drops out, "ohne-preis"
         * gets its pound price here and comes in.
         *
         * The reversal is the point: if the listener took the shop's
         * currency again, exactly the old list would be there, and a mere
         * "is still in" would have said nothing. The country of the price
         * row stays AT — it still comes from the shop.
         */
        $this->currencyOfRootPage = 'GBP';
        $this->connection->update('tl_page', ['consho_currency' => 'GBP'], ['id' => 1]);
        $this->insertListPrice(['pid' => 2, 'currency' => 'GBP', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);
        $this->rebuildProjection();

        $event = $this->event([1]);

        $this->listener()($event);

        $addresses = $this->addresses($event);

        self::assertContains(self::HOST.'/produkt/ohne-preis.html', $addresses);
        self::assertNotContains(self::HOST.'/produkt/schwedenbitter.html', $addresses, 'Ein Euro-Preis zählt auf einem Pfund-Startpunkt nicht.');
    }

    public function testContaosOwnAddressesStayUntouched(): void
    {
        $event = $this->event([1]);

        $this->listener()($event);

        self::assertSame([self::HOST.'/', self::HOST.'/kraeuter.html'], \array_slice($this->addresses($event), 0, 2));
    }

    public function testAStartPointWithoutAShopAddsNothing(): void
    {
        // An installation with several websites: pages without Consho stay untouched.
        $event = $this->event([2]);

        $this->listener()($event);

        self::assertSame([self::HOST.'/', self::HOST.'/kraeuter.html'], $this->addresses($event));
    }

    public function testAStartPointWithoutAProductPageAddsNoProductsAndFailsNothing(): void
    {
        $this->hasProductPage = false;
        $event = $this->event([1]);

        $this->listener()($event);

        self::assertSame([], array_values(array_filter($this->addresses($event), static fn (string $a): bool => str_contains($a, '/produkt/'))));
    }

    public function testALandingPageIsAddedOnlyWhereContaoListedItsPage(): void
    {
        $event = $this->event([1, 2]);

        $this->listener()($event);

        $addresses = $this->addresses($event);

        self::assertContains(self::HOST.'/kraeuter/schwedenbitter-250-ml.html', $addresses);
        self::assertNotContains(self::HOST.'/tees/tee-500-ml.html', $addresses, 'Seite 13 steht nicht in Contaos Sitemap, ihre Landingpage auch nicht.');
        self::assertNotContains(self::HOST.'/fremd/fremd.html', $addresses, 'Die Listenseite gehört zu einem Startpunkt ohne Shop.');
    }

    public function testALandingPageWithoutHitsIsLeftOut(): void
    {
        // B-8: 500 ml has no product right now — the landing page answers 404.
        $this->landingPages[] = new FacetPageRecord(4, 12, 'tee-500-ml', 'volume:500ml', 'Tee 500 ml', '');
        $event = $this->event([1]);

        $this->listener()($event);

        self::assertNotContains(self::HOST.'/kraeuter/tee-500-ml.html', $this->addresses($event));
        self::assertContains(self::HOST.'/kraeuter/schwedenbitter-250-ml.html', $this->addresses($event));
    }

    public function testALandingPageWhoseOptionIsGoneIsLeftOut(): void
    {
        // Resolves only partly → 404 as in ListingVerdict, no broader list.
        $this->landingPages[] = new FacetPageRecord(5, 12, 'ein-liter', 'volume:1l', 'Ein Liter', '');
        $event = $this->event([1]);

        $this->listener()($event);

        self::assertNotContains(self::HOST.'/kraeuter/ein-liter.html', $this->addresses($event));
    }

    public function testAPinThatMatchesKeepsALandingPageOtherwiseEmpty(): void
    {
        // Product 1 does not carry the preset "bitter"; pinned and 250 ml, it still stands on top (B-7, rule 5).
        $this->connection->update('tl_content', ['conshoFilter' => serialize(['21'])], ['id' => 50]);

        $event = $this->event([1]);
        $this->listener()($event);
        self::assertNotContains(self::HOST.'/kraeuter/schwedenbitter-250-ml.html', $this->addresses($event));

        $this->connection->insert('tl_content', ['pid' => 50, 'ptable' => 'tl_content', 'type' => 'consho_product_pin', 'conshoProduct' => 1, 'tstamp' => 1]);

        $event = $this->event([1]);
        $this->listener()($event);
        self::assertContains(self::HOST.'/kraeuter/schwedenbitter-250-ml.html', $this->addresses($event));
    }

    public function testALandingPageOnAPageWithoutAListingIsLeftOut(): void
    {
        // Nobody reads the path segment — Contao answers with 404 itself.
        $this->connection->delete('tl_content', ['id' => 50]);
        $event = $this->event([1]);

        $this->listener()($event);

        self::assertNotContains(self::HOST.'/kraeuter/schwedenbitter-250-ml.html', $this->addresses($event));
    }

    public function testNoAddressIsAddedTwiceAndNoneCarriesTheFilterParameter(): void
    {
        $event = $this->event([1], [self::HOST.'/produkt/schwedenbitter.html']);

        $listener = $this->listener();
        $listener($event);
        $listener($event);

        $addresses = $this->addresses($event);

        self::assertSame($addresses, array_values(array_unique($addresses)));
        self::assertSame([], array_values(array_filter($addresses, static fn (string $a): bool => str_contains($a, 'cq='))));
    }

    private function product(int $id, string $alias, bool $priced = true, bool $published = true): void
    {
        $this->insertProduct(['id' => $id, 'pid' => 0, 'title' => ucfirst($alias), 'alias' => $alias, 'published' => $published ? 1 : 0]);
        $this->insertProductShop(['pid' => $id, 'shop' => 1]);

        if ($priced) {
            $this->insertListPrice(['pid' => $id, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);
        }
    }

    /**
     * @param list<int>    $rootPageIds
     * @param list<string> $alsoPresent further addresses already in the document
     */
    private function event(array $rootPageIds, array $alsoPresent = []): SitemapEvent
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $urlSet = $document->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');

        foreach ([self::HOST.'/', self::HOST.'/kraeuter.html', ...$alsoPresent] as $address) {
            $url = $document->createElementNS($urlSet->namespaceURI, 'url');
            $loc = $document->createElementNS($urlSet->namespaceURI, 'loc');
            $loc->appendChild($document->createTextNode($address));
            $url->appendChild($loc);
            $urlSet->appendChild($url);
        }

        $document->appendChild($urlSet);

        return new SitemapEvent($document, Request::create('/sitemap.xml'), $rootPageIds);
    }

    /**
     * @return list<string>
     */
    private function addresses(SitemapEvent $event): array
    {
        $addresses = [];

        foreach ($event->getDocument()->getElementsByTagNameNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'loc') as $loc) {
            $addresses[] = $loc->textContent;
        }

        return $addresses;
    }

    private function listener(): SitemapListener
    {
        $pages = [
            /*
             * The two root pages **without** `rootId`: the listener fetches them
             * via findById(), i.e. without walking the tree, and a page loaded
             * that way does not have the property. That they are their own root
             * page is said by `type` — the real column. A double that returned
             * `rootId` here would be more capable than its origin; exactly that is
             * how an earlier bug went unnoticed.
             *
             * Pages 12/13/14/123 come via findWithDetails() and rightly keep
             * their `rootId` — the listener really reads it there.
             */
            1 => $this->page(['id' => 1, 'type' => 'root', 'language' => 'de', 'alias' => 'index'], ['consho_shop' => 1, 'consho_currency' => $this->currencyOfRootPage]),
            2 => $this->page(['id' => 2, 'type' => 'root', 'language' => 'en', 'alias' => 'other'], ['consho_shop' => 0]),
            12 => $this->page(['id' => 12, 'rootId' => 1, 'alias' => 'kraeuter']),
            13 => $this->page(['id' => 13, 'rootId' => 1, 'alias' => 'tees']),
            14 => $this->page(['id' => 14, 'rootId' => 2, 'alias' => 'fremd']),
            123 => $this->page(['id' => 123, 'rootId' => 1, 'alias' => 'produkt'], ['conshoPathTemplate' => '{alias}']),
        ];

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(
            static fn (string $name, array $args): ?PageModel => \in_array($name, ['findById', 'findByPk', 'findWithDetails'], true) ? ($pages[$args[0]] ?? null) : null,
        );

        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => 1, 'currency' => 'EUR'][$key] ?? null);

        $shopAdapter = $this->createMock(Adapter::class);
        $shopAdapter->method('__call')->willReturnCallback(
            static fn (string $name, array $args): ?ShopModel => 'findByPk' === $name && 1 === $args[0] ? $shop : null,
        );

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => ShopModel::class === $class ? $shopAdapter : $pageAdapter,
        );

        // Like Contao's generator: the product page with {alias}, every other
        // page via its alias, the path segment of a landing page as "parameters".
        $contentUrls = $this->createMock(ContentUrlGenerator::class);
        $contentUrls->method('generate')->willReturnCallback(
            static function (object $content, array $values, int $referenceType): string {
                $pageAlias = $content instanceof PageModel ? (string) $content->alias : '';

                if ('produkt' === $pageAlias) {
                    $path = '/produkt/'.($values['alias'] ?? '').'.html';
                    unset($values['alias']);
                } else {
                    $path = '/'.$pageAlias.($values['parameters'] ?? '').'.html';
                    unset($values['parameters']);
                }

                if ([] !== $values) {
                    $path .= '?'.http_build_query($values);
                }

                return UrlGeneratorInterface::ABSOLUTE_URL === $referenceType ? self::HOST.$path : $path;
            },
        );

        $productPages = $this->createMock(ProductPageRepositoryInterface::class);
        $productPages->method('findForRootPage')->willReturnCallback(fn (int $rootId): ?int => $this->hasProductPage && 1 === $rootId ? 123 : null);
        $productPages->method('urlValues')->willReturnCallback(
            fn (int $id): array => ['alias' => (string) $this->connection->fetchOne('SELECT alias FROM tl_consho_product WHERE id = ?', [$id])],
        );

        $facetPages = $this->createMock(FacetPageRepositoryInterface::class);
        $facetPages->method('findAll')->willReturnCallback(fn (): array => $this->landingPages);
        $facetPages->method('findByState')->willReturnCallback(
            function (int $pageId, string $state): ?FacetPageRecord {
                foreach ($this->landingPages as $landing) {
                    if ($landing->pageId === $pageId && $landing->filterState === $state) {
                        return $landing;
                    }
                }

                return null;
            },
        );

        $codec = new FilterStateCodec();
        $shops = new ShopResolver($framework);
        $languages = new ShopLanguages($this->connection);
        $products = new ProductQuery($this->connection);

        return new SitemapListener(
            $framework,
            $shops,
            $languages,
            $products,
            new ProductUrlGenerator($contentUrls, $productPages, $framework, $shops, $languages),
            $facetPages,
            new FilterUrlGenerator($contentUrls, new FacetPageResolver($facetPages, $codec), $codec),
            $codec,
            $contentUrls,
            new FacetPageHits(new ListingPageRepository($this->connection), new FilterResolver($this->connection), $codec, $products, new DbalPinRepository($this->connection)),
            static fn (): int => self::NOW,
        );
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $row
     */
    private function page(array $properties, array $row = []): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnCallback(static fn (string $key): mixed => $properties[$key] ?? null);
        $page->method('row')->willReturn($row);

        return $page;
    }
}

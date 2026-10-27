<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContext;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\String\HtmlDecoder;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\FrontendUser;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalCatalogSchedule;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalPinRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalProductTileRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterResolver;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingPageRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\SortLinks;
use Webwerkwien\ContaoConshoBundle\Catalog\TilePrices;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\ProductListController;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRules;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionTerms;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageResolver;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Seo\FilterUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Seo\ListingAddressResolver;
use Webwerkwien\ContaoConshoBundle\Seo\ListingHead;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

require_once __DIR__.'/../../Notification/OrderConfirmationTest.php';

/**
 * Whom the product list calculates for (C-4).
 *
 * ⚠️ Why this test exists although getResponse() is, according to
 * ProductListControllerTest, checked in the browser: C-4 replaces a
 * hard-wired `$showsNet = false` in this method with a question to
 * PriceAudienceResolver. A test that feeds the resolver directly does not
 * check whether the controller asks it at all — and a check in the browser
 * covers one case, not four. A check can run and still leave the gaps
 * standing, and the error can sit in how the object is put together while
 * only the finished parts are checked.
 *
 * Hence only the audience question here, with the smallest setup that
 * produces a tile: one shop, one start point, one product with a price.
 */
class ProductListAudienceTest extends TestCase
{
    use CatalogSchema;

    private const SHOP = 1;
    private const PAGE = 123;
    private const ELEMENT = 42;
    private const PRODUCT = 1;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);

        $this->insertShop(['id' => self::SHOP, 'title' => 'Shop', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', self::SHOP);

        // For DbalPinRepository: the list asks for pinned products, even when
        // none is pinned.
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

        $this->connection->insert('tl_member_group', ['id' => 3, 'name' => 'Händler']);
        $this->connection->insert('tl_member_group', ['id' => 4, 'name' => 'Newsletter']);

        $this->insertProduct(['id' => self::PRODUCT, 'title' => 'Schwedenbitter', 'alias' => 'schwedenbitter', 'published' => 1]);
        $this->insertProductShop(['pid' => self::PRODUCT, 'shop' => self::SHOP]);

        // 12.00 gross = 10.00 net at 20 %. Both columns are in the projection,
        // since C-1a, exactly for this case.
        $this->insertListPrice(['pid' => self::PRODUCT, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);

        // The visibility projection is built by the real projector, not by this
        // test: a hand-written row would in the end check the test's own copy of
        // the rules instead of the rules.
        (new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        ))->rebuild(1_000_000);
    }

    public function testEinGastSiehtBruttoUndKeinenHinweis(): void
    {
        $this->netPriceGroups([3]);

        $data = $this->render();

        self::assertFalse($data['showsNet']);
        self::assertSame('', $data['ownPriceGroup']);

        // The RAW value of the tile, not the formatted one: it says which column
        // of the projection was read — and that is exactly the question.
        self::assertSame('12', $data['products'][0]->price);
    }

    public function testEinMitgliedDerNettogruppeSiehtNetto(): void
    {
        // The wiring this is about: the controller asks the resolver, and the
        // answer reaches both the tile and the statement for the template.
        $this->netPriceGroups([3]);

        $data = $this->render([3]);

        self::assertTrue($data['showsNet']);
        self::assertSame('10', $data['products'][0]->price);
    }

    public function testEinMitgliedAusserhalbDerNettogruppeSiehtBrutto(): void
    {
        $this->netPriceGroups([3]);

        $data = $this->render([4]);

        self::assertFalse($data['showsNet']);
        self::assertSame('12', $data['products'][0]->price);
    }

    public function testOhneAuswahlAmShopSiehtAuchEinHaendlerBrutto(): void
    {
        // The freshly created shop. A default "logged-in users see net" would be
        // green here and wrong.
        $this->netPriceGroups([]);

        self::assertFalse($this->render([3])['showsNet']);
    }

    public function testDerHinweisErscheintNurFuerEineGruppeMitPreisen(): void
    {
        // E1: the tile shows the list price because the projection has no group
        // dimension. The notice says where one's own price is — and only to
        // whoever has one.
        $this->netPriceGroups([]);
        $this->insertPrice(['pid' => self::PRODUCT, 'net' => '8.0000', 'currency' => 'EUR', 'customerGroup' => 3]);

        self::assertSame('Händler', $this->render([3])['ownPriceGroup']);
        self::assertSame('', $this->render([4])['ownPriceGroup']);

        // And never to a guest: a group comes from the operator, not from
        // logging in — "as a customer you pay less" would be a promise to almost
        // every guest that the shop does not keep.
        self::assertSame('', $this->render()['ownPriceGroup']);
    }

    public function testDerGruppenpreisAendertDieKachelNicht(): void
    {
        // The decision from Block A 3.6, recorded here: the tile reads the
        // projection, and that knows only the list price. Otherwise this would
        // say 8.00 — and the sorting next to it would be a different one.
        $this->netPriceGroups([3]);
        $this->insertPrice(['pid' => self::PRODUCT, 'net' => '8.0000', 'currency' => 'EUR', 'customerGroup' => 3]);

        self::assertSame('10', $this->render([3])['products'][0]->price);
    }

    /**
     * @param list<int> $groups
     */
    private function netPriceGroups(array $groups): void
    {
        $this->connection->update(
            'tl_consho_shop',
            ['netPriceGroups' => [] === $groups ? null : serialize(array_map('strval', $groups))],
            ['id' => self::SHOP],
        );
    }

    /**
     * @param list<int>|null $memberGroups null = nobody logged in
     *
     * @return array<string, mixed>
     */
    private function render(array|null $memberGroups = null): array
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => self::PAGE, 'type' => 'regular', 'rootId' => 1, 'language' => 'de'][$key] ?? null);

        /*
         * ⚠️ __isset, not just __get. The controller reads `$page->language ?? ''`,
         * and for magic properties the null coalescing operator asks __isset()
         * FIRST; a double that says false there returns '' and never calls
         * __get. The list then searched in the language "" and found nothing —
         * visible only in that the tiles were missing.
         */
        $page->method('__isset')->willReturn(true);

        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => 1, 'type' => 'root', 'rootId' => 1, 'language' => 'de'][$key] ?? null);
        $root->method('row')->willReturn(['consho_shop' => self::SHOP, 'consho_currency' => 'EUR']);

        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => self::SHOP, 'currency' => 'EUR', 'country' => 'AT'][$key] ?? null);

        $pages = [self::PAGE => $page, 1 => $root];

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(
            static fn (string $name, array $args): ?PageModel => \in_array($name, ['findById', 'findByPk'], true) ? ($pages[$args[0]] ?? null) : null,
        );

        $shopAdapter = $this->createMock(Adapter::class);
        $shopAdapter->method('__call')->willReturnCallback(
            static fn (string $name, array $args): ?ShopModel => 'findByPk' === $name && self::SHOP === $args[0] ? $shop : null,
        );

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => PageModel::class === $class ? $pageAdapter : $shopAdapter,
        );

        $insertTags = $this->createMock(InsertTagParser::class);
        $insertTags->method('replaceInline')->willReturnArgument(0);

        $request = Request::create('https://example.org/katalog.html');
        $request->attributes->set('pageModel', $page);

        $context = new ResponseContext();
        $context->add((new HtmlHeadBag())->setTitle('Katalog'));
        $request->attributes->set(ResponseContext::REQUEST_ATTRIBUTE_NAME, $context);

        $stack = new \Symfony\Component\HttpFoundation\RequestStack();
        $stack->push($request);

        $codec = new FilterStateCodec();
        $facetPages = $this->createMock(FacetPageRepositoryInterface::class);
        $facets = new FacetPageResolver($facetPages, $codec);

        $contentUrls = $this->createMock(ContentUrlGenerator::class);
        $contentUrls->method('generate')->willReturn('/katalog.html');

        $filterUrls = new FilterUrlGenerator($contentUrls, $facets, $codec);

        $productPages = $this->createMock(ProductPageRepositoryInterface::class);
        $productPages->method('findForRootPage')->willReturn(null);

        $shops = new ShopResolver($framework);
        $languages = new ShopLanguages($this->connection);

        $controller = new class(
            $shops,
            new FilterResolver($this->connection),
            new ProductQuery($this->connection),
            new DbalProductTileRepository($this->connection),
            $languages,
            new ListingAddressResolver($framework, $codec, $facetPages),
            new ListingHead(new ResponseContextAccessor($stack), $facets, $filterUrls, new HtmlDecoder($insertTags)),
            new ListingPageRepository($this->connection),
            new SortLinks($filterUrls),
            new ProductUrlGenerator($contentUrls, $productPages, $framework, $shops, $languages),
            new TilePrices(new MoneyFormatter(), new SubscriptionRules($this->connection), new SubscriptionTerms(new ContaoLikeTranslator())),
            new DbalPinRepository($this->connection),
            $this->createMock(TokenChecker::class),
            new PriceAudienceResolver($this->tokenStorage($memberGroups), $this->connection),
            new DbalCatalogSchedule($this->connection),
        ) extends ProductListController {
            public function respond(FragmentTemplate $template, ContentModel $model, Request $request): Response
            {
                return $this->getResponse($template, $model, $request);
            }
        };

        $row = ['id' => self::ELEMENT, 'conshoPerPage' => 0, 'conshoTileSize' => '', 'conshoTileTemplate' => '', 'conshoShowCounts' => '', 'conshoPinsOnly' => '', 'conshoSort' => '', 'conshoFilter' => '', 'conshoFollowsAddress' => ''];

        $model = $this->createMock(ContentModel::class);
        $model->method('row')->willReturn($row);
        $model->method('__get')->willReturnCallback(static fn (string $key): mixed => $row[$key] ?? null);

        $container = new Container();
        $container->set('contao.cache.tag_manager', $this->createMock(CacheTagManager::class));
        $controller->setContainer($container);

        $template = new FragmentTemplate('content_element/consho_product_list', static fn (): Response => new Response());
        $controller->respond($template, $model, $request);

        return $template->getData();
    }

    /**
     * A real FrontendUser without the framework, as in CartResolverTest: the
     * resolver decides on `instanceof FrontendUser`.
     *
     * @param list<int>|null $groups
     */
    private function tokenStorage(array|null $groups): TokenStorageInterface
    {
        $storage = new TokenStorage();

        if (null === $groups) {
            return $storage;
        }

        $user = (new \ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
        $user->id = 1;
        $user->username = 'member1';
        $user->groups = serialize(array_map('strval', $groups));

        $storage->setToken(new UsernamePasswordToken($user, 'frontend'));

        return $storage;
    }
}

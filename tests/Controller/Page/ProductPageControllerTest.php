<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Page;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Controller\Page\ProductPageController;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Seo\ProductAddressLookup;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The product page looks up its address in the language of its start point.
 *
 * Against a real SQLite database: ProductAddressLookup and ShopLanguages
 * are final, and the question is which rows the query finds anyway.
 * renderPage() is replaced by an anonymous subclass — nothing is rendered
 * here; what is checked is whether the page WOULD be rendered, and with
 * which product.
 *
 * M-3 had made the address lookup language-aware but had not switched this
 * caller over (B-5).
 */
class ProductPageControllerTest extends TestCase
{
    private const SHOP = 1;
    private const ROOT_PAGE = 5;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Columns as in the real DB, built from the DCA: start/stop
        // varchar with '', the safety fields longtext NULL.
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');
        DcaSchema::create($this->connection, 'tl_consho_manufacturer');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        DcaSchema::create($this->connection, 'tl_consho_translation');
        DcaSchema::createCore($this->connection, 'tl_page');
        DcaSchema::create($this->connection, 'tl_consho_shop');

        $this->connection->insert('tl_consho_shop', ['id' => self::SHOP, 'fallbackLanguage' => 'en']);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'language' => 'en', 'consho_shop' => self::SHOP]);
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'language' => 'fr', 'consho_shop' => self::SHOP]);

        $this->connection->insert('tl_consho_product', ['id' => 1, 'alias' => 'schwedenbitter', 'published' => 1]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 1, 'shop' => self::SHOP]);
    }

    public function testInTheDefaultLanguageTheProductsOwnAliasFindsIt(): void
    {
        $request = $this->request('schwedenbitter');

        self::assertSame('rendered', $this->controller()($this->page('en'), $request)->getContent());
        self::assertSame(1, $request->attributes->get(ProductPageController::PRODUCT_ATTRIBUTE));
    }

    public function testInAnotherLanguageTheTranslatedAliasFindsIt(): void
    {
        $this->translate('fr', 'amer-suedois');

        self::assertSame('rendered', $this->controller()($this->page('fr'), $this->request('amer-suedois'))->getContent());
    }

    public function testInAnotherLanguageTheDefaultAliasIsNotAnAddress(): void
    {
        // Otherwise every product would be reachable under two addresses.
        $this->translate('fr', 'amer-suedois');

        $this->expectException(PageNotFoundException::class);

        $this->controller()($this->page('fr'), $this->request('schwedenbitter'));
    }

    public function testWithoutATranslationTheProductDoesNotExistInThatLanguage(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->controller()($this->page('fr'), $this->request('schwedenbitter'));
    }

    public function testUntranslatedSafetyNoticesHideTheProductInThatLanguage(): void
    {
        // The GPSR rule: warnings must be available in the language of the market
        // and do not fall back to the default language.
        $this->connection->update('tl_consho_product', ['safetyWarnings' => 'Enthält Alkohol'], ['id' => 1]);
        $this->translate('fr', 'amer-suedois');

        $this->expectException(PageNotFoundException::class);

        $this->controller()($this->page('fr'), $this->request('amer-suedois'));
    }

    public function testTranslatedSafetyNoticesMakeItReachableAgain(): void
    {
        // The counter-check to the test before: only with it is it proven that
        // there the missing warnings trigger the 404 and not, say, the alias.
        $this->connection->update('tl_consho_product', ['safetyWarnings' => 'Enthält Alkohol'], ['id' => 1]);
        $this->translate('fr', 'amer-suedois', 'Contient de l\'alcool');

        self::assertSame('rendered', $this->controller()($this->page('fr'), $this->request('amer-suedois'))->getContent());
    }

    private function translate(string $language, string $alias, ?string $safetyWarnings = null): void
    {
        $this->connection->insert('tl_consho_translation', [
            'ptable' => 'tl_consho_product',
            'pid' => 1,
            'language' => $language,
            'alias' => $alias,
            'safetyWarnings' => $safetyWarnings,
        ]);
    }

    private function request(string $alias): Request
    {
        $request = Request::create('/produkt/'.$alias.'.html');
        $request->attributes->set('alias', $alias);

        return $request;
    }

    /**
     * The product page below a start point. After loadDetails() its `language`
     * is the start point's — which is what the mock hands out.
     *
     * It is a `regular` page and carries no `consho_shop`: the shop hangs off
     * the start point, and the double used to short-circuit that by claiming
     * `rootId === id`. ShopResolver now turns on `type`, so the shortcut would
     * have to call this page a start point to keep working — which it is not.
     */
    private function page(string $language): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', 10],
            ['type', 'regular'],
            ['rootId', self::ROOT_PAGE],
            ['language', $language],
        ]);
        $page->method('row')->willReturn(['id' => 10]);

        return $page;
    }

    /**
     * The start point above it, where `consho_shop` actually lives.
     */
    private function rootPage(): PageModel
    {
        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturnMap([
            ['id', self::ROOT_PAGE],
            ['type', 'root'],
            ['rootId', self::ROOT_PAGE],
        ]);
        $root->method('row')->willReturn(['id' => self::ROOT_PAGE, 'consho_shop' => self::SHOP]);

        return $root;
    }

    private function controller(): ProductPageController
    {
        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnMap([['id', self::SHOP]]);

        $shopAdapter = $this->createMock(Adapter::class);
        $shopAdapter->method('__call')->with('findByPk', [self::SHOP])->willReturn($shop);

        $root = $this->rootPage();

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(
            /**
             * @param list<mixed> $arguments
             */
            static fn (string $method, array $arguments): PageModel|null => self::ROOT_PAGE === (int) ($arguments[0] ?? 0) ? $root : null,
        );

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnMap([
            [PageModel::class, $pageAdapter],
            [ShopModel::class, $shopAdapter],
        ]);

        return new class(new ShopResolver($framework), new ProductAddressLookup($this->connection, new VisibilityCriteria()), new ShopLanguages($this->connection)) extends ProductPageController {
            protected function renderPage(PageModel $pageModel): Response
            {
                return new Response('rendered');
            }
        };
    }
}

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
 * Die Produktseite sucht ihre Adresse in der Sprache ihres Startpunkts.
 *
 * Gegen eine echte SQLite-Datenbank: ProductAddressLookup und ShopLanguages
 * sind final, und die Frage ist ohnehin, welche Zeilen die Abfrage findet.
 * renderPage() ersetzt eine anonyme Unterklasse — gerendert wird hier nichts,
 * geprüft wird, ob die Seite gerendert WÜRDE und mit welchem Produkt.
 *
 * Gefunden in B-5: M-3 hatte die Adresssuche sprachfähig gemacht, aber diesen
 * Aufrufer nicht umgestellt.
 */
class ProductPageControllerTest extends TestCase
{
    private const SHOP = 1;
    private const ROOT_PAGE = 5;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Spalten wie in der echten DB, aus der DCA gebaut: start/stop
        // varchar mit '', die Sicherheitsfelder longtext NULL.
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');
        DcaSchema::create($this->connection, 'tl_consho_manufacturer');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        DcaSchema::create($this->connection, 'tl_consho_translation');
        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY, type TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0)",
        );
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
        // Sonst wäre jedes Produkt unter zwei Adressen erreichbar.
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
        // Die GPSR-Regel: Warnhinweise müssen in der Sprache des Marktes
        // vorliegen und fallen nicht auf die Standardsprache zurück.
        $this->connection->update('tl_consho_product', ['safetyWarnings' => 'Enthält Alkohol'], ['id' => 1]);
        $this->translate('fr', 'amer-suedois');

        $this->expectException(PageNotFoundException::class);

        $this->controller()($this->page('fr'), $this->request('amer-suedois'));
    }

    public function testTranslatedSafetyNoticesMakeItReachableAgain(): void
    {
        // Die Gegenprobe zum Test davor: Erst mit ihr ist belegt, dass dort die
        // fehlenden Hinweise die 404 auslösen und nicht etwa der Alias.
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\FrontendUser;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webwerkwien\ContaoConshoBundle\Confirmation\ConfirmationPurpose;
use Webwerkwien\ContaoConshoBundle\Controller\StockNoticeController;
use Webwerkwien\ContaoConshoBundle\Customer\GuestOrderConfirmation;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockNotices;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Block G, G-6 (G12, G15): the route of the three buttons — sign up, sign
 * off, confirm. The member comes from the session, never from the form;
 * origin checked as for the cart.
 *
 * Hiking pole (1) with variants 2 and 3; variant 3 is sold out.
 */
class StockNoticeControllerTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        // The catalogue: a variant's address asks the shop's languages.
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_stock_notice');
        $this->insertShop(['id' => 1, 'title' => 'Shop', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(7, 'de', 1);

        $this->connection->insert('tl_consho_product', ['id' => 1, 'title' => 'Wanderstock', 'stockTracked' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'pid' => 1]);
        (new StockRows($this->connection))->sync(1, 100);
        $this->connection->update('tl_consho_stock', ['quantity' => '4.0000'], ['article' => 2]);

        // Both variants are shown at this start point (K5).
        foreach ([2, 3] as $variant) {
            $this->connection->insert('tl_consho_product_visibility', ['pid' => $variant, 'main' => 1, 'variant' => 1, 'shop' => 1, 'language' => 'de', 'currency' => 'EUR']);
        }
    }

    public function testOhneHerkunftKeineAntwort(): void
    {
        self::assertSame(Response::HTTP_FORBIDDEN, $this->controller()($this->post(['article' => '3'], origin: null))->getStatusCode());
    }

    public function testOhneAnmeldungNurDerHinweis(): void
    {
        $response = $this->controller()($this->post(['article' => '3', 'main' => '1']));

        self::assertSame('https://shop.test/produkt/wanderstock.html?variant=3&consho_stock_notice=login', $response->headers->get('Location'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_notice'));
    }

    public function testEintragenUndAbmelden(): void
    {
        $response = $this->controller(5)($this->post(['article' => '3', 'main' => '1', 'member' => '99']));

        self::assertStringEndsWith('consho_stock_notice=subscribed', (string) $response->headers->get('Location'));
        self::assertSame(
            [3, 1, 5, 1, 7, 'de'],
            array_values(array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, (array) $this->connection->fetchAssociative('SELECT article, main, member, shop, root, language FROM tl_consho_stock_notice'))),
            'das Mitglied aus der Sitzung, nicht aus dem Formular',
        );

        $response = $this->controller(5)($this->post(['article' => '3', 'main' => '1', 'consho_action' => 'unsubscribe']));

        self::assertStringEndsWith('consho_stock_notice=unsubscribed', (string) $response->headers->get('Location'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_notice'));
    }

    public function testLieferbarWirdNichtsEingetragen(): void
    {
        $response = $this->controller(5)($this->post(['article' => '2', 'main' => '1']));

        self::assertStringEndsWith('consho_stock_notice=available', (string) $response->headers->get('Location'));
    }

    public function testBestaetigenMitDemZweckDerBenachrichtigung(): void
    {
        $confirmations = $this->createMock(GuestOrderConfirmation::class);
        $confirmations->expects(self::once())->method('confirm')
            ->with('cosn-abc', 5, 'kundin@example.org', self::anything(), ConfirmationPurpose::StockNotice)
            ->willReturn('confirmed');

        $response = $this->controller(5, $confirmations)($this->post(['article' => '3', 'main' => '1', 'consho_action' => 'confirm', 'token' => 'cosn-abc']));

        self::assertStringEndsWith('consho_stock_notice=confirmed', (string) $response->headers->get('Location'));
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(array $fields, string|null $origin = 'https://shop.test'): Request
    {
        $request = Request::create('https://shop.test/_consho/stock/notice', 'POST', ['page' => '7', ...$fields]);

        if (null !== $origin) {
            $request->headers->set('Origin', $origin);
        }

        return $request;
    }

    private function controller(int|null $memberId = null, GuestOrderConfirmation|null $confirmations = null): StockNoticeController
    {
        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => 7, 'type' => 'root', 'rootId' => 7, 'language' => 'de'][$key] ?? null);
        $root->method('row')->willReturn(['consho_shop' => 1]);

        $productPage = $this->createMock(PageModel::class);
        $productPage->method('row')->willReturn(['conshoPathTemplate' => '{alias}']);

        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => 1][$key] ?? null);

        $pages = $this->createMock(Adapter::class);
        $pages->method('__call')->willReturnCallback(static fn (string $name, array $args): PageModel|null => match ($args[0] ?? null) {
            7 => $root,
            123 => $productPage,
            default => null,
        });

        $shops = $this->createMock(Adapter::class);
        $shops->method('__call')->willReturnCallback(static fn (string $name, array $args): ShopModel|null => 1 === ($args[0] ?? null) ? $shop : null);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(static fn (string $class): Adapter => ShopModel::class === $class ? $shops : $pages);

        $contentUrls = $this->createMock(ContentUrlGenerator::class);
        $contentUrls->method('generate')->willReturnCallback(
            static function (object $content, array $values): string {
                $alias = $values['alias'] ?? '';
                unset($values['alias']);

                return 'https://shop.test/produkt/'.$alias.'.html'.([] === $values ? '' : '?'.http_build_query($values));
            },
        );

        $productPages = $this->createMock(ProductPageRepositoryInterface::class);
        $productPages->method('findForRootPage')->willReturn(123);
        $productPages->method('urlValues')->willReturn(['alias' => 'wanderstock']);

        $user = null;

        if (null !== $memberId) {
            $user = $this->createMock(FrontendUser::class);
            $user->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => $memberId, 'email' => 'kundin@example.org'][$key] ?? null);
        }

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $resolver = new ShopResolver($framework);

        return new StockNoticeController(
            $framework,
            $resolver,
            $security,
            new StockNotices($this->connection, new Availability($this->connection)),
            $confirmations ?? $this->createStub(GuestOrderConfirmation::class),
            new ProductUrlGenerator($contentUrls, $productPages, $framework, $resolver, new ShopLanguages($this->connection)),
        );
    }
}

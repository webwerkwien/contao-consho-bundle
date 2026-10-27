<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shop;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

class ShopResolverTest extends TestCase
{
    public function testResolvesAShopById(): void
    {
        $shop = $this->createMock(ShopModel::class);
        $framework = $this->frameworkFinding(ShopModel::class, 'findByPk', [7], $shop);

        $resolver = new ShopResolver($framework);

        self::assertSame($shop, $resolver->resolveById(7));
    }

    public function testReturnsNullWithoutQueryingWhenNoShopIsAssigned(): void
    {
        // A Contao installation without a shop must not query for one on every
        // request. The guard sits before the framework, not after it.
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects(self::never())->method('getAdapter');

        $resolver = new ShopResolver($framework);

        self::assertNull($resolver->resolveById(0));
        self::assertNull($resolver->resolveById(-1));
    }

    public function testReturnsNullWhenTheShopIdPointsNowhere(): void
    {
        $framework = $this->frameworkFinding(ShopModel::class, 'findByPk', [99], null);

        $resolver = new ShopResolver($framework);

        self::assertNull($resolver->resolveById(99));
    }

    public function testResolvesARootPageThroughItsOwnField(): void
    {
        $shop = $this->createMock(ShopModel::class);
        $framework = $this->frameworkFinding(ShopModel::class, 'findByPk', [7], $shop);

        $page = $this->page(id: 5, rootId: 5, shopId: 7);

        $resolver = new ShopResolver($framework);

        self::assertSame($shop, $resolver->resolveForPage($page));
    }

    public function testResolvesASubpageThroughItsRootPage(): void
    {
        $shop = $this->createMock(ShopModel::class);
        $root = $this->page(id: 5, rootId: 5, shopId: 7);

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->with('findByPk', [5])->willReturn($root);

        $shopAdapter = $this->createMock(Adapter::class);
        $shopAdapter->method('__call')->with('findByPk', [7])->willReturn($shop);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnMap([
            [PageModel::class, $pageAdapter],
            [ShopModel::class, $shopAdapter],
        ]);

        $resolver = new ShopResolver($framework);

        self::assertSame($shop, $resolver->resolveForPage($this->subpage(id: 12, rootId: 5)));
    }

    public function testEineNichtAufbereiteteUnterseiteHatKeinenStartpunktStattSelbstEinerZuSein(): void
    {
        /*
         * 🔴 A bug at its root. Here stood `(int) $page->rootId ?: $pageId`
         * — "no rootId? then you are the start point yourself". That holds for
         * a page of type `root` and for no other. A subpage from findById()
         * therefore got itself back, carried no `consho_shop`, and "add to
         * cart" answered with 400 from every subpage.
         *
         * Not to be confused with the case "start point deleted": there the
         * rootId is known and the record is gone (see the test below). Here
         * the rootId itself is unknown — and the only honest answer to that is
         * "I don't know", not "it's me".
         */
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects(self::never())->method('getAdapter');

        $resolver = new ShopResolver($framework);

        self::assertNull($resolver->rootOf($this->unwalkedSubpage(id: 12, rootId: 5)));
    }

    public function testReturnsNullWhenTheRootPageIsGone(): void
    {
        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturn(null);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pageAdapter);

        $resolver = new ShopResolver($framework);

        self::assertNull($resolver->resolveForPage($this->page(id: 12, rootId: 5, shopId: 0)));
    }

    public function testDieWaehrungKommtVomStartpunktUndNichtVomShop(): void
    {
        // Spec 4.0: The currency belongs to the start point, the home country to the shop.
        // An Austrian shop selling in pounds via /en/ is the market pair
        // (GBP, AT) — and this test checks exactly that split: the shop
        // explicitly carries a different currency here.
        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnMap([['id', 7], ['currency', 'EUR'], ['country', 'AT']]);

        $framework = $this->frameworkFinding(ShopModel::class, 'findByPk', [7], $shop);

        $page = $this->page(id: 5, rootId: 5, shopId: 7, currency: 'GBP');

        $resolver = new ShopResolver($framework);

        self::assertSame('GBP', $resolver->currencyOfPage($page));
    }

    public function testEineUnterseiteErbtDieWaehrungIhresStartpunkts(): void
    {
        // `consho_currency` is only on the start point. The subpage from
        // which the purchase is made carries the column empty — whoever reads
        // it there reads nothing.
        $root = $this->page(id: 5, rootId: 5, shopId: 7, currency: 'GBP');

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->with('findByPk', [5])->willReturn($root);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pageAdapter);

        $resolver = new ShopResolver($framework);

        self::assertSame('GBP', $resolver->currencyOfPage($this->subpage(id: 12, rootId: 5)));
    }

    public function testDerBaumWirdHochgelaufenBevorNachDemStartpunktGefragtWird(): void
    {
        /*
         * ⚠️ The same bug in its own check: `rootId` is not a column of
         * tl_page but a property that PageModel sets while walking up the
         * tree. A page from findById() does not have it — rootOf() would then
         * return the subpage itself, and that carries no currency.
         *
         * The double below therefore only hands out `rootId` after
         * loadDetails(). Without the call in currencyOfPage(), '' is left here.
         */
        $root = $this->page(id: 5, rootId: 5, shopId: 7, currency: 'GBP');

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->with('findByPk', [5])->willReturn($root);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pageAdapter);

        $resolver = new ShopResolver($framework);

        self::assertSame('GBP', $resolver->currencyOfPage($this->unwalkedSubpage(id: 12, rootId: 5)));
    }

    public function testOhneGewaehlteWaehrungKommtEineLeereZeichenketteZurueck(): void
    {
        // No euro as a last resort: a start point without a currency should
        // stay visible as such. Whoever calculates with it finds no price —
        // that is the intended consequence, not a price in a currency nobody
        // selected.
        $framework = $this->createMock(ContaoFramework::class);

        $resolver = new ShopResolver($framework);

        self::assertSame('', $resolver->currencyOfPage($this->page(id: 5, rootId: 5, shopId: 7, currency: '')));
    }

    public function testEinStartpunktOhneDieSpalteIstKeinEuroStartpunkt(): void
    {
        /*
         * The column is missing from the row, not just its value: the state
         * between deploying new code and `contao:migrate`, and the same case
         * against which RootCurrencyMigration sets up its own checks.
         *
         * Measured: without this test a `?? 'EUR'` at this point survives the
         * whole suite — and with it a shop that labels pound prices in euros
         * during an update.
         */
        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturnMap([['id', 5], ['type', 'root'], ['rootId', 5]]);
        $root->method('row')->willReturn(['id' => 5, 'consho_shop' => 7]);

        $resolver = new ShopResolver($this->createMock(ContaoFramework::class));

        self::assertSame('', $resolver->currencyOfPage($root));
    }

    public function testEinGeloeschterStartpunktLaesstDieWaehrungLeerImBackendUndAufDerKommandozeile(): void
    {
        /*
         * ⚠️ The name deliberately says where this branch occurs. It does not
         * occur in the front end: there `loadDetails()` throws
         * `NoRootPageFoundException` for a page under no start point
         * (PageModel.php:1087) before currencyOfPage() could return anything.
         * The double below has `loadDetails()` as a no-op and thereby models
         * the back-end and CLI path — there Contao checks the request context
         * and does not throw.
         *
         * So the line stays correct, but it does not prove what a visitor
         * sees.
         */
        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturn(null);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pageAdapter);

        $resolver = new ShopResolver($framework);

        self::assertSame('', $resolver->currencyOfPage($this->subpage(id: 12, rootId: 5)));
    }

    /**
     * A start point. `type` is the real column by which ShopResolver
     * recognises it; `rootId` stands next to it because a prepared start point
     * carries both.
     */
    private function page(int $id, int $rootId, int $shopId, string $currency = ''): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', $id],
            ['type', 'root'],
            ['rootId', $rootId],
        ]);

        // consho_shop and consho_currency come from Consho's own DCA and are
        // therefore read from the row array, not as declared properties — see
        // ShopResolver.
        $page->method('row')->willReturn(['id' => $id, 'consho_shop' => $shopId, 'consho_currency' => $currency]);

        return $page;
    }

    /**
     * A subpage as the front-end index passes it on: loadDetails() has run,
     * `rootId` is set.
     *
     * Its row carries **no** `consho_currency` — the column is only
     * maintained on the start point. A double that handed it out here unasked
     * would be more capable than the original and would make every check of
     * the tree walk blind.
     */
    private function subpage(int $id, int $rootId): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([['id', $id], ['type', 'regular'], ['rootId', $rootId]]);
        $page->method('row')->willReturn(['id' => $id, 'consho_shop' => 0]);

        return $page;
    }

    /**
     * The same subpage, but fresh from findById(): it has not yet walked up
     * the tree and only hands out `rootId` after loadDetails() — exactly like
     * the real PageModel.
     *
     * A real closure with `&$detailsLoaded`, not an arrow function: that only
     * binds copies, and the flag would stay false for ever. The model is the
     * repaired version in AddToCartControllerTest.
     */
    private function unwalkedSubpage(int $id, int $rootId): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $detailsLoaded = false;

        $page->method('loadDetails')->willReturnCallback(
            static function () use (&$detailsLoaded): null {
                $detailsLoaded = true;

                return null;
            },
        );

        $page->method('__get')->willReturnCallback(
            static function (string $key) use ($id, $rootId, &$detailsLoaded): int|string|null {
                return match ($key) {
                    'id' => $id,
                    // `type` is in the row and holds even without a tree walk —
                    // `rootId` only afterwards. Exactly this difference is the
                    // reason ShopResolver checks `type`.
                    'type' => 'regular',
                    'rootId' => $detailsLoaded ? $rootId : null,
                    default => null,
                };
            },
        );
        $page->method('row')->willReturn(['id' => $id, 'consho_shop' => 0]);

        return $page;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function frameworkFinding(string $class, string $method, array $arguments, ?object $result): ContaoFramework
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('__call')->with($method, $arguments)->willReturn($result);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->with($class)->willReturn($adapter);

        return $framework;
    }
}

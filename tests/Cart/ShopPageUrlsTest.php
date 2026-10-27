<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

/**
 * The one place that reads the page fields on the root page.
 *
 * Checked above all are the paths on which there is **no** address: the
 * "add to cart" route and the mini cart fall back on it, and an
 * exception instead of a `null` would take down the page the visitor
 * is looking at.
 */
class ShopPageUrlsTest extends TestCase
{
    private const ROOT = 5;

    private const CART_PAGE = 8;

    public function testDieAdresseDerEingestelltenWarenkorbseite(): void
    {
        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturn('https://example.com/warenkorb.html');

        $cartPage = $this->cartPageUrl($this->framework($this->createMock(PageModel::class)), $urls);

        self::assertSame('https://example.com/warenkorb.html', $cartPage->ofRoot($this->root(self::CART_PAGE)));
    }

    public function testOhneEingestellteSeiteGibtEsKeineAdresse(): void
    {
        // And the database is not even asked: an installation without a
        // cart page must not search for it on every request.
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects(self::never())->method('getAdapter');

        $cartPage = $this->cartPageUrl($framework, $this->createMock(ContentUrlGenerator::class));

        self::assertNull($cartPage->ofRoot($this->root(0)));
    }

    public function testEineGeloeschteWarenkorbseiteGibtKeineAdresse(): void
    {
        $cartPage = $this->cartPageUrl($this->framework(null), $this->createMock(ContentUrlGenerator::class));

        self::assertNull($cartPage->ofRoot($this->root(self::CART_PAGE)));
    }

    public function testEineSeiteOhneRouteGibtKeineAdresse(): void
    {
        // ContentUrlGenerator throws for a page without a route — an
        // unpublished cart page, a page in a tree without a
        // root page. That must not take the calling page down with it.
        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willThrowException(new RouteNotFoundException());

        $cartPage = $this->cartPageUrl($this->framework($this->createMock(PageModel::class)), $urls);

        self::assertNull($cartPage->ofRoot($this->root(self::CART_PAGE)));
    }

    public function testEineUnterseiteFindetDieWarenkorbseiteUeberIhrenStartpunkt(): void
    {
        $root = $this->root(self::CART_PAGE);

        $pages = $this->createMock(Adapter::class);
        $pages->method('__call')->willReturnCallback(
            /**
             * @param list<mixed> $arguments
             */
            fn (string $method, array $arguments): PageModel|null => match (true) {
                'findByPk' === $method && self::ROOT === $arguments[0] => $root,
                'findById' === $method && self::CART_PAGE === $arguments[0] => $this->createMock(PageModel::class),
                default => null,
            },
        );

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pages);

        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturn('https://example.com/warenkorb.html');

        $subpage = $this->createMock(PageModel::class);
        $subpage->method('__get')->willReturnMap([['id', 12], ['rootId', self::ROOT]]);
        $subpage->method('row')->willReturn(['id' => 12]);

        self::assertSame('https://example.com/warenkorb.html', $this->cartPageUrl($framework, $urls)->ofPage($subpage));
    }

    public function testDieCheckoutSeiteWirdAusDemselbenStartpunktGelesen(): void
    {
        // The same rule, another field — the reason why the class is no
        // longer called CartPageUrl.
        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturn('https://example.com/kasse.html');

        self::assertSame(
            'https://example.com/kasse.html',
            $this->cartPageUrl($this->frameworkWithRoot(), $urls)->checkoutOfPage($this->subpage()),
        );
    }

    public function testOhneCheckoutSeiteKommtNullUndKeinFehler(): void
    {
        // ⚠️ Since C-1c rootOf() answers `null` for a root page that
        // asks about itself — which is why this test, too, goes through a
        // subpage. Exactly that assumption had once produced the HTTP 400.
        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->expects(self::never())->method('generate');

        self::assertNull($this->cartPageUrl($this->frameworkWithRoot(0), $urls)->checkoutOfPage($this->subpage()));
    }

    private function subpage(): PageModel
    {
        $subpage = $this->createMock(PageModel::class);
        $subpage->method('__get')->willReturnMap([['id', 12], ['rootId', self::ROOT]]);
        $subpage->method('row')->willReturn(['id' => 12]);

        return $subpage;
    }

    private function frameworkWithRoot(int $checkoutPageId = 9): ContaoFramework
    {
        $root = $this->root(cartPageId: 7, checkoutPageId: $checkoutPageId);

        $pages = $this->createMock(Adapter::class);
        $pages->method('__call')->willReturnCallback(
            fn (string $method, array $arguments): PageModel|null => match (true) {
                'findByPk' === $method && self::ROOT === $arguments[0] => $root,
                'findById' === $method && 9 === $arguments[0] => $this->createMock(PageModel::class),
                default => null,
            },
        );

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pages);

        return $framework;
    }

    private function cartPageUrl(ContaoFramework $framework, ContentUrlGenerator $urls): ShopPageUrls
    {
        return new ShopPageUrls($framework, new ShopResolver($framework), $urls);
    }

    private function root(int $cartPageId, int $checkoutPageId = 0): PageModel
    {
        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturnMap([['id', self::ROOT], ['rootId', self::ROOT]]);
        $root->method('row')->willReturn([
            'id' => self::ROOT,
            'consho_cartPage' => $cartPageId,
            'consho_checkoutPage' => $checkoutPageId,
        ]);

        return $root;
    }

    private function framework(PageModel|null $found): ContaoFramework
    {
        $pages = $this->createMock(Adapter::class);
        $pages->method('__call')->willReturn($found);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pages);

        return $framework;
    }
}

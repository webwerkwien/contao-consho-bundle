<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\EventListener\ProductRedirectListener;
use Webwerkwien\ContaoConshoBundle\Seo\ProductAddressLookup;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageMissing;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectResolverInterface;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectTarget;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Shop 1 mit Standardsprache de; Produkt 10 in Shop 1.
 */
class ProductRedirectListenerTest extends TestCase
{
    public function testSendsASupersededAddressToTodaysOne(): void
    {
        $event = $this->event('/produkt/schwedenbitter.html');

        $this->listener(
            target: new RedirectTarget(10, 1, 1),
            visible: true,
            url: 'https://example.org/produkt/kraeuterhaus/schwedenbitter.html',
        )($event);

        $response = $event->getResponse();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://example.org/produkt/kraeuterhaus/schwedenbitter.html', $response->getTargetUrl());
    }

    public function testLeavesAWorkingPageAlone(): void
    {
        /*
         * The listener sees every response, so the status check comes first.
         * Without it every page view in the site would cost a query.
         */
        $event = $this->event('/produkt/schwedenbitter.html', 200);

        $this->listener(target: new RedirectTarget(10, 1, 1), visible: true, url: 'https://example.org/x')($event);

        self::assertSame(200, $event->getResponse()->getStatusCode());
    }

    public function testLeavesTheNotFoundAloneWhenNothingWasRecorded(): void
    {
        $event = $this->event('/etwas-anderes.html');

        $this->listener(target: null, visible: true, url: 'https://example.org/x')($event);

        self::assertSame(404, $event->getResponse()->getStatusCode());
    }

    public function testDoesNotRedirectOntoAPageThatIsGone(): void
    {
        /*
         * A redirect onto a 404 is worse than the 404 itself — the visitor
         * waits for a second request to be told the same thing, and a crawler
         * records a working redirect to a dead end.
         */
        $event = $this->event('/produkt/schwedenbitter.html');

        $this->listener(target: new RedirectTarget(10, 1, 1), visible: false, url: 'https://example.org/x')($event);

        self::assertSame(404, $event->getResponse()->getStatusCode());
    }

    public function testDoesNotRedirectIntoALanguageWhereTheProductIsNotTranslated(): void
    {
        /*
         * 2026-09-14: Ohne Sprache gefragt, galt das Produkt auf dem französischen
         * Startpunkt als sichtbar — die 301 führte auf eine französische Adresse,
         * die 404 liefert (VisibilityCriteria, Regel 4).
         */
        $event = $this->event('/produit/amer-suedois.html');

        $this->listener(target: new RedirectTarget(10, 2, 1, 'fr'), visible: true, url: 'https://example.org/produit/x.html')($event);

        self::assertSame(404, $event->getResponse()->getStatusCode());
    }

    public function testRedirectsInATranslationLanguageWhereTheProductIsTranslated(): void
    {
        $event = $this->event('/produit/amer-suedois.html');

        $this->listener(target: new RedirectTarget(10, 2, 1, 'fr'), visible: true, url: 'https://example.org/produit/amer.html', translatedInto: 'fr')($event);

        self::assertSame(301, $event->getResponse()->getStatusCode());
    }

    public function testTheDefaultLanguageNeedsNoTranslation(): void
    {
        $event = $this->event('/produkt/schwedenbitter.html');

        $this->listener(target: new RedirectTarget(10, 1, 1, 'de'), visible: true, url: 'https://example.org/produkt/neu.html')($event);

        self::assertSame(301, $event->getResponse()->getStatusCode());
    }

    public function testLeavesTheNotFoundAloneWhenTheSiteHasNoProductPage(): void
    {
        $event = $this->event('/produkt/schwedenbitter.html');

        $this->listener(target: new RedirectTarget(10, 1, 1), visible: true, url: null)($event);

        self::assertSame(404, $event->getResponse()->getStatusCode());
    }

    public function testRefusesToRedirectAnAddressOntoItself(): void
    {
        /*
         * Possible when a stale row survives a pattern change back to an
         * earlier shape. Without this guard the browser would follow the same
         * address forever.
         */
        $event = $this->event('/produkt/schwedenbitter.html');

        $this->listener(
            target: new RedirectTarget(10, 1, 1),
            visible: true,
            url: 'https://example.org/produkt/schwedenbitter.html',
        )($event);

        self::assertSame(404, $event->getResponse()->getStatusCode());
    }

    private function listener(RedirectTarget|null $target, bool $visible, string|null $url, string|null $translatedInto = null): ProductRedirectListener
    {
        $resolver = $this->createMock(RedirectResolverInterface::class);
        $resolver->method('find')->willReturn($target);

        $urls = $this->createMock(ContentUrlGenerator::class);

        if (null === $url) {
            $urls->method('generate')->willThrowException(ProductPageMissing::belowRootPage(1));
        } else {
            $urls->method('generate')->willReturn($url);
        }

        $repository = $this->createMock(ProductPageRepositoryInterface::class);
        $repository->method('findForRootPage')->willReturn(5);
        $repository->method('urlValues')->willReturn([]);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($this->pageAdapter());

        $catalogue = $this->catalogue($visible, $translatedInto);

        return new ProductRedirectListener(
            $resolver,
            new ProductAddressLookup($catalogue, new VisibilityCriteria()),
            new ProductUrlGenerator(
                $urls,
                $repository,
                $framework,
                new ShopResolver($this->createMock(ContaoFramework::class)),
                new ShopLanguages($catalogue),
            ),
            new ShopLanguages($catalogue),
        );
    }

    /**
     * A real catalogue over an in-memory database rather than doubles of final
     * classes: the lookup asks the visibility rules — in the startpoint's
     * language — instead of asserting what it was told.
     */
    private function catalogue(bool $visible, string|null $translatedInto): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        DcaSchema::create($connection, 'tl_consho_product');
        DcaSchema::create($connection, 'tl_consho_product_shop');
        DcaSchema::create($connection, 'tl_consho_translation');
        $connection->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                type TEXT NOT NULL DEFAULT '',
                language TEXT NOT NULL DEFAULT '',
                consho_shop INTEGER NOT NULL DEFAULT 0,
                consho_currency TEXT NOT NULL DEFAULT ''
            )",
        );
        DcaSchema::create($connection, 'tl_consho_shop');

        $connection->insert('tl_consho_shop', ['id' => 1, 'fallbackLanguage' => 'de']);
        $connection->insert('tl_consho_product', ['id' => 10, 'published' => $visible ? 1 : 0]);
        $connection->insert('tl_consho_product_shop', ['pid' => 10, 'shop' => 1]);

        if (null !== $translatedInto) {
            $connection->insert('tl_consho_translation', ['pid' => 10, 'ptable' => 'tl_consho_product', 'language' => $translatedInto, 'alias' => 'amer-suedois']);
        }

        return $connection;
    }

    /**
     * @return Adapter<PageModel>
     */
    private function pageAdapter(): Adapter
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('__call')->willReturn($this->createMock(PageModel::class));

        return $adapter;
    }

    private function event(string $path, int $status = 404): ResponseEvent
    {
        return new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('https://example.org'.$path),
            HttpKernelInterface::MAIN_REQUEST,
            new Response('', $status),
        );
    }
}

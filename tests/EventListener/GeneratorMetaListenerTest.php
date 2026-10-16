<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener;

use Contao\CoreBundle\Event\LayoutEvent;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContext;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\Twig\LayoutTemplate;
use Contao\PageModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Webwerkwien\ContaoConshoBundle\EventListener\GeneratorMetaListener;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

/** D-6, G1: der Generator-Hinweis auf Shopseiten. */
class GeneratorMetaListenerTest extends TestCase
{
    public function testEineShopseiteTraegtDenHinweis(): void
    {
        $head = $this->head(shopId: 5);

        self::assertSame(['<meta name="generator" content="Consho">'], $this->tags($head));
    }

    public function testEineUnterseiteFragtIhrenStartpunkt(): void
    {
        $root = $this->page(['id' => 1, 'type' => 'root', 'consho_shop' => 5]);
        $page = $this->page(['id' => 9, 'type' => 'regular', 'rootId' => 1]);

        $adapter = $this->createMock(Adapter::class);
        $adapter->method('__call')->with('findByPk', [1])->willReturn($root);

        $head = $this->head(page: $page, adapter: $adapter);

        self::assertSame(['<meta name="generator" content="Consho">'], $this->tags($head));
    }

    public function testEineFremdeSeiteBleibtUnberuehrt(): void
    {
        // Mehrmandanten-Installation: nicht jede Seite gehört einem Shop.
        self::assertSame([], $this->tags($this->head(shopId: 0)));
    }

    public function testNurEinmalAuchBeiMehrerenAufrufen(): void
    {
        $head = new HtmlHeadBag();
        $listener = $this->listener($head);
        $page = $this->page(['id' => 1, 'type' => 'root', 'consho_shop' => 5]);

        $listener->onGeneratePage($page);
        $listener->onGeneratePage($page);

        self::assertCount(1, $this->tags($head));
    }

    /** Fable-Review D-6, K1: der Weg der neuen Layouts, über LayoutEvent. */
    public function testAuchUeberDasLayoutEvent(): void
    {
        $head = new HtmlHeadBag();
        $page = $this->page(['id' => 1, 'type' => 'root', 'consho_shop' => 5]);
        $template = new LayoutTemplate('page/layout', static fn (): Response => new Response());

        $this->listener($head)->onLayout(new LayoutEvent($template, $page, null));

        self::assertSame(['<meta name="generator" content="Consho">'], $this->tags($head));
    }

    public function testOhneVersionsnummer(): void
    {
        self::assertMatchesRegularExpression('/^[A-Za-z]+$/', GeneratorMetaListener::NAME, 'eine Version hülfe Angreifern');
    }

    /**
     * @param Adapter<PageModel>|null $adapter
     */
    private function head(int $shopId = 5, PageModel|null $page = null, Adapter|null $adapter = null): HtmlHeadBag
    {
        $head = new HtmlHeadBag();
        $this->listener($head, $adapter)->onGeneratePage($page ?? $this->page(['id' => 1, 'type' => 'root', 'consho_shop' => $shopId]));

        return $head;
    }

    /**
     * @param Adapter<PageModel>|null $adapter
     */
    private function listener(HtmlHeadBag $head, Adapter|null $adapter = null): GeneratorMetaListener
    {
        $context = new ResponseContext();
        $context->add($head);

        $request = Request::create('https://example.org/');
        $request->attributes->set(ResponseContext::REQUEST_ATTRIBUTE_NAME, $context);
        $stack = new RequestStack();
        $stack->push($request);

        $framework = $this->createMock(ContaoFramework::class);

        if (null !== $adapter) {
            $framework->method('getAdapter')->willReturn($adapter);
        }

        return new GeneratorMetaListener(new ShopResolver($framework), new ResponseContextAccessor($stack));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function page(array $row): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('row')->willReturn($row);
        $page->method('__get')->willReturnCallback(static fn (string $key): mixed => $row[$key] ?? null);

        return $page;
    }

    /**
     * @return list<string>
     */
    private function tags(HtmlHeadBag $head): array
    {
        return array_map(static fn ($tag): string => '<meta'.$tag.'>', $head->getMetaTags());
    }
}

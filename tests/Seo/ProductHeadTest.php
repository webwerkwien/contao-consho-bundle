<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContext;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlDecoder;
use Contao\PageModel;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Seo\ProductHead;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * With a real ResponseContext, real HtmlHeadBag and real ProductUrlGenerator.
 *
 * Page 123 "Produkt" under start point 1, product 1 "Schwedenbitter". The head
 * is prefilled the way CoreResponseContextFactory fills it before the element:
 * title and description of the page, robots `index,follow`.
 *
 * Contao's ContentUrlGenerator is doubled, but behaves like Symfony's
 * generator: values the route does not know are appended as a query. If
 * `variant` got into the canonical, it would thus be visible in the address.
 *
 * Every call additionally checks that robots stays unchanged — never
 * `noindex`, not even on a variant address (Block A 2).
 */
class ProductHeadTest extends TestCase
{
    private const PAGE_TITLE = 'Produkt';

    private const PAGE_DESCRIPTION = 'Unsere Produkte';

    private const CANONICAL = 'https://example.org/produkt/schwedenbitter.html';

    public function testTheHeadNamesTheProductAndPointsAtItsAddress(): void
    {
        $head = $this->apply('Schwedenbitter', 'Nach alter Rezeptur');

        self::assertSame('Schwedenbitter', $head->getTitle());
        self::assertSame('Nach alter Rezeptur', $head->getMetaDescription());
        self::assertSame(self::CANONICAL, $head->getCanonicalUri());
    }

    public function testAnInputEncodedTitleIsDecodedTheWayTheCoreDoes(): void
    {
        // Like CoreResponseContextFactory: HtmlDecoder::inputEncodedToPlainText().
        // The template encodes itself on output — otherwise "&amp;amp;" would be
        // in the title. The teaser is a text field without allowHtml, so likewise.
        //
        // Also pins the order: shorten first, then decode.
        // StringUtil::substr() removes tags — if decoded before, "<süß>" would
        // drop out as a supposed tag.
        $head = $this->apply('Kr&#228;uter &amp; Bitter', 'Bitter &lt;süß&gt;');

        self::assertSame('Kräuter & Bitter', $head->getTitle());
        self::assertSame('Bitter <süß>', $head->getMetaDescription());
    }

    // ── Description: own, otherwise teaser shortened, otherwise the page's ──

    public function testTheProductsOwnMetaDescriptionComesBeforeTheTeaser(): void
    {
        // The product's own meta description comes before the teaser.
        $head = $this->apply('Schwedenbitter', 'Nach alter Rezeptur', metaDescription: 'Schwedenbitter nach Originalrezept, 250 ml');

        self::assertSame('Schwedenbitter nach Originalrezept, 250 ml', $head->getMetaDescription());
    }

    public function testTheOwnMetaDescriptionIsDecodedAndTrimmedButNeverShortened(): void
    {
        // Whoever writes it themselves also decides the length. Only the teaser
        // is shortened, which was not written for the search results.
        $long = str_repeat('Kr&#228;uter &amp; Bitter nach alter Rezeptur. ', 8);

        $head = $this->apply('Schwedenbitter', 'Nach alter Rezeptur', metaDescription: "\n  ".$long."\n");

        self::assertSame(trim(str_repeat('Kräuter & Bitter nach alter Rezeptur. ', 8)), $head->getMetaDescription());
    }

    public function testAMetaDescriptionOfOnlyWhiteSpaceFallsBackToTheTeaser(): void
    {
        $head = $this->apply('Schwedenbitter', 'Nach alter Rezeptur', metaDescription: "\n  ");

        self::assertSame('Nach alter Rezeptur', $head->getMetaDescription());
    }

    public function testWithoutAMetaDescriptionALongTeaserIsShortenedAtAWordBoundary(): void
    {
        // 350 characters. Contao does not shorten a meta description itself
        // (HtmlHeadBag::setMetaDescription() takes the value as is), so ProductHead
        // does — to about 160 characters, at a word boundary, with an ellipsis.
        $teaser = str_repeat('Kräuterbitter nach alter Rezeptur. ', 10);

        $description = $this->apply('Schwedenbitter', $teaser)->getMetaDescription();

        self::assertStringEndsWith(' …', $description);
        self::assertLessThanOrEqual(162, mb_strlen($description));
        self::assertGreaterThan(140, mb_strlen($description));
        // A beginning of the teaser that ends before a space — no half word.
        self::assertStringStartsWith(mb_substr($description, 0, -2).' ', $teaser);
    }

    public function testAShortTeaserIsTakenWhole(): void
    {
        $teaser = 'Kräuterbitter nach alter Rezeptur, mit 22 Kräutern angesetzt und in der Steiermark abgefüllt.';

        self::assertSame($teaser, $this->apply('Schwedenbitter', $teaser)->getMetaDescription());
    }

    public function testWithoutMetaDescriptionAndTeaserThePagesDescriptionStays(): void
    {
        // No excerpt from the description.
        $head = $this->apply('Schwedenbitter', '');

        self::assertSame('Schwedenbitter', $head->getTitle());
        self::assertSame(self::PAGE_DESCRIPTION, $head->getMetaDescription());
    }

    public function testATeaserOfOnlyWhiteSpaceKeepsThePagesDescription(): void
    {
        // A textarea keeps line breaks. An empty meta description would be
        // worse than the page's.
        $head = $this->apply('Schwedenbitter', "\n  ");

        self::assertSame(self::PAGE_DESCRIPTION, $head->getMetaDescription());
    }

    public function testTheProductAddressOverridesACanonicalLinkMaintainedOnThePage(): void
    {
        // This is exactly why the canonical is set and not left to Contao's
        // default: the factory has already entered the product page's maintained
        // canonicalLink; it would otherwise apply to every product.
        $head = $this->pageHead();
        $head->setCanonicalUri('https://example.org/gepflegt.html');

        $this->apply('Schwedenbitter', '', $head);

        self::assertSame(self::CANONICAL, $head->getCanonicalUri());
    }

    public function testAVariantAddressIsCanonicalToItsProduct(): void
    {
        /*
         * Request /produkt/schwedenbitter.html?variant=6. Title and canonical
         * belong to the main product (Google's line on
         * product variants). Even a canonicalKeepParams with `variant`
         * maintained on the page must not bring the parameter
         * back — a canonical that is set takes precedence in HtmlHeadBag.
         */
        $head = $this->pageHead();
        $head->setKeepParamsForCanonical(['variant', 'utm_*']);

        $this->apply('Schwedenbitter', '', $head, 'https://example.org/produkt/schwedenbitter.html?variant=6');

        self::assertSame('Schwedenbitter', $head->getTitle());
        self::assertSame(self::CANONICAL, $head->getCanonicalUri());
        self::assertSame(
            self::CANONICAL,
            $head->getCanonicalUriForRequest(Request::create('https://example.org/produkt/schwedenbitter.html?variant=6&utm_source=newsletter')),
        );
    }

    public function testWithoutAProductPageTheTitleIsSetButNoCanonical(): void
    {
        // ProductUrlGenerator throws ProductPageMissing. Hardly conceivable on the
        // product page itself — but an exception from the head must not take the
        // page down with it, and an empty address is not a canonical.
        $head = $this->apply('Schwedenbitter', 'Nach alter Rezeptur', productPageId: null);

        self::assertSame('Schwedenbitter', $head->getTitle());
        self::assertSame('Nach alter Rezeptur', $head->getMetaDescription());
        self::assertSame('', $head->getCanonicalUri());
    }

    public function testWithoutAResponseContextNothingHappens(): void
    {
        // For example when rendering outside a page. The database is then not
        // even asked for the product page.
        $repository = $this->createMock(ProductPageRepositoryInterface::class);
        $repository->expects(self::never())->method('findForRootPage');

        $this->productHead(new ResponseContextAccessor(new RequestStack()), $repository)
            ->apply(1, 1, 'Schwedenbitter', '', 'Nach alter Rezeptur')
        ;
    }

    public function testAResponseContextWithoutAHeadIsLeftAlone(): void
    {
        // createResponseContext() without a web page creates no HtmlHeadBag.
        $context = new ResponseContext();

        $this->productHead($this->accessor($context), $this->repository(123))
            ->apply(1, 1, 'Schwedenbitter', '', 'Nach alter Rezeptur')
        ;

        self::assertFalse($context->has(HtmlHeadBag::class));
    }

    private function apply(
        string $title,
        string $teaser,
        ?HtmlHeadBag $head = null,
        string $uri = self::CANONICAL,
        ?int $productPageId = 123,
        string $metaDescription = '',
    ): HtmlHeadBag {
        $head ??= $this->pageHead();
        $robots = $head->getMetaRobots();

        $context = new ResponseContext();
        $context->add($head);

        $this->productHead($this->accessor($context, $uri), $this->repository($productPageId))->apply(1, 1, $title, $metaDescription, $teaser);

        self::assertSame($robots, $head->getMetaRobots(), 'ProductHead setzt nie Robots — kein noindex.');

        return $head;
    }

    private function pageHead(): HtmlHeadBag
    {
        return (new HtmlHeadBag())->setTitle(self::PAGE_TITLE)->setMetaDescription(self::PAGE_DESCRIPTION);
    }

    private function productHead(ResponseContextAccessor $accessor, ProductPageRepositoryInterface $repository): ProductHead
    {
        $insertTags = $this->createMock(InsertTagParser::class);
        $insertTags->method('replaceInline')->willReturnArgument(0);

        return new ProductHead($accessor, $this->productUrls($repository), new HtmlDecoder($insertTags));
    }

    private function accessor(ResponseContext $context, string $uri = self::CANONICAL): ResponseContextAccessor
    {
        $request = Request::create($uri);
        $request->attributes->set(ResponseContext::REQUEST_ATTRIBUTE_NAME, $context);

        $stack = new RequestStack();
        $stack->push($request);

        return new ResponseContextAccessor($stack);
    }

    private function repository(?int $productPageId): ProductPageRepositoryInterface
    {
        $repository = $this->createMock(ProductPageRepositoryInterface::class);
        $repository->method('findForRootPage')->willReturn($productPageId);
        $repository->method('urlValues')->willReturn(['alias' => 'schwedenbitter', 'sku' => 'SB-250']);

        return $repository;
    }

    /**
     * The real ProductUrlGenerator — it is final. Only its suppliers are
     * doubled, as in ProductUrlGeneratorTest.
     */
    private function productUrls(ProductPageRepositoryInterface $repository): ProductUrlGenerator
    {
        // Like Symfony's generator: the route knows {alias}, everything else it
        // appends as a query.
        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturnCallback(
            static function (object $content, array $values): string {
                $alias = $values['alias'] ?? '';
                unset($values['alias']);

                return 'https://example.org/produkt/'.$alias.'.html'.([] === $values ? '' : '?'.http_build_query($values));
            },
        );

        $productPage = $this->createMock(PageModel::class);
        $productPage->method('row')->willReturn(['conshoPathTemplate' => '{alias}']);

        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturn('');
        $root->method('row')->willReturn(['consho_shop' => 0]);

        $pages = [123 => $productPage, 1 => $root];

        $adapter = $this->createMock(Adapter::class);
        $adapter
            ->method('__call')
            ->willReturnCallback(static fn (string $name, array $args): ?PageModel => 'findById' === $name ? ($pages[$args[0]] ?? null) : null)
        ;

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->with(PageModel::class)->willReturn($adapter);

        // The same schema as in ProductUrlGeneratorTest — ShopLanguages asks both.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
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

        return new ProductUrlGenerator(
            $urls,
            $repository,
            $framework,
            new ShopResolver($this->createMock(ContaoFramework::class)),
            new ShopLanguages($connection),
        );
    }
}

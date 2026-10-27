<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\Page\PageRegistry;
use Contao\CoreBundle\Routing\PageUrlGenerator;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContext;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlDecoder;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Cmf\Component\Routing\RouteProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingElement;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRecord;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageResolver;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Seo\FilterUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Seo\ListingAddress;
use Webwerkwien\ContaoConshoBundle\Seo\ListingHead;

/**
 * With a real ResponseContext, real HtmlHeadBag and real ContentUrlGenerator.
 *
 * Page 12 "Kräuter" on example.org, list element 7. The head is prefilled
 * the way CoreResponseContextFactory fills it before the element: title
 * and description of the page, robots `index,follow`.
 *
 * Every call additionally checks that robots stays unchanged — never
 * `noindex` (Google's line from Block A 2).
 */
class ListingHeadTest extends TestCase
{
    private const PAGE_TITLE = 'Kräuter';

    private const PAGE_DESCRIPTION = 'Alles über Kräuter';

    // ── Who writes the head ──────────────────────────────────────────────

    public function testOnlyTheFirstListingInAnArticleWritesTheHead(): void
    {
        // Otherwise two address-following lists would both set
        // the canonical, and the one rendered last would win.
        $first = new ListingElement(7, 12, [], true, true);

        self::assertTrue(ListingHead::isWrittenBy($first, 7));
        self::assertFalse(ListingHead::isWrittenBy($first, 8));
    }

    public function testWithoutAListingInAnArticleTheListingThatRendersWritesIt(): void
    {
        /*
         * firstOnPage() never finds a list in a layout section.
         * Without this rule a category whose list sits in the layout
         * would get neither the landing page title nor a canonical.
         */
        self::assertTrue(ListingHead::isWrittenBy(null, 700));
    }

    public function testTheCategoryKeepsItsOwnHeadAndItsPageNumber(): void
    {
        $head = $this->apply(ListingAddress::category(new FilterState()), 2);

        self::assertSame(self::PAGE_TITLE, $head->getTitle());
        self::assertSame(self::PAGE_DESCRIPTION, $head->getMetaDescription());
        self::assertSame('', $head->getCanonicalUri());
        self::assertSame(['page_c7'], $head->getKeepParamsForCanonical());
    }

    public function testPageOneOrAListingWithoutPaginationKeepsNoPageNumber(): void
    {
        // The controller passes 1 for page 1 AND for a list without
        // paging. There ?page_c7=… was typed by hand; if it stayed in the
        // canonical, any number would be a canonical address of its own.
        $head = $this->apply(ListingAddress::category(new FilterState()), 1);

        self::assertSame([], $head->getKeepParamsForCanonical());
        self::assertSame(
            'https://example.org/kraeuter.html',
            $head->getCanonicalUriForRequest(Request::create('https://example.org/kraeuter.html?page_c7=0')),
        );
    }

    public function testContaoThenPointsPageTwoOfTheCategoryAtPageTwo(): void
    {
        // Without the keep param Contao would throw page_c7 away, page 2 would point
        // to page 1 — Google: "Don't use the first page … as the canonical".
        $head = $this->apply(ListingAddress::category(new FilterState()), 2);

        self::assertSame(
            'https://example.org/kraeuter.html?page_c7=2',
            $head->getCanonicalUriForRequest(Request::create('https://example.org/kraeuter.html?page_c7=2&utm_source=newsletter')),
        );
    }

    public function testACanonicalLinkMaintainedOnTheCategoryStays(): void
    {
        // The factory has already set the page's canonicalLink and
        // canonicalKeepParams. Consho does not touch the category itself — only the
        // page number is added to the maintained parameters.
        $head = $this->pageHead();
        $head->setCanonicalUri('https://example.org/gepflegt.html');
        $head->setKeepParamsForCanonical(['utm_*']);

        $this->apply(ListingAddress::category(new FilterState()), 2, $head);

        self::assertSame('https://example.org/gepflegt.html', $head->getCanonicalUri());
        self::assertSame(['utm_*', 'page_c7'], $head->getKeepParamsForCanonical());
    }

    public function testALandingPageGetsItsOwnTitleDescriptionAndCanonical(): void
    {
        $head = $this->apply($this->landing('volume:250ml'));

        self::assertSame('Schwedenbitter 250 ml', $head->getTitle());
        self::assertSame('Alles über Schwedenbitter in 250 ml', $head->getMetaDescription());
        self::assertSame('https://example.org/kraeuter/schwedenbitter-250-ml.html', $head->getCanonicalUri());
        self::assertSame([], $head->getKeepParamsForCanonical());
    }

    public function testPageTwoOfALandingPageIsItsOwnCanonical(): void
    {
        $head = $this->apply($this->landing('volume:250ml'), 2);

        self::assertSame('https://example.org/kraeuter/schwedenbitter-250-ml.html?page_c7=2', $head->getCanonicalUri());
    }

    public function testALandingPageWithoutDescriptionKeepsThePagesDescription(): void
    {
        $head = $this->apply($this->landing('colour:red;volume:250ml'));

        self::assertSame('Rote 250 ml', $head->getTitle());
        self::assertSame(self::PAGE_DESCRIPTION, $head->getMetaDescription());
    }

    public function testASortedLandingPageIsCanonicalToItsUnsortedFirstPage(): void
    {
        // Sorted, page 3 shows different products than unsorted page 3. The
        // page number would thus only remain as a false claim.
        $head = $this->apply($this->landing('volume:250ml', 'price_asc'), 3);

        self::assertSame('Schwedenbitter 250 ml', $head->getTitle());
        self::assertSame('https://example.org/kraeuter/schwedenbitter-250-ml.html', $head->getCanonicalUri());
    }

    public function testALandingPageOverridesACanonicalLinkMaintainedOnTheCategory(): void
    {
        // This is exactly why the canonical is set and not left to the
        // default: otherwise the category's maintained link would win.
        $head = $this->pageHead();
        $head->setCanonicalUri('https://example.org/gepflegt.html');

        $this->apply($this->landing('volume:250ml'), 1, $head);

        self::assertSame('https://example.org/kraeuter/schwedenbitter-250-ml.html', $head->getCanonicalUri());
    }

    public function testAReleasedCombinationInTheParameterTakesTheHeadOfItsLandingPage(): void
    {
        // Old bookmark /kraeuter.html?cq=volume:250ml — no 301,
        // but title and canonical of the landing page.
        $head = $this->apply(ListingAddress::category(new FilterState(['volume' => ['250ml']])));

        self::assertSame('Schwedenbitter 250 ml', $head->getTitle());
        self::assertSame('Alles über Schwedenbitter in 250 ml', $head->getMetaDescription());
        self::assertSame('https://example.org/kraeuter/schwedenbitter-250-ml.html', $head->getCanonicalUri());
        self::assertSame([], $head->getKeepParamsForCanonical());
    }

    public function testPageTwoOfAReleasedCombinationInTheParameterIsPageTwoOfTheLandingPage(): void
    {
        // The page number stays on the parameter address too, not only on the
        // path address: unsorted, ?cq=volume:250ml&page_c7=2 shows
        // the same products as page 2 of the landing page.
        $head = $this->apply(ListingAddress::category(new FilterState(['volume' => ['250ml']])), 2);

        self::assertSame('https://example.org/kraeuter/schwedenbitter-250-ml.html?page_c7=2', $head->getCanonicalUri());
    }

    public function testASortedReleasedCombinationInTheParameterDropsThePageNumber(): void
    {
        $head = $this->apply(ListingAddress::category(new FilterState(['volume' => ['250ml']], 'price_desc')), 2);

        self::assertSame('https://example.org/kraeuter/schwedenbitter-250-ml.html', $head->getCanonicalUri());
    }

    public function testNurVerfuegbareLaesstDieSeitenzahlWeg(): void
    {
        // Block Verfügbarkeit (availability): "Nur verfügbare" narrows the set —
        // page 2 of it is not a page of the landing page. The canonical stays the
        // landing page, without the tick.
        $head = $this->apply(ListingAddress::category(new FilterState(['volume' => ['250ml']], '', true)), 2);
        self::assertSame('https://example.org/kraeuter/schwedenbitter-250-ml.html', $head->getCanonicalUri());

        $category = $this->apply(ListingAddress::category(new FilterState([], '', true)), 2);
        self::assertSame([], $category->getKeepParamsForCanonical());
    }

    public function testAnyOtherFilterLeavesContaosCanonicalOnTheBareCategory(): void
    {
        $head = $this->apply(ListingAddress::category(new FilterState(['colour' => ['red']])), 2);

        self::assertSame(self::PAGE_TITLE, $head->getTitle());
        self::assertSame(self::PAGE_DESCRIPTION, $head->getMetaDescription());
        self::assertSame('', $head->getCanonicalUri());
        self::assertSame([], $head->getKeepParamsForCanonical());

        // Contao's default throws cq and the page number away: the canonical is the
        // category. The combinatorics do not belong in the index (Block A 2).
        self::assertSame(
            'https://example.org/kraeuter.html',
            $head->getCanonicalUriForRequest(Request::create('https://example.org/kraeuter.html?cq=colour:red&page_c7=2')),
        );
    }

    public function testASortOrderAloneDropsThePageNumberToo(): void
    {
        $head = $this->apply(ListingAddress::category(new FilterState([], 'price_asc')), 2);

        self::assertSame(self::PAGE_TITLE, $head->getTitle());
        self::assertSame([], $head->getKeepParamsForCanonical());
    }

    public function testAParameterThatDecodesToNothingIsTheBareCategory(): void
    {
        // ?cq=Foo decodes to an empty state and
        // is treated like the category — cq drops out of the canonical, the
        // page number stays.
        $codec = new FilterStateCodec();
        $head = $this->apply(ListingAddress::category($codec->decode('Foo')), 2);

        self::assertSame(
            'https://example.org/kraeuter.html?page_c7=2',
            $head->getCanonicalUriForRequest(Request::create('https://example.org/kraeuter.html?cq=Foo&page_c7=2')),
        );
    }

    public function testAnInputEncodedTitleIsDecodedTheWayTheCoreDoes(): void
    {
        // Like CoreResponseContextFactory: HtmlDecoder::inputEncodedToPlainText().
        // The template encodes itself on output — double-encoded, "&amp;amp;"
        // would otherwise be in the title.
        $head = $this->apply($this->landing('material:glas'));

        self::assertSame('Kräuter & Co', $head->getTitle());
        self::assertSame('Bitter <süß>', $head->getMetaDescription());
    }

    public function testAnInvalidAddressLeavesTheHeadAlone(): void
    {
        // The list element answers 404 there. If it still gets
        // here, no page number may be marked as canonical.
        $head = $this->apply(ListingAddress::invalid(), 2);

        self::assertSame(self::PAGE_TITLE, $head->getTitle());
        self::assertSame('', $head->getCanonicalUri());
        self::assertSame([], $head->getKeepParamsForCanonical());
    }

    public function testWithoutAResponseContextNothingHappens(): void
    {
        // For example when rendering outside a page. The database is then
        // not even asked.
        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository->expects(self::never())->method('findByState');

        $this->listingHead(new ResponseContextAccessor(new RequestStack()), $repository)
            ->apply($this->kraeuter(), ListingAddress::category(new FilterState(['volume' => ['250ml']])), 7, 1)
        ;
    }

    public function testAResponseContextWithoutAHeadIsLeftAlone(): void
    {
        // createResponseContext() without a web page creates no HtmlHeadBag.
        $context = new ResponseContext();

        $this->listingHead($this->accessor($context), $this->repository())
            ->apply($this->kraeuter(), $this->landing('volume:250ml'), 7, 1)
        ;

        self::assertFalse($context->has(HtmlHeadBag::class));
    }

    private function apply(ListingAddress $address, int $pageNumber = 1, ?HtmlHeadBag $head = null): HtmlHeadBag
    {
        $head ??= $this->pageHead();
        $robots = $head->getMetaRobots();

        $context = new ResponseContext();
        $context->add($head);

        $this->listingHead($this->accessor($context), $this->repository())->apply($this->kraeuter(), $address, 7, $pageNumber);

        self::assertSame($robots, $head->getMetaRobots(), 'ListingHead setzt nie Robots — kein noindex.');

        return $head;
    }

    private function pageHead(): HtmlHeadBag
    {
        return (new HtmlHeadBag())->setTitle(self::PAGE_TITLE)->setMetaDescription(self::PAGE_DESCRIPTION);
    }

    private function landing(string $filterState, string $sort = ''): ListingAddress
    {
        $record = $this->records()[$filterState];

        return ListingAddress::landingPage(
            $record,
            new FilterState((new FilterStateCodec())->decode($filterState)->filters, $sort),
        );
    }

    /**
     * Released under page 12; the key is the encoded combination.
     *
     * @return array<string, FacetPageRecord>
     */
    private function records(): array
    {
        return [
            'volume:250ml' => new FacetPageRecord(1, 12, 'schwedenbitter-250-ml', 'volume:250ml', 'Schwedenbitter 250 ml', 'Alles über Schwedenbitter in 250 ml'),
            'colour:red;volume:250ml' => new FacetPageRecord(2, 12, 'rote-250-ml', 'colour:red;volume:250ml', 'Rote 250 ml', ''),
            'material:glas' => new FacetPageRecord(3, 12, 'glas', 'material:glas', 'Kr&#228;uter &amp; Co', 'Bitter &lt;süß&gt;'),
        ];
    }

    private function repository(): FacetPageRepositoryInterface
    {
        $records = $this->records();

        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository->method('findByState')->willReturnCallback(
            static fn (int $page, string $state): ?FacetPageRecord => 12 === $page ? ($records[$state] ?? null) : null,
        );

        return $repository;
    }

    private function listingHead(ResponseContextAccessor $accessor, FacetPageRepositoryInterface $repository): ListingHead
    {
        $codec = new FilterStateCodec();
        $facets = new FacetPageResolver($repository, $codec);

        $insertTags = $this->createMock(InsertTagParser::class);
        $insertTags->method('replaceInline')->willReturnArgument(0);

        return new ListingHead($accessor, $facets, new FilterUrlGenerator($this->contentUrls(), $facets, $codec), new HtmlDecoder($insertTags));
    }

    private function accessor(ResponseContext $context): ResponseContextAccessor
    {
        $request = Request::create('https://example.org/kraeuter.html');
        $request->attributes->set(ResponseContext::REQUEST_ATTRIBUTE_NAME, $context);

        $stack = new RequestStack();
        $stack->push($request);

        return new ResponseContextAccessor($stack);
    }

    /**
     * As in FilterUrlGeneratorTest: the real generator, without a database.
     */
    private function contentUrls(): ContentUrlGenerator
    {
        $registry = new PageRegistry($this->createMock(Connection::class));
        $router = new PageUrlGenerator($this->createMock(RouteProviderInterface::class), $registry);

        return new ContentUrlGenerator($router, $registry, $this->createMock(EntityManagerInterface::class), []);
    }

    private function kraeuter(): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', 12],
            ['type', 'regular'],
            ['alias', 'kraeuter'],
            ['requireItem', false],
            ['domain', 'example.org'],
            ['rootUseSSL', true],
            ['urlPrefix', ''],
            ['urlSuffix', '.html'],
        ]);

        return $page;
    }
}

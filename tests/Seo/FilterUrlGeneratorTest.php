<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\Page\PageRegistry;
use Contao\CoreBundle\Routing\PageUrlGenerator;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Cmf\Component\Routing\RouteProviderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRecord;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageResolver;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Seo\FilterUrlGenerator;

/**
 * With Contao's real ContentUrlGenerator, not with a mock.
 *
 * What is checked is the string that ends up in the href. A mock would have
 * to rebuild how Symfony encodes the path and appends the query — and that is
 * exactly the question: does `cq=colour:red;volume:250ml` stay readable, or
 * does it become `cq=colour%3Ared%3Bvolume%3A250ml`? Without a database:
 * PageRegistry only asks the connection for the prefixes and suffixes of all
 * start points, not for a route.
 */
class FilterUrlGeneratorTest extends TestCase
{
    /**
     * Page 12 (Kräuter) has released "volume:250ml", page 13 (Tees)
     * "volume:500ml".
     */
    private const RELEASED = [
        12 => ['volume:250ml' => 'schwedenbitter-250-ml'],
        13 => ['volume:500ml' => 'tee-500-ml'],
    ];

    public function testTheFirstFilterGoesIntoTheParameter(): void
    {
        self::assertSame(
            '/kraeuter.html?cq=colour:red',
            $this->generator()->toggle($this->kraeuter(), new FilterState(), 'colour', 'red'),
        );
    }

    public function testASecondOptionOfTheSameGroupJoinsItSorted(): void
    {
        self::assertSame(
            '/kraeuter.html?cq=colour:blue,red',
            $this->generator()->toggle($this->kraeuter(), new FilterState(['colour' => ['red']]), 'colour', 'blue'),
        );
    }

    public function testDeselectingOneOfTwoOptionsKeepsTheOther(): void
    {
        self::assertSame(
            '/kraeuter.html?cq=colour:blue',
            $this->generator()->toggle($this->kraeuter(), new FilterState(['colour' => ['blue', 'red']]), 'colour', 'red'),
        );
    }

    public function testDeselectingTheLastFilterIsTheBareCategory(): void
    {
        // No "?cq=" and no "?cq=colour:" — the category has exactly one
        // address, and it carries no query.
        self::assertSame(
            '/kraeuter.html',
            $this->generator()->toggle($this->kraeuter(), new FilterState(['colour' => ['red']]), 'colour', 'red'),
        );
    }

    public function testDeselectingTheLastFilterKeepsTheSortOrder(): void
    {
        self::assertSame(
            '/kraeuter.html?cq=sort_by:price_asc',
            $this->generator()->toggle($this->kraeuter(), new FilterState(['colour' => ['red']], 'price_asc'), 'colour', 'red'),
        );
    }

    // ── Only available (block Verfügbarkeit, V5) ─────────────────────────

    public function testDasHaekchenStehtImParameterUndBleibtBeimFiltern(): void
    {
        $page = $this->kraeuter();

        self::assertSame('/kraeuter.html?cq=only_available:1', $this->generator()->toggleAvailable($page, new FilterState()));
        self::assertSame('/kraeuter.html?cq=colour:red;only_available:1', $this->generator()->toggle($page, new FilterState([], '', true), 'colour', 'red'));
        self::assertSame('/kraeuter.html?cq=only_available:1;sort_by:price_asc', $this->generator()->withSort($page, new FilterState([], '', true), 'price_asc'));
        self::assertSame('/kraeuter.html?cq=colour:red', $this->generator()->toggleAvailable($page, new FilterState(['colour' => ['red']], '', true)));
    }

    public function testAufDerLandingpageWieDieSortierung(): void
    {
        self::assertSame(
            '/kraeuter/schwedenbitter-250-ml.html?cq=only_available:1',
            $this->generator()->toggle($this->kraeuter(), new FilterState([], '', true), 'volume', '250ml'),
        );
    }

    public function testAReleasedCombinationGetsItsPathAddress(): void
    {
        self::assertSame(
            '/kraeuter/schwedenbitter-250-ml.html',
            $this->generator()->toggle($this->kraeuter(), new FilterState(), 'volume', '250ml'),
        );
    }

    public function testAReleasedCombinationKeepsTheSortOrderAsItsOnlyParameter(): void
    {
        // Sorting creates no new set — the landing page stays, the
        // sort order is the only thing in cq.
        self::assertSame(
            '/kraeuter/schwedenbitter-250-ml.html?cq=sort_by:price_asc',
            $this->generator()->toggle($this->kraeuter(), new FilterState([], 'price_asc'), 'volume', '250ml'),
        );
    }

    public function testAnotherFilterOnALandingPageLeavesItForTheCategory(): void
    {
        // Red + 250 ml is not released. The address of the union is
        // the category with cq — not the landing page with "colour:red" after it.
        self::assertSame(
            '/kraeuter.html?cq=colour:red;volume:250ml',
            $this->generator()->toggle($this->kraeuter(), new FilterState(['volume' => ['250ml']]), 'colour', 'red'),
        );
    }

    public function testAnotherFilterOnALandingPageThatIsReleasedTooGetsThatPathAddress(): void
    {
        $generator = $this->generator([12 => [
            'volume:250ml' => 'schwedenbitter-250-ml',
            'colour:red;volume:250ml' => 'rote-250-ml',
        ]]);

        self::assertSame(
            '/kraeuter/rote-250-ml.html',
            $generator->toggle($this->kraeuter(), new FilterState(['volume' => ['250ml']]), 'colour', 'red'),
        );
    }

    public function testDeselectingTheFilterOfALandingPageIsTheBareCategory(): void
    {
        self::assertSame(
            '/kraeuter.html',
            $this->generator()->toggle($this->kraeuter(), new FilterState(['volume' => ['250ml']]), 'volume', '250ml'),
        );
    }

    public function testDeselectingTheFilterOfASortedLandingPageKeepsTheSortOrder(): void
    {
        self::assertSame(
            '/kraeuter.html?cq=sort_by:price_desc',
            $this->generator()->toggle($this->kraeuter(), new FilterState(['volume' => ['250ml']], 'price_desc'), 'volume', '250ml'),
        );
    }

    public function testTheClickOrderDoesNotChangeTheAddress(): void
    {
        // First red, then 250 ml — first 250 ml, then red. Two paths, one content,
        // one address. On the second path the visitor is even on the
        // landing page in between.
        $generator = $this->generator();
        $page = $this->kraeuter();

        $redFirst = $generator->toggle($page, new FilterState(['colour' => ['red']]), 'volume', '250ml');
        $volumeFirst = $generator->toggle($page, new FilterState(['volume' => ['250ml']]), 'colour', 'red');

        self::assertSame($redFirst, $volumeFirst);
        self::assertSame('/kraeuter.html?cq=colour:red;volume:250ml', $redFirst);
    }

    public function testTheReleaseBelongsToItsPage(): void
    {
        // volume:500ml is released under the teas, not under the herbs.
        self::assertSame(
            '/kraeuter.html?cq=volume:500ml',
            $this->generator()->toggle($this->kraeuter(), new FilterState(), 'volume', '500ml'),
        );
        self::assertSame(
            '/tees/tee-500-ml.html',
            $this->generator()->toggle($this->page(13, 'tees'), new FilterState(), 'volume', '500ml'),
        );
    }

    public function testSortingOnALandingPageStaysOnIt(): void
    {
        self::assertSame(
            '/kraeuter/schwedenbitter-250-ml.html?cq=sort_by:price_desc',
            $this->generator()->withSort($this->kraeuter(), new FilterState(['volume' => ['250ml']], 'price_asc'), 'price_desc'),
        );
    }

    public function testSortingOnTheCategoryKeepsTheFilters(): void
    {
        self::assertSame(
            '/kraeuter.html?cq=colour:red;sort_by:price_desc',
            $this->generator()->withSort($this->kraeuter(), new FilterState(['colour' => ['red']]), 'price_desc'),
        );
    }

    public function testAnEmptySortIsTheDefaultOrderWithoutAParameter(): void
    {
        // The default sort order of the list needs no cq value of its own.
        self::assertSame(
            '/kraeuter/schwedenbitter-250-ml.html',
            $this->generator()->withSort($this->kraeuter(), new FilterState(['volume' => ['250ml']], 'price_asc'), ''),
        );
    }

    public function testThePageNumberIsNeverCarriedOver(): void
    {
        /*
         * The generator gets no request — a page_c<ID> cannot come along at
         * all. Here it is additionally shown that Symfony does not take over the
         * query of the current request either when it is in the RequestContext.
         */
        $context = new RequestContext();
        $context->setQueryString('page_c7=3&cq=colour:red&utm_source=newsletter');

        self::assertSame(
            '/kraeuter.html?cq=colour:blue,red',
            $this->generator(context: $context)->toggle($this->kraeuter(), new FilterState(['colour' => ['red']]), 'colour', 'blue'),
        );
    }

    public function testAnAbsoluteAddressCarriesDomainAndScheme(): void
    {
        // This is how the canonical needs it.
        $page = $this->page(12, 'kraeuter', domain: 'example.org', https: true);

        self::assertSame(
            'https://example.org/kraeuter/schwedenbitter-250-ml.html?cq=sort_by:price_asc',
            $this->generator()->addressOf($page, new FilterState(['volume' => ['250ml']], 'price_asc'), UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    public function testExtraQueryValuesFollowThePathAddressOfALandingPage(): void
    {
        // This is how the canonical of a landing page gets its page number from
        // page 2 on — through Symfony, not by string.
        self::assertSame(
            '/kraeuter/schwedenbitter-250-ml.html?page_c7=2',
            $this->generator()->addressOf($this->kraeuter(), new FilterState(['volume' => ['250ml']]), UrlGeneratorInterface::ABSOLUTE_PATH, ['page_c7' => 2]),
        );
    }

    public function testExtraQueryValuesFollowTheFilterParameterOfTheCategory(): void
    {
        self::assertSame(
            '/kraeuter.html?cq=colour:red&page_c7=2',
            $this->generator()->addressOf($this->kraeuter(), new FilterState(['colour' => ['red']]), UrlGeneratorInterface::ABSOLUTE_PATH, ['page_c7' => 2]),
        );
    }

    public function testAnExtraFilterParameterIsRefused(): void
    {
        // The address is a function of page and state. An extra value
        // named cq would otherwise simply be added on the landing page without
        // sorting — an address that ListingAddressResolver rejects as invalid.
        $this->expectException(\InvalidArgumentException::class);

        $this->generator()->addressOf($this->kraeuter(), new FilterState(['volume' => ['250ml']]), UrlGeneratorInterface::ABSOLUTE_PATH, ['cq' => 'colour:red']);
    }

    public function testAnExtraPathSegmentIsRefused(): void
    {
        // "parameters" is the route variable for the segment — an extra value
        // of this name would invent a landing page on the category.
        $this->expectException(\InvalidArgumentException::class);

        $this->generator()->addressOf($this->kraeuter(), new FilterState(), UrlGeneratorInterface::ABSOLUTE_PATH, ['parameters' => '/erfunden']);
    }

    public function testTheParameterStaysReadableForEveryStateTheCodecLetsThrough(): void
    {
        // Colon, semicolon and comma stay unencoded: Symfony explicitly reverts
        // them in the query (RFC 3986, QUERY_FRAGMENT_DECODED).
        $generator = $this->generator();
        $codec = new FilterStateCodec();

        foreach ([
            'colour:red',
            'colour:blue,red;volume:250ml',
            'colour:blue,red;material:glas-braun;volume:1-5l;sort_by:price_desc',
            'sort_by:title_asc',
        ] as $raw) {
            $url = $generator->addressOf($this->kraeuter(), $codec->decode($raw));

            self::assertSame('/kraeuter.html?cq='.$raw, $url);
            self::assertMatchesRegularExpression('/\?cq=[a-z0-9_,:;\-]+$/', $url);
        }
    }

    public function testAnAliasOutsideTheCodecCannotBreakOutOfTheParameter(): void
    {
        /*
         * Aliases come from the database, not from the visitor — but should
         * something else ever be in there, it must not turn into a second
         * query parameter or a fragment. Symfony encodes & # = and
         * spaces; assembled by hand this would read "&page_c7=9".
         */
        $url = $this->generator()->toggle($this->kraeuter(), new FilterState(), 'colour', 'red&page_c7=9#x y');

        self::assertSame('/kraeuter.html?cq=colour:red%26page_c7%3D9%23x%20y', $url);
    }

    /**
     * @param array<int, array<string, string>> $released page id => [encoded state => alias]
     */
    private function generator(array $released = self::RELEASED, ?RequestContext $context = null): FilterUrlGenerator
    {
        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository->method('findByState')->willReturnCallback(
            static fn (int $page, string $state): ?FacetPageRecord => isset($released[$page][$state])
                ? new FacetPageRecord(1, $page, $released[$page][$state], $state, 'Titel', '')
                : null,
        );

        $codec = new FilterStateCodec();

        return new FilterUrlGenerator($this->contentUrls($context), new FacetPageResolver($repository, $codec), $codec);
    }

    private function contentUrls(?RequestContext $context): ContentUrlGenerator
    {
        $registry = new PageRegistry($this->createMock(Connection::class));
        $router = new PageUrlGenerator($this->createMock(RouteProviderInterface::class), $registry);

        if (null !== $context) {
            $router->setContext($context);
        }

        return new ContentUrlGenerator($router, $registry, $this->createMock(EntityManagerInterface::class), []);
    }

    private function kraeuter(): PageModel
    {
        return $this->page(12, 'kraeuter');
    }

    /**
     * A regular page, as PageRoute reads it after loadDetails().
     */
    private function page(int $id, string $alias, string $domain = '', bool $https = false): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', $id],
            ['type', 'regular'],
            ['alias', $alias],
            ['requireItem', false],
            ['domain', $domain],
            ['rootUseSSL', $https],
            ['urlPrefix', ''],
            ['urlSuffix', '.html'],
        ]);

        return $page;
    }
}

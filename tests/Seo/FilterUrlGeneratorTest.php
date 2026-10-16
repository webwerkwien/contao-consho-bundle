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
 * Mit dem echten ContentUrlGenerator von Contao, nicht mit einem Mock.
 *
 * Geprüft wird die Zeichenkette, die im href landet. Ein Mock müsste nachbauen,
 * wie Symfony den Pfad kodiert und die Query anhängt — und genau das ist die
 * Frage: Bleibt `cq=colour:red;volume:250ml` lesbar, oder wird daraus
 * `cq=colour%3Ared%3Bvolume%3A250ml`? Ohne Datenbank: PageRegistry fragt die
 * Verbindung erst für Präfixe und Suffixe aller Startpunkte, nicht für eine Route.
 */
class FilterUrlGeneratorTest extends TestCase
{
    /**
     * Seite 12 (Kräuter) hat "volume:250ml" freigegeben, Seite 13 (Tees)
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
        // Kein "?cq=" und kein "?cq=colour:" — die Kategorie hat genau eine
        // Adresse, und die trägt keine Query.
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

    // ── Nur verfügbare (block Verfügbarkeit, V5) ─────────────────────────

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
        // Sortieren erzeugt keine neue Menge — die Landingpage bleibt, die
        // Sortierung steht als einziges in cq.
        self::assertSame(
            '/kraeuter/schwedenbitter-250-ml.html?cq=sort_by:price_asc',
            $this->generator()->toggle($this->kraeuter(), new FilterState([], 'price_asc'), 'volume', '250ml'),
        );
    }

    public function testAnotherFilterOnALandingPageLeavesItForTheCategory(): void
    {
        // Rot + 250 ml ist nicht freigegeben. Die Adresse der Vereinigung ist
        // die Kategorie mit cq — nicht die Landingpage mit "colour:red" dahinter.
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
        // Erst rot, dann 250 ml — erst 250 ml, dann rot. Zwei Wege, ein Inhalt,
        // eine Adresse. Beim zweiten Weg steht der Besucher dazwischen sogar
        // auf der Landingpage.
        $generator = $this->generator();
        $page = $this->kraeuter();

        $redFirst = $generator->toggle($page, new FilterState(['colour' => ['red']]), 'volume', '250ml');
        $volumeFirst = $generator->toggle($page, new FilterState(['volume' => ['250ml']]), 'colour', 'red');

        self::assertSame($redFirst, $volumeFirst);
        self::assertSame('/kraeuter.html?cq=colour:red;volume:250ml', $redFirst);
    }

    public function testTheReleaseBelongsToItsPage(): void
    {
        // volume:500ml ist unter den Tees freigegeben, nicht unter den Kräutern.
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
        // Die Vorgabesortierung der Liste braucht keinen eigenen cq-Wert.
        self::assertSame(
            '/kraeuter/schwedenbitter-250-ml.html',
            $this->generator()->withSort($this->kraeuter(), new FilterState(['volume' => ['250ml']], 'price_asc'), ''),
        );
    }

    public function testThePageNumberIsNeverCarriedOver(): void
    {
        /*
         * Der Generator bekommt keinen Request — ein page_c<ID> kann gar nicht
         * mitkommen. Hier zusätzlich belegt, dass auch Symfony die Query der
         * laufenden Anfrage nicht übernimmt, wenn sie im RequestContext steht.
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
        // So braucht es der Canonical (Task 6).
        $page = $this->page(12, 'kraeuter', domain: 'example.org', https: true);

        self::assertSame(
            'https://example.org/kraeuter/schwedenbitter-250-ml.html?cq=sort_by:price_asc',
            $this->generator()->addressOf($page, new FilterState(['volume' => ['250ml']], 'price_asc'), UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    public function testExtraQueryValuesFollowThePathAddressOfALandingPage(): void
    {
        // So bekommt der Canonical einer Landingpage ab Seite 2 seine
        // Seitenzahl (Task 6) — über Symfony, nicht per Zeichenkette.
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
        // Die Adresse ist eine Funktion von Seite und Zustand. Ein Zusatzwert
        // namens cq käme auf der Landingpage ohne Sortierung sonst einfach
        // dazu — eine Adresse, die ListingAddressResolver als ungültig abweist.
        $this->expectException(\InvalidArgumentException::class);

        $this->generator()->addressOf($this->kraeuter(), new FilterState(['volume' => ['250ml']]), UrlGeneratorInterface::ABSOLUTE_PATH, ['cq' => 'colour:red']);
    }

    public function testAnExtraPathSegmentIsRefused(): void
    {
        // "parameters" ist die Routenvariable für das Segment — ein Zusatzwert
        // dieses Namens würde auf der Kategorie eine Landingpage erfinden.
        $this->expectException(\InvalidArgumentException::class);

        $this->generator()->addressOf($this->kraeuter(), new FilterState(), UrlGeneratorInterface::ABSOLUTE_PATH, ['parameters' => '/erfunden']);
    }

    public function testTheParameterStaysReadableForEveryStateTheCodecLetsThrough(): void
    {
        // Doppelpunkt, Strichpunkt und Komma bleiben unkodiert: Symfony nimmt
        // sie in der Query ausdrücklich zurück (RFC 3986, QUERY_FRAGMENT_DECODED).
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
         * Aliase kommen aus der Datenbank, nicht vom Besucher — aber falls doch
         * einmal etwas anderes darin steht, darf daraus kein zweiter
         * Query-Parameter und kein Fragment werden. Symfony kodiert & # = und
         * Leerzeichen; von Hand zusammengesetzt stünde hier "&page_c7=9".
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
     * Eine reguläre Seite, wie PageRoute sie nach loadDetails() liest.
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

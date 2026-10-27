<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\Page\PageRegistry;
use Contao\CoreBundle\Routing\Page\RouteConfig;
use Contao\CoreBundle\Routing\PageUrlGenerator;
use Contao\PageModel;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Cmf\Component\Routing\RouteProviderInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\AvailabilityChoice;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterCountsInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterGroupView;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterOptionRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterOptionView;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterPanelBuilder;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterResolver;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingElement;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRecord;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageResolver;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Seo\FilterUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Seo\ListingAddress;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Counts, visibility and links of the filter controls (B-4).
 *
 * Everything real: FilterResolver, FilterOptionRepository, ProductQuery and
 * FilterUrlGenerator are final, and the question is what comes out of their
 * interplay — against SQLite and with Contao's real ContentUrlGenerator.
 *
 * Catalogue (shop 1, page 12 "Kräuter", where "volume:250ml" is released):
 *
 *   | rot   | 12 items   | 5 with 250 ml, 7 with 500 ml |
 *   | blau  |  8 items   | 3 with 250 ml, 5 with 500 ml |
 *   | grün  |  4 items   | without quantity             |
 *   | navy  |  none      | (only for individual tests)  |
 *
 * "Outside the category" means: not in the default of the list element.
 */
class FilterPanelBuilderTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_000_000;
    private const SHOP = 1;
    private const PAGE = 12;

    private const COLOUR = 1;
    private const RED = 11;
    private const BLUE = 12;
    private const GREEN = 13;
    private const NAVY = 14;

    private const VOLUME = 2;
    private const ML250 = 21;
    private const ML500 = 22;

    /** @var list<string> */
    private static array $statements = [];

    private Connection $connection;

    public static function record(string $sql): void
    {
        self::$statements[] = $sql;
    }

    protected function setUp(): void
    {
        self::$statements = [];

        // Every statement is logged — that way it can be counted how often
        // countsFor() actually asked.
        $configuration = (new Configuration())->setMiddlewares([new class implements Middleware {
            public function wrap(Driver $driver): Driver
            {
                return new class($driver) extends AbstractDriverMiddleware {
                    public function connect(array $params): DriverConnection
                    {
                        return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                            public function prepare(string $sql): DriverStatement
                            {
                                FilterPanelBuilderTest::record($sql);

                                return parent::prepare($sql);
                            }

                            public function query(string $sql): DriverResult
                            {
                                FilterPanelBuilderTest::record($sql);

                                return parent::query($sql);
                            }
                        };
                    }
                };
            }
        }]);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);

        /*
         * Columns, nullability, defaults and UNIQUE indexes as in the
         * real DB — through CatalogSchema, including the visibility projection
         * that ProductQuery reads since B-10. Shop 1 in EUR, start points de and en.
         *
         * 🔴 safetyWarnings/safetyInformation are `text notnull false` —
         * longtext NULL without a default. A field never saved is NULL, not
         * ''. Exactly this extra strictness in the test schema once hid a
         * real bug.
         */
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => self::SHOP, 'title' => 'Shop', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', self::SHOP);
        $this->insertRootPage(2, 'en', self::SHOP);

        $this->connection->insert('tl_consho_property_group', ['id' => self::COLOUR, 'title' => 'Farbe', 'alias' => 'colour']);
        $this->connection->insert('tl_consho_property_group', ['id' => self::VOLUME, 'title' => 'Füllmenge', 'alias' => 'volume']);

        foreach ([
            [self::RED, self::COLOUR, 'Rot', 'red'],
            [self::BLUE, self::COLOUR, 'Blau', 'blue'],
            [self::GREEN, self::COLOUR, 'Grün', 'green'],
            [self::NAVY, self::COLOUR, 'Marineblau', 'navy'],
            [self::ML250, self::VOLUME, '250 ml', '250ml'],
            [self::ML500, self::VOLUME, '500 ml', '500ml'],
        ] as [$id, $group, $title, $alias]) {
            $this->connection->insert('tl_consho_property_option', ['id' => $id, 'pid' => $group, 'title' => $title, 'alias' => $alias]);
        }

        for ($id = 1; $id <= 12; ++$id) {
            $this->product($id, [self::RED, $id <= 5 ? self::ML250 : self::ML500]);
        }

        for ($id = 20; $id <= 27; ++$id) {
            $this->product($id, [self::BLUE, $id <= 22 ? self::ML250 : self::ML500]);
        }

        for ($id = 40; $id <= 43; ++$id) {
            $this->product($id, [self::GREEN]);
        }
    }

    // ── Counts ───────────────────────────────────────────────────────────

    public function testWithoutPresetOrChoiceEveryOptionOfTheShopIsCountedAndLinked(): void
    {
        $panel = $this->build(new FilterState());
        $colour = $this->options($panel, 'colour');

        self::assertSame(['red' => 12, 'blue' => 8, 'green' => 4], $this->counts($colour));
        self::assertSame(['250ml' => 8, '500ml' => 12], $this->counts($this->options($panel, 'volume')));

        foreach ($colour as $option) {
            self::assertFalse($option->selected);
            self::assertNotNull($option->url);
        }
    }

    public function testAChosenOptionLeavesTheNumbersOfItsOwnGroupAlone(): void
    {
        // 🔴 The self-exclusion, left open since B-3. Without it there would be
        // a zero next to "blau", although there are eight blue items.
        $colour = $this->options($this->build(new FilterState(['colour' => ['red']])), 'colour');

        self::assertSame(['red' => 12, 'blue' => 8, 'green' => 4], $this->counts($colour));
        self::assertTrue($colour['red']->selected);
        self::assertFalse($colour['blue']->selected);
    }

    public function testTheOtherGroupDoesNarrowTheNumbers(): void
    {
        $panel = $this->build(new FilterState(['colour' => ['red'], 'volume' => ['250ml']]));

        self::assertSame(['red' => 5, 'blue' => 3, 'green' => 0], $this->counts($this->options($panel, 'colour')));
        self::assertSame(['250ml' => 5, '500ml' => 7], $this->counts($this->options($panel, 'volume')));
    }

    public function testTheNumbersOfAnUntouchedGroupFollowTheChoice(): void
    {
        $panel = $this->build(new FilterState(['colour' => ['red']]));

        self::assertSame(['250ml' => 5, '500ml' => 7], $this->counts($this->options($panel, 'volume')));
    }

    // ── What appears ─────────────────────────────────────────────────────

    public function testWhatThePresetDoesNotContainDoesNotAppear(): void
    {
        $colour = $this->options($this->build(new FilterState(), preset: [self::RED, self::BLUE]), 'colour');

        self::assertSame(['red' => 12, 'blue' => 8], $this->counts($colour));
    }

    public function testAnOptionWithoutProductsInTheCategoryDoesNotAppear(): void
    {
        // "navy" exists as an option, but no item carries it. Otherwise
        // "Kräuterbitter" would list all colours of the shop.
        $colour = $this->options($this->build(new FilterState()), 'colour');

        self::assertArrayNotHasKey('navy', $colour);
    }

    public function testAGroupThePresetNarrowsToOneOptionIsDropped(): void
    {
        // A button that narrows nothing only creates another cq address.
        $panel = $this->build(new FilterState(), preset: [self::RED]);

        self::assertSame(['volume'], $this->groupAliases($panel));
        self::assertSame(['250ml' => 5, '500ml' => 7], $this->counts($this->options($panel, 'volume')));
    }

    public function testAGroupWithOneOptionStaysWhileThatOptionIsChosen(): void
    {
        /*
         * Landing page "250 ml" in a category in which
         * everything has 250 ml. Without a choice the group is dropped (a button
         * that narrows nothing). But if the only option is chosen, it could not
         * be deselected without the group.
         */
        $this->product(50, [self::NAVY, self::ML250]);
        $this->product(51, [self::NAVY, self::ML250]);
        $preset = [self::NAVY];

        $unchosen = $this->build(new FilterState(), preset: $preset);
        $chosen = $this->build(new FilterState(['volume' => ['250ml']]), preset: $preset);
        $volume = $this->options($chosen, 'volume');

        self::assertSame([], $this->groupAliases($unchosen));
        self::assertSame(['volume'], $this->groupAliases($chosen));
        self::assertSame(['250ml'], array_keys($volume));
        self::assertTrue($volume['250ml']->selected);
        self::assertSame('/kraeuter.html', $volume['250ml']->url);
    }

    public function testAnOptionAChoiceBringsDownToZeroStaysWithoutALink(): void
    {
        // Two navy ones without 250 ml, in the default. With "250 ml" navy
        // stays — no layout shift — but a link would lead to a 404.
        $this->product(50, [self::NAVY, self::ML500]);
        $this->product(51, [self::NAVY, self::ML500]);
        $preset = [self::RED, self::BLUE, self::NAVY];

        $before = $this->options($this->build(new FilterState(), preset: $preset), 'colour');
        $after = $this->options($this->build(new FilterState(['volume' => ['250ml']]), preset: $preset), 'colour');

        self::assertSame(array_keys($before), array_keys($after));
        self::assertSame(['red' => 5, 'blue' => 3, 'navy' => 0], $this->counts($after));
        self::assertNull($after['navy']->url);
        self::assertFalse($after['navy']->selected);
        self::assertNotNull($after['blue']->url);
    }

    public function testAChosenOptionTheCategoryLacksStaysSoItCanBeDeselected(): void
    {
        /*
         * ?cq=colour:navy,red — an old bookmark. The list shows the red ones
         * (OR within the group), no 404. If navy disappeared from the controls,
         * "deselect red" would lead to colour:navy, and that is a 404.
         */
        $colour = $this->options($this->build(new FilterState(['colour' => ['navy', 'red']])), 'colour');

        self::assertTrue($colour['navy']->selected);
        self::assertSame(0, $colour['navy']->count);
        self::assertSame('/kraeuter.html?cq=colour:red', $colour['navy']->url);
    }

    public function testAChosenOptionOutsideAnExplicitPresetStaysToo(): void
    {
        /*
         * The same case, but the category explicitly fixes the colour
         * (red, blue). The default filtered navy out before it was even
         * asked whether it is chosen.
         */
        $colour = $this->options(
            $this->build(new FilterState(['colour' => ['navy', 'red']]), preset: [self::RED, self::BLUE]),
            'colour',
        );

        self::assertArrayHasKey('navy', $colour);
        self::assertTrue($colour['navy']->selected);
        self::assertSame('/kraeuter.html?cq=colour:red', $colour['navy']->url);
    }

    public function testAStateThatCannotMatchGivesAnEmptyPanel(): void
    {
        // narrow() yields null — the list answers 404 anyway.
        self::assertSame([], $this->build(new FilterState(['colour' => ['blue']]), preset: [self::RED]));
        self::assertSame(0, $this->countQueries());
    }

    public function testAnInvalidAddressGivesAnEmptyPanel(): void
    {
        // Its state is empty. Without the check the 404 page would show the controls
        // of the whole category.
        self::assertSame([], $this->build(ListingAddress::invalid()));
    }

    public function testOnlyTheOfferedGroupsComeInTheOrderGiven(): void
    {
        self::assertSame(['volume', 'colour'], $this->groupAliases($this->build(new FilterState(), offered: [self::VOLUME, self::COLOUR])));
        self::assertSame(['volume'], $this->groupAliases($this->build(new FilterState(), offered: [self::VOLUME])));
    }

    // ── Without counts: still computed, just not shown ───────────────────

    public function testWithoutNumbersTheyAreStillCountedButNotShown(): void
    {
        // Without computing, the panel did not know which option is empty and
        // linked all of them — a click led to a 404. Counting now always
        // happens; the switch only hides the numbers.
        $panel = $this->build(new FilterState(), preset: [self::RED, self::BLUE], showCounts: false);
        $colour = $this->options($panel, 'colour');

        self::assertSame(1, $this->countQueries());
        self::assertSame(['red' => null, 'blue' => null], $this->counts($colour));
        self::assertSame(['250ml' => null, '500ml' => null], $this->counts($this->options($panel, 'volume')));
        self::assertSame('/kraeuter.html?cq=colour:red', $colour['red']->url);
        self::assertNotNull($colour['blue']->url);
    }

    public function testWithoutNumbersAnOptionWithoutProductsIsNotOffered(): void
    {
        // navy has not a single item. It used to appear linked, and
        // clicking it gave a 404.
        $colour = $this->options($this->build(new FilterState(), showCounts: false), 'colour');

        self::assertSame(['red', 'blue', 'green'], array_keys($colour));
    }

    public function testWithoutNumbersAChoiceThatLeavesNothingIsNotLinked(): void
    {
        // Green has no quantity: 250 ml would stay empty. Visible, so that nothing
        // jumps — but without a link, otherwise 404 again.
        $volume = $this->options($this->build(new FilterState(['colour' => ['green']]), showCounts: false), 'volume');

        self::assertSame(['250ml' => null, '500ml' => null], $this->counts($volume));
        self::assertNull($volume['250ml']->url);
        self::assertNull($volume['500ml']->url);
    }

    // ── Cost ─────────────────────────────────────────────────────────────

    public function testWithoutAChoiceTheNumbersCostOneQuery(): void
    {
        $this->build(new FilterState());

        self::assertSame(1, $this->countQueries());
    }

    public function testAChoiceCostsAtMostTwoQueriesPlusOnePerChosenGroup(): void
    {
        // Base set + untouched groups + "Farbe" without itself.
        $this->build(new FilterState(['colour' => ['red']]));
        self::assertSame(3, $this->countQueries());

        // No group untouched: the query for it is dropped.
        $this->build(new FilterState(['colour' => ['red'], 'volume' => ['250ml']]));
        self::assertSame(3, $this->countQueries());

        // A chosen group that the element does not offer needs no count.
        $this->build(new FilterState(['colour' => ['red']]), offered: [self::VOLUME]);
        self::assertSame(2, $this->countQueries());
    }

    // ── Links ────────────────────────────────────────────────────────────

    public function testTheLinksComeFromTheFilterUrlGenerator(): void
    {
        // Only passed through — FilterUrlGeneratorTest checks how the addresses are built.
        $panel = $this->build(new FilterState(['colour' => ['red']], 'price_asc'));
        $colour = $this->options($panel, 'colour');

        self::assertSame('/kraeuter.html?cq=colour:blue,red;sort_by:price_asc', $colour['blue']->url);
        self::assertSame('/kraeuter.html?cq=sort_by:price_asc', $colour['red']->url, 'abwählen');
    }

    public function testAClickThatMakesAReleasedCombinationLinksToTheLandingPage(): void
    {
        $volume = $this->options($this->build(new FilterState()), 'volume');

        self::assertSame('/kraeuter/schwedenbitter-250-ml.html', $volume['250ml']->url);
    }

    public function testOnALandingPageItsFilterIsChosenAndDeselectingLeadsToTheCategory(): void
    {
        $facet = new FacetPageRecord(1, self::PAGE, 'schwedenbitter-250-ml', 'volume:250ml', 'Schwedenbitter 250 ml', '');
        $panel = $this->build(ListingAddress::landingPage($facet, new FilterState(['volume' => ['250ml']])));
        $volume = $this->options($panel, 'volume');

        self::assertTrue($volume['250ml']->selected);
        self::assertSame('/kraeuter.html', $volume['250ml']->url);
        self::assertSame(['red' => 5, 'blue' => 3, 'green' => 0], $this->counts($this->options($panel, 'colour')));
        self::assertSame('/kraeuter.html?cq=colour:red;volume:250ml', $this->options($panel, 'colour')['red']->url);
    }

    public function testAPageWithoutARouteGivesAnEmptyPanel(): void
    {
        /*
         * ContentUrlGenerator throws for non-routable
         * pages. That really happens — a filter in a layout section with a fixed
         * list also renders on the 404 page, and that one has no route.
         * The error page must not fail on it a second time.
         */
        $registry = new PageRegistry($this->createMock(Connection::class));
        $registry->add('error_404', new RouteConfig(false));

        self::assertSame([], $this->build(new FilterState(), page: $this->page('error_404'), urls: $this->contentUrls($registry)));
    }

    // ── Language ─────────────────────────────────────────────────────────

    public function testLabelsAndNumbersFollowThePageLanguage(): void
    {
        /*
         * In English there is only what is translated: all red, three blue,
         * no green item. Green thus drops out of the base set.
         *
         * ⚠️ The translated items count as saved in the backend here — their
         * safety fields are '', not NULL. Never-saved ones (NULL) once made them
         * disappear in every foreign language; VisibilityCriteriaTest covers that
         * case.
         */
        foreach ([...range(1, 12), 20, 21, 22] as $id) {
            $this->connection->update('tl_consho_product', ['safetyWarnings' => '', 'safetyInformation' => ''], ['id' => $id]);
            $this->translation('tl_consho_product', $id, 'en', 'Product '.$id, 'product-'.$id);
        }

        $this->translation('tl_consho_property_group', self::COLOUR, 'en', 'Colour');
        $this->translation('tl_consho_property_option', self::RED, 'en', 'Red');
        $this->translation('tl_consho_property_option', self::BLUE, 'en', 'Blue');

        $panel = $this->build(new FilterState(), language: 'en', fallback: 'de');
        $colour = $this->options($panel, 'colour');

        self::assertSame('Colour', $this->group($panel, 'colour')->label);
        self::assertSame(['red' => 'Red', 'blue' => 'Blue'], array_map(static fn (FilterOptionView $o): string => $o->label, $colour));
        self::assertSame(['red' => 12, 'blue' => 3], $this->counts($colour));
        self::assertSame(['250ml' => 8, '500ml' => 7], $this->counts($this->options($panel, 'volume')));
        self::assertSame('Füllmenge', $this->group($panel, 'volume')->label, 'ohne Übersetzung die Standardsprache');
    }

    // ── Docking point for large catalogues ───────────────────────────────

    public function testTheCountsComeFromTheInjectedImplementation(): void
    {
        // A module for large catalogues plugs in its own
        // counting. The panel's rules apply to every one — here 99 for "rot"
        // instead of the twelve the catalogue has. The private constants are not
        // reachable in the anonymous class: 11 = red, 13 = green.
        $counts = new class implements FilterCountsInterface {
            public function countsFor(int $shopId, array $groups, int|null $without, string $language, string $currency, bool $availableOnly = false): array
            {
                return [11 => 99, 13 => 5];
            }

            public function count(int $shopId, array $groups, string $language, string $currency, array $excluded = [], bool $availableOnly = false): int
            {
                return 0;
            }
        };

        $panel = $this->build(new FilterState(), counts: $counts);

        self::assertSame(['red' => 99, 'green' => 5], $this->counts($this->options($panel, 'colour')));
        self::assertSame(
            ['colour'],
            array_map(static fn (FilterGroupView $group): string => $group->alias, $panel),
            'Ohne Zahl in der Kategorie fällt „blau" weg und die Füllmenge ganz — dieselben Regeln wie mit der Zählung des Kerns.',
        );
        self::assertSame(0, $this->countQueries(), 'Die Zählung des Kerns wurde nicht gefragt.');
    }

    // ── Only available (block Verfügbarkeit, V5) ─────────────────────────

    public function testNurVerfuegbareSenktDieZahlenUndBehaeltDieOptionen(): void
    {
        foreach (range(40, 43) as $id) {
            $this->connection->insert('tl_consho_sold_out', ['article' => $id]);
        }

        $colour = $this->options($this->build(new FilterState([], '', true)), 'colour');

        self::assertSame(['red' => 12, 'blue' => 8, 'green' => 0], $this->counts($colour));
        self::assertNull($colour['green']->url, 'grün bleibt sichtbar, führt aber nirgends hin');
        self::assertSame('/kraeuter.html?cq=colour:red;only_available:1', $colour['red']->url, 'der Link behält das Häkchen');
    }

    public function testDasHaekchenZaehltUndVerlinkt(): void
    {
        $this->connection->insert('tl_consho_sold_out', ['article' => 40]);

        $choice = $this->availability(new FilterState());
        self::assertNotNull($choice);
        self::assertFalse($choice->selected);
        self::assertSame(23, $choice->count);
        self::assertSame('/kraeuter.html?cq=only_available:1', $choice->url);

        $ticked = $this->availability(new FilterState(['colour' => ['red']], '', true));
        self::assertNotNull($ticked);
        self::assertTrue($ticked->selected);
        self::assertSame('/kraeuter.html?cq=colour:red', $ticked->url, 'abwählen behält den Filter');
    }

    public function testOhneVerfuegbaresKeinLinkZumAnhaken(): void
    {
        foreach (range(40, 43) as $id) {
            $this->connection->insert('tl_consho_sold_out', ['article' => $id]);
        }

        $choice = $this->availability(new FilterState(['colour' => ['green']]), showCounts: false);
        self::assertNotNull($choice);
        self::assertNull($choice->url);
        self::assertNull($choice->count, 'ohne Zahlen an der Liste keine Zahl');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function availability(FilterState $state, bool $showCounts = true): ?AvailabilityChoice
    {
        (new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        ))->rebuild(self::NOW);

        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $codec = new FilterStateCodec();

        return (new FilterPanelBuilder(
            new FilterResolver($this->connection),
            new FilterOptionRepository($this->connection),
            new ProductQuery($this->connection),
            new FilterUrlGenerator($this->contentUrls(), new FacetPageResolver($repository, $codec), $codec),
        ))->availability($this->page(), self::SHOP, new ListingElement(7, self::PAGE, [], $showCounts, true), ListingAddress::category($state), 'de', 'EUR');
    }

    /**
     * @param list<int> $preset
     * @param list<int> $offered
     *
     * @return list<FilterGroupView>
     */
    private function build(
        FilterState|ListingAddress $address,
        array $preset = [],
        bool $showCounts = true,
        array $offered = [],
        string $language = 'de',
        string $currency = 'EUR',
        string $fallback = 'de',
        ?PageModel $page = null,
        ?ContentUrlGenerator $urls = null,
        ?FilterCountsInterface $counts = null,
    ): array {
        // The projection from the fixtures as they are now — at the point in time
        // that the test used to pass to the query itself.
        (new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        ))->rebuild(self::NOW);

        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository->method('findByState')->willReturnCallback(
            static fn (int $pageId, string $encoded): ?FacetPageRecord => self::PAGE === $pageId && 'volume:250ml' === $encoded
                ? new FacetPageRecord(1, self::PAGE, 'schwedenbitter-250-ml', $encoded, 'Schwedenbitter 250 ml', '')
                : null,
        );

        $codec = new FilterStateCodec();
        $builder = new FilterPanelBuilder(
            new FilterResolver($this->connection),
            new FilterOptionRepository($this->connection),
            // The default is the core's counting; a test can plug in another one.
            $counts ?? new ProductQuery($this->connection),
            new FilterUrlGenerator($urls ?? $this->contentUrls(), new FacetPageResolver($repository, $codec), $codec),
        );

        self::$statements = [];

        return $builder->build(
            $page ?? $this->page(),
            self::SHOP,
            new ListingElement(7, self::PAGE, $preset, $showCounts, true),
            $address instanceof ListingAddress ? $address : ListingAddress::category($address),
            $offered,
            $language,
            $currency,
            $fallback,
        );
    }

    /**
     * How often ProductQuery::countsFor() was asked since the last build().
     */
    private function countQueries(): int
    {
        return \count(array_filter(self::$statements, static fn (string $sql): bool => str_contains($sql, 'GROUP BY o.optionId')));
    }

    /**
     * @param list<FilterGroupView> $panel
     */
    private function group(array $panel, string $alias): FilterGroupView
    {
        foreach ($panel as $group) {
            if ($group->alias === $alias) {
                return $group;
            }
        }

        self::fail(\sprintf('Die Gruppe „%s" fehlt.', $alias));
    }

    /**
     * @param list<FilterGroupView> $panel
     *
     * @return array<string, FilterOptionView> alias => option, in the order of the panel
     */
    private function options(array $panel, string $alias): array
    {
        $options = [];

        foreach ($this->group($panel, $alias)->options as $option) {
            $options[$option->alias] = $option;
        }

        return $options;
    }

    /**
     * @param array<string, FilterOptionView> $options
     *
     * @return array<string, int|null>
     */
    private function counts(array $options): array
    {
        return array_map(static fn (FilterOptionView $option): ?int => $option->count, $options);
    }

    /**
     * @param list<FilterGroupView> $panel
     *
     * @return list<string>
     */
    private function groupAliases(array $panel): array
    {
        return array_map(static fn (FilterGroupView $group): string => $group->alias, $panel);
    }

    /**
     * @param list<int> $options
     */
    private function product(int $id, array $options): void
    {
        // Safety fields stay NULL — an item on which nobody ever saved them,
        // as every single one was on a real installation.
        $this->connection->insert('tl_consho_product', ['id' => $id, 'title' => 'Produkt '.$id, 'published' => 1]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => $id, 'shop' => self::SHOP]);
        $this->connection->insert('tl_consho_product_list_price', ['pid' => $id, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);

        foreach ($options as $optionId) {
            $this->connection->insert('tl_consho_product_property', ['pid' => $id, 'optionId' => $optionId]);
        }
    }

    private function translation(string $ptable, int $pid, string $language, string $title, ?string $alias = null): void
    {
        $this->connection->insert('tl_consho_translation', [
            'ptable' => $ptable,
            'pid' => $pid,
            'language' => $language,
            'title' => $title,
            'alias' => $alias,
        ]);
    }

    private function contentUrls(?PageRegistry $registry = null): ContentUrlGenerator
    {
        $registry ??= new PageRegistry($this->createMock(Connection::class));
        $router = new PageUrlGenerator($this->createMock(RouteProviderInterface::class), $registry);

        return new ContentUrlGenerator($router, $registry, $this->createMock(EntityManagerInterface::class), []);
    }

    /**
     * A page as PageRoute reads it after loadDetails().
     */
    private function page(string $type = 'regular'): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', self::PAGE],
            ['type', $type],
            ['alias', 'kraeuter'],
            ['requireItem', false],
            ['domain', ''],
            ['rootUseSSL', false],
            ['urlPrefix', ''],
            ['urlSuffix', '.html'],
        ]);

        return $page;
    }
}

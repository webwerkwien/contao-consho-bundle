<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\ProductListController;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSort;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Against a real database — the whole class is one SQL statement, and an
 * assertion about a query string would prove nothing about what it returns.
 *
 * Since B-10 ProductQuery reads the visibility projection. The fixtures write
 * the catalogue; every helper that queries builds the projection first with
 * the real projector at NOW (shop 1 in EUR, start points de, en, fr).
 */
class ProductQueryTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_000_000;
    private const SHOP = 1;

    /** Property group 1: colour */
    private const RED = 11;
    private const BLUE = 12;

    /** Property group 2: volume */
    private const ML250 = 21;

    /** Property group 3: size, used as a variant axis */
    private const SIZE_M = 31;
    private const SIZE_L = 32;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        /*
         * 🔴 Prices are NUMERIC in CatalogSchema, not TEXT. SQLite compares TEXT
         * lexicographically, so "10" sorts before "8.33" and a price order comes
         * out wrong — or, worse, accidentally right whenever every value happens
         * to have the same number of digits. In MySQL these are DECIMAL(12,4).
         */
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => self::SHOP, 'title' => 'Shop', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', self::SHOP);
        $this->insertRootPage(2, 'en', self::SHOP);
        $this->insertRootPage(3, 'fr', self::SHOP);
    }

    public function testWithoutFiltersItReturnsEveryVisiblePricedProduct(): void
    {
        $this->product(1, properties: [self::RED]);
        $this->product(2, properties: [self::BLUE]);

        self::assertSame([1, 2], $this->find([]));
    }

    public function testOneValueSelects(): void
    {
        $this->product(1, properties: [self::RED]);
        $this->product(2, properties: [self::BLUE]);

        self::assertSame([1], $this->find([1 => [self::RED]]));
    }

    public function testTwoValuesOfOneGroupAreOr(): void
    {
        $this->product(1, properties: [self::RED]);
        $this->product(2, properties: [self::BLUE]);
        $this->product(3, properties: [self::ML250]);

        self::assertSame([1, 2], $this->find([1 => [self::RED, self::BLUE]]));
    }

    public function testTwoGroupsAreAnd(): void
    {
        $this->product(1, properties: [self::RED, self::ML250]);
        $this->product(2, properties: [self::RED]);
        $this->product(3, properties: [self::ML250]);

        self::assertSame([1], $this->find([1 => [self::RED], 2 => [self::ML250]]));
    }

    public function testAnEmptyGroupIsNotAFilter(): void
    {
        // "in ()" is a syntax error in some engines and an empty result in
        // others. Either way it is not what an unselected group means.
        $this->product(1, properties: [self::RED]);

        self::assertSame([1], $this->find([1 => []]));
    }

    public function testAnUnknownOptionFindsNothingRatherThanFailing(): void
    {
        // Comes straight from the URL, where anybody can type anything.
        $this->product(1, properties: [self::RED]);

        self::assertSame([], $this->find([1 => [999_999]]));
    }

    // ── The variant axis ─────────────────────────────────────────────────

    public function testAFilterOnAVariantAxisFindsTheMainProduct(): void
    {
        /*
         * 🔴 The reason this class searches two tables. "Größe M" hangs on no
         * row of the main product — a query that only reads the property table
         * returns nothing, and nothing fails while it does.
         */
        $this->product(1, priced: false);
        $this->variant(10, of: 1, options: [self::SIZE_M]);
        $this->variant(11, of: 1, options: [self::SIZE_L]);

        self::assertSame([1], $this->find([3 => [self::SIZE_M]]));
    }

    public function testAVariantThatIsSwitchedOffDoesNotQualifyItsParent(): void
    {
        $this->product(1, priced: false);
        $this->variant(10, of: 1, options: [self::SIZE_M], published: false);
        $this->variant(11, of: 1, options: [self::SIZE_L]);

        self::assertSame([], $this->find([3 => [self::SIZE_M]]));
        self::assertSame([1], $this->find([3 => [self::SIZE_L]]));
    }

    public function testAPropertyAndAVariantAxisCombine(): void
    {
        // "Kräuterbitter in Größe M": one condition on the main product, one on
        // its variants, and both have to hold.
        $this->product(1, properties: [self::RED], priced: false);
        $this->variant(10, of: 1, options: [self::SIZE_M]);

        $this->product(2, properties: [self::BLUE], priced: false);
        $this->variant(20, of: 2, options: [self::SIZE_M]);

        self::assertSame([1], $this->find([1 => [self::RED], 3 => [self::SIZE_M]]));
    }

    // ── One variant for every group (block Verfügbarkeit, F2) ────────────

    public function testZweiAchsenMuessenAnDerselbenVarianteHaengen(): void
    {
        // Shirt 1: red only in M — matches. Shirt 2: red only in L, M only in
        // blue — matched until F2, although there is no red M.
        $this->product(1, priced: false);
        $this->variant(10, of: 1, options: [self::RED, self::SIZE_M]);
        $this->product(2, priced: false);
        $this->variant(20, of: 2, options: [self::RED, self::SIZE_L]);
        $this->variant(21, of: 2, options: [self::BLUE, self::SIZE_M]);

        self::assertSame([1], $this->find([1 => [self::RED], 3 => [self::SIZE_M]]));
    }

    public function testEineEigenschaftDesHauptproduktsGiltFuerJedeVariante(): void
    {
        $this->product(1, properties: [self::ML250], priced: false);
        $this->variant(10, of: 1, options: [self::SIZE_M]);

        self::assertSame([1], $this->find([2 => [self::ML250], 3 => [self::SIZE_M]]));
    }

    public function testDieZahlEinerAchseZaehltNurDieVarianteDieAuchDenRestErfuellt(): void
    {
        $this->product(1, priced: false);
        $this->variant(10, of: 1, options: [self::RED, self::SIZE_M]);
        $this->product(2, priced: false);
        $this->variant(20, of: 2, options: [self::RED, self::SIZE_L]);
        $this->variant(21, of: 2, options: [self::BLUE, self::SIZE_M]);

        // Under "rot" (red) M leads to shirt 1, L to shirt 2 — one each, like the click.
        $counts = $this->counts([1 => [self::RED]], null);
        self::assertSame(1, $counts[self::SIZE_M] ?? 0);
        self::assertSame(1, $counts[self::SIZE_L] ?? 0);
        self::assertCount(1, $this->find([1 => [self::RED], 3 => [self::SIZE_M]]));
    }

    // ── Only available (block Verfügbarkeit, V2–V6) ──────────────────────

    public function testNurVerfuegbareLaesstAusverkaufteWeg(): void
    {
        $this->product(1);
        $this->product(2);
        $this->soldOut(2);

        self::assertSame([1], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de', availableOnly: true));
        self::assertSame(1, $this->query()->count(self::SHOP, [], 'de', 'EUR', [], true));
        self::assertSame([1, 2], $this->find([]), 'ohne den Filter bleibt alles');
    }

    public function testEinProduktMitEinerVerfuegbarenVarianteBleibt(): void
    {
        $this->product(1, priced: false);
        $this->variant(10, of: 1, options: [self::SIZE_M]);
        $this->variant(11, of: 1, options: [self::SIZE_L]);
        $this->soldOut(10);

        self::assertSame([1], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de', availableOnly: true));

        $this->soldOut(11);
        self::assertSame([], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de', availableOnly: true));
    }

    public function testMitAchseMussDieGewaehlteVarianteSelbstVerfuegbarSein(): void
    {
        // V6: M is sold out, L is not — "M + nur verfügbare" finds nothing.
        $this->product(1, priced: false);
        $this->variant(10, of: 1, options: [self::SIZE_M]);
        $this->variant(11, of: 1, options: [self::SIZE_L]);
        $this->soldOut(10);

        self::assertSame([], $this->query()->find(self::SHOP, [3 => [self::SIZE_M]], 'de', 'EUR', 'de', availableOnly: true));
        self::assertSame([1], $this->query()->find(self::SHOP, [3 => [self::SIZE_L]], 'de', 'EUR', 'de', availableOnly: true));

        $counts = $this->query()->countsFor(self::SHOP, [], null, 'de', 'EUR', true);
        self::assertSame(0, $counts[self::SIZE_M] ?? 0);
        self::assertSame(1, $counts[self::SIZE_L] ?? 0);
    }

    public function testEineMarkeAmHauptproduktMitVariantenZaehltNicht(): void
    {
        // Must not exist (SoldOutMarks) — and if it does, the variants
        // decide.
        $this->product(1, priced: false);
        $this->variant(10, of: 1);
        $this->soldOut(1);

        self::assertSame([1], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de', availableOnly: true));
    }

    public function testEinAusverkaufterPinFaelltMitDemFilterWeg(): void
    {
        $this->product(1);
        $this->product(2);
        $this->soldOut(1);

        self::assertSame([2], $this->query()->visibleAmong([1, 2], self::SHOP, [], 'de', 'EUR', true));
        self::assertSame([1, 2], $this->query()->visibleAmong([1, 2], self::SHOP, [], 'de', 'EUR'));
    }

    /**
     * The recount for the 404 verdict of the product list (open point of the
     * availability block): "Nur verfügbare" never turns an address into an error
     * page. Against the real query, through the controller's method — no
     * copy of its condition.
     */
    public function testDasUrteilZaehltOhneNurVerfuegbareNach(): void
    {
        $this->product(1, properties: [self::RED]);
        $this->product(2, properties: [self::BLUE]);
        $this->soldOut(1);
        $query = $this->query();
        $red = [1 => [self::RED]];

        // Red is sold out: empty with the filter, but the address exists.
        self::assertSame(1, ProductListController::verdictMatches($query, 0, true, $red, [], false, self::SHOP, $red, 'de', 'EUR'));
        // A curated list whose only pin is sold out.
        self::assertSame(1, ProductListController::verdictMatches($query, 0, true, [], [1], true, self::SHOP, [], 'de', 'EUR'));
        self::assertSame(0, ProductListController::verdictMatches($query, 0, true, [], [], true, self::SHOP, [], 'de', 'EUR'), 'kuratiert ohne Pin: wirklich leer');
        // Without "Nur verfügbare" or with hits: not recounted.
        self::assertSame(0, ProductListController::verdictMatches($query, 0, false, $red, [], false, self::SHOP, $red, 'de', 'EUR'));
        self::assertSame(5, ProductListController::verdictMatches($query, 5, true, $red, [], false, self::SHOP, $red, 'de', 'EUR'));
        self::assertSame(0, ProductListController::verdictMatches($query, 0, true, null, [1], false, self::SHOP, $red, 'de', 'EUR'), 'ohne Regel wird nichts gefragt');
        // If red does not exist at all, it stays empty — then ListingVerdict judges.
        self::assertSame(0, ProductListController::verdictMatches($query, 0, true, [2 => [self::ML250]], [], false, self::SHOP, [2 => [self::ML250]], 'de', 'EUR'));
    }

    public function testNichtVerfuegbaresAnsEndeVorJederSortierung(): void
    {
        $this->product(1, gross: '5.0000');
        $this->product(2, gross: '9.0000');
        $this->product(3, gross: '7.0000');
        $this->soldOut(1);

        self::assertSame([3, 2, 1], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de', ProductSort::PRICE_ASC, unavailableLast: true));
        self::assertSame([1, 3, 2], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de', ProductSort::PRICE_ASC), 'ohne Schalter wie bisher');
    }

    public function testAnsEndeGiltFuerDieGefilterteVariante(): void
    {
        // Under "M" shirt 1 is not available, although its L variant is.
        $this->product(1, priced: false, title: 'A');
        $this->variant(10, of: 1, options: [self::SIZE_M]);
        $this->variant(11, of: 1, options: [self::SIZE_L]);
        $this->product(2, priced: false, title: 'B');
        $this->variant(20, of: 2, options: [self::SIZE_M]);
        $this->soldOut(10);

        self::assertSame([2, 1], $this->query()->find(self::SHOP, [3 => [self::SIZE_M]], 'de', 'EUR', 'de', unavailableLast: true));
        self::assertSame([1, 2], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de', unavailableLast: true), 'ohne Filter: L trägt Shirt 1');
    }

    // ── Priceable ────────────────────────────────────────────────────────

    public function testAProductWithoutAPriceDoesNotAppear(): void
    {
        $this->product(1, properties: [self::RED], priced: false);

        self::assertSame([], $this->find([]));
    }

    public function testAProductWhoseVariantsCarryThePriceDoesAppear(): void
    {
        /*
         * 🔴 An INNER JOIN of the price projection onto the main product looks
         * obvious. With variants the prices sit on the variants — block A
         * says a price belongs to "product/variant" — so that would have hidden
         * every variant-based article in the shop.
         */
        $this->product(1, priced: false);
        $this->variant(10, of: 1, options: [self::SIZE_M]);

        self::assertSame([1], $this->find([]));
    }

    public function testAPriceInAnotherCurrencyDoesNotCount(): void
    {
        $this->product(1, properties: [self::RED], priced: false);
        $this->price(1, currency: 'CHF');

        self::assertSame([], $this->find([]));
    }

    public function testAnUnpricedVariantDoesNotCarryItsParent(): void
    {
        $this->product(1, priced: false);
        $this->variant(10, of: 1, options: [self::SIZE_M], priced: false);

        self::assertSame([], $this->find([]));
    }

    public function testTheMainProductsOwnRowDoesNotCarryItsVariants(): void
    {
        /*
         * Inheritance is the projector's business: a variant without its own price
         * gets a row there with the main product's price. For products with
         * variants the query therefore reads only their rows — if
         * no visible variant has one, there is nothing to buy (A-4b, E2).
         */
        $this->product(1);
        $this->variant(10, of: 1, options: [self::SIZE_M], priced: false);

        self::assertSame([], $this->find([]));
    }

    // ── Visibility still applies ─────────────────────────────────────────

    public function testAnUnpublishedProductDoesNotAppear(): void
    {
        $this->product(1, properties: [self::RED], published: false);

        self::assertSame([], $this->find([]));
    }

    public function testAProductOfAnotherShopDoesNotAppear(): void
    {
        $this->product(1, properties: [self::RED], shop: 2);

        self::assertSame([], $this->find([]));
    }

    public function testAVariantIsNeverReturnedAsAResult(): void
    {
        // A listing shows main products. A variant has no address of its own.
        $this->product(1, priced: false);
        $this->variant(10, of: 1, options: [self::SIZE_M]);

        self::assertSame([1], $this->find([]));
    }

    // ── The counts on the filters ────────────────────────────────────────

    public function testCountsEveryOptionOfTheMatchingProducts(): void
    {
        $this->product(1, properties: [self::RED, self::ML250]);
        $this->product(2, properties: [self::BLUE]);

        $counts = $this->counts([], null);

        self::assertSame(1, $counts[self::RED]);
        self::assertSame(1, $counts[self::BLUE]);
        self::assertSame(1, $counts[self::ML250]);
    }

    public function testTheChosenGroupKeepsItsOtherNumbers(): void
    {
        /*
         * 🔴 The test this method exists for. Twelve red and eight blue
         * articles, the customer picks red. Without self-exclusion the number
         * beside "blau" drops to zero and the control they would use to change
         * their mind looks dead.
         */
        for ($i = 1; $i <= 12; ++$i) {
            $this->product($i, properties: [self::RED]);
        }

        for ($i = 20; $i <= 27; ++$i) {
            $this->product($i, properties: [self::BLUE]);
        }

        $counts = $this->counts([1 => [self::RED]], without: 1);

        self::assertSame(12, $counts[self::RED]);
        self::assertSame(8, $counts[self::BLUE]);
    }

    public function testAnotherGroupDoesNarrowTheNumbers(): void
    {
        // Self-exclusion applies to the group being counted, not to the rest.
        $this->product(1, properties: [self::RED, self::ML250]);
        $this->product(2, properties: [self::BLUE, self::ML250]);
        $this->product(3, properties: [self::BLUE]);

        $counts = $this->counts([1 => [self::RED], 2 => [self::ML250]], without: 1);

        self::assertSame(1, $counts[self::RED]);
        self::assertSame(1, $counts[self::BLUE], 'only the blue 250 ml article counts');
    }

    public function testNumbersOfUntouchedGroupsUseEveryActiveFilter(): void
    {
        $this->product(1, properties: [self::RED, self::ML250]);
        $this->product(2, properties: [self::BLUE, self::ML250]);

        $counts = $this->counts([1 => [self::RED]], without: null);

        self::assertSame(1, $counts[self::ML250]);
        self::assertArrayNotHasKey(self::BLUE, $counts, 'blue is filtered away and has nothing to count');
    }

    public function testAVariantAxisIsCountedToo(): void
    {
        $this->product(1, priced: false);
        $this->variant(10, of: 1, options: [self::SIZE_M]);
        $this->variant(11, of: 1, options: [self::SIZE_L]);

        $counts = $this->counts([], null);

        self::assertSame(1, $counts[self::SIZE_M]);
        self::assertSame(1, $counts[self::SIZE_L]);
    }

    public function testAProductIsCountedOnceEvenWithTwoMatchingVariants(): void
    {
        // COUNT(DISTINCT) over the union — a listing shows the product once, so
        // the number beside the filter has to mean the same thing.
        $this->product(1, priced: false);
        $this->variant(10, of: 1, options: [self::SIZE_M]);
        $this->variant(11, of: 1, options: [self::SIZE_M]);

        self::assertSame(1, $this->counts([], null)[self::SIZE_M]);
    }

    public function testAnInvisibleVariantContributesNoNumber(): void
    {
        $this->product(1, properties: [self::RED]);
        $this->variant(10, of: 1, options: [self::SIZE_M], published: false);

        self::assertArrayNotHasKey(self::SIZE_M, $this->counts([], null));
    }

    // ── Sorting and paging ───────────────────────────────────────────────

    public function testSortsByTitleByDefault(): void
    {
        $this->product(1, title: 'Zwetschkenbrand');
        $this->product(2, title: 'Anisschnaps');

        self::assertSame([2, 1], $this->sorted(ProductSort::TITLE));
    }

    public function testSortsByPrice(): void
    {
        $this->product(1, title: 'A', gross: '30.0000');
        $this->product(2, title: 'B', gross: '10.0000');
        $this->product(3, title: 'C', gross: '20.0000');

        self::assertSame([2, 3, 1], $this->sorted(ProductSort::PRICE_ASC));
        self::assertSame([1, 3, 2], $this->sorted(ProductSort::PRICE_DESC));
    }

    public function testPriceSortingUsesTheCheapestVisibleVariant(): void
    {
        /*
         * 🔴 With variants the prices sit on the variants, so the main product
         * row may carry none. Sorting by its own column would put every
         * variant-based article in one indistinguishable clump — and the
         * minimum is also what the tile has to print as "ab 12,60 €".
         */
        $this->product(1, title: 'Teuer', gross: '30.0000');

        $this->product(2, title: 'Varianten', priced: false);
        $this->variant(20, of: 2, gross: '25.0000');
        $this->variant(21, of: 2, gross: '5.0000');

        self::assertSame([2, 1], $this->sorted(ProductSort::PRICE_ASC));
    }

    public function testAnInvisibleVariantDoesNotSetThePrice(): void
    {
        // "ab 5 €" for an article nobody can buy at five euros would be a
        // price statement that is simply untrue.
        $this->product(1, title: 'Teuer', gross: '20.0000');

        $this->product(2, title: 'Varianten', priced: false);
        $this->variant(20, of: 2, gross: '30.0000');
        $this->variant(21, of: 2, gross: '5.0000', published: false);

        self::assertSame([1, 2], $this->sorted(ProductSort::PRICE_ASC));
    }

    public function testOnceAProductHasVariantsItsOwnPriceDoesNotSetTheSortOrder(): void
    {
        // A-4b: main product €10, variants with their own €15 and €18.
        // Previously the product sorted at €10 — the detail page says "ab 15 €".
        $this->product(1, title: 'Einzeln', gross: '12.0000');

        $this->product(2, title: 'Varianten', gross: '10.0000');
        $this->variant(20, of: 2, gross: '15.0000');
        $this->variant(21, of: 2, gross: '18.0000');

        self::assertSame([1, 2], $this->sorted(ProductSort::PRICE_ASC));
    }

    public function testSortsByTheColumnTheVisitorActuallySees(): void
    {
        // Since A-5 a customer with a valid VAT ID is billed net and sees net.
        // Sorting by gross would order the listing by a number they cannot see.
        $this->product(1, title: 'A', gross: '12.0000');
        $this->product(2, title: 'B', gross: '10.0000');

        self::assertSame([2, 1], $this->sorted(ProductSort::PRICE_ASC, showsNet: true));
    }

    public function testSortsNewestFirst(): void
    {
        $this->product(1, title: 'Alt');
        $this->product(2, title: 'Neu');

        self::assertSame([2, 1], $this->sorted(ProductSort::NEWEST));
    }

    public function testSortsByTheEditorsOwnOrder(): void
    {
        $this->product(1, title: 'A', sorting: 128);
        $this->product(2, title: 'B', sorting: 64);

        self::assertSame([2, 1], $this->sorted(ProductSort::MANUAL));
    }

    public function testEveryOrderHasATieBreak(): void
    {
        /*
         * Titles repeat, prices repeat, a manual order is full of zeros until
         * somebody drags something. Without a tie-break the engine is free to
         * return a different order per query — and a product then appears on
         * page one and page two of the same listing, or on neither.
         */
        $this->product(1, title: 'Gleich', sorting: 0, gross: '10.0000');
        $this->product(2, title: 'Gleich', sorting: 0, gross: '10.0000');
        $this->product(3, title: 'Gleich', sorting: 0, gross: '10.0000');

        foreach ([ProductSort::TITLE, ProductSort::PRICE_ASC, ProductSort::MANUAL] as $sort) {
            self::assertSame([1, 2, 3], $this->sorted($sort), $sort);
        }
    }

    public function testPagesThroughTheListing(): void
    {
        $this->product(1, title: 'A');
        $this->product(2, title: 'B');
        $this->product(3, title: 'C');

        self::assertSame([1, 2], $this->sorted(ProductSort::TITLE, limit: 2));
        self::assertSame([3], $this->sorted(ProductSort::TITLE, limit: 2, offset: 2));
    }

    public function testCountsTheWholeListingNotThePage(): void
    {
        $this->product(1, title: 'A');
        $this->product(2, title: 'B');
        $this->product(3, title: 'C');

        self::assertSame(3, $this->query()->count(self::SHOP, [], 'de', 'EUR'));
    }

    /**
     * @return list<int>
     */
    private function sorted(string $sort, bool $showsNet = false, int $limit = 0, int $offset = 0): array
    {
        return $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de', $sort, $showsNet, $offset, $limit);
    }

    // ── Multilingualism ──────────────────────────────────────────────────

    public function testSortingUsesTheTranslatedTitle(): void
    {
        // Opposite: in German A before B, in English B before A. Without an
        // opposite order the test measures nothing — both sort orders
        // would return the same result, and a forgotten COALESCE would go unnoticed.
        $this->product(1, title: 'Apfel');
        $this->product(2, title: 'Birne');
        $this->translate(1, 'en', alias: 'a', title: 'Zebra');
        $this->translate(2, 'en', alias: 'b', title: 'Antelope');

        self::assertSame([1, 2], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de', ProductSort::TITLE));
        self::assertSame([2, 1], $this->query()->find(self::SHOP, [], 'en', 'EUR', 'de', ProductSort::TITLE));
    }

    public function testAProductWithoutATranslationKeepsItsOwnTitleForSorting(): void
    {
        // The fallback also applies in sorting, otherwise an
        // untranslated product would slip to the end or to the beginning.
        $this->product(1, title: 'Apfel');
        $this->product(2, title: 'Birne');
        $this->translate(1, 'en', alias: 'a');
        $this->translate(2, 'en', alias: 'b');

        self::assertSame([1, 2], $this->query()->find(self::SHOP, [], 'en', 'EUR', 'de', ProductSort::TITLE));
    }

    public function testAProductTranslatedIntoSeveralLanguagesIsCountedOnce(): void
    {
        // Not: the join would otherwise multiply. It cannot — the
        // ON clause binds exactly one language, and the unique index forbids two
        // rows of the same language. What this test shows is simpler and
        // still worth nailing down: additional languages do not change the
        // count.
        $this->product(1, title: 'Apfel');
        $this->translate(1, 'en', alias: 'a', title: 'Apple');
        $this->translate(1, 'fr', alias: 'p', title: 'Pomme');

        self::assertSame(1, $this->query()->count(self::SHOP, [], 'en', 'EUR'));
    }

    public function testTwoTranslationsOfTheSameLanguageAreImpossible(): void
    {
        // The actual protection against multiplied rows. If it is not in the
        // test schema, the test suite checks a looser database than the real one —
        // and a danger that does not even exist.
        $this->product(1, title: 'Apfel');
        $this->translate(1, 'en', alias: 'a', title: 'Apple');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->translate(1, 'en', alias: 'b', title: 'Apple again');
    }

    public function testAProductWithoutATranslationIsNotFoundInThatLanguage(): void
    {
        $this->product(1, title: 'Apfel');

        self::assertSame([], $this->query()->find(self::SHOP, [], 'en', 'EUR', 'de'));
    }

    public function testTheMonolingualCaseIsUnchanged(): void
    {
        // The touchstone of the whole multilingualism: in the default language
        // exactly what came out before must come out.
        $this->product(1, title: 'Apfel');

        self::assertSame([1], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de'));
        self::assertSame(1, $this->query()->count(self::SHOP, [], 'de', 'EUR'));
    }

    // ── Pins (B-7) ───────────────────────────────────────────────────────

    public function testAnExcludedProductIsLeftOutOfFindAndCount(): void
    {
        // Rule 1: the pinned ones are on top — the rule after them without them,
        // otherwise they would appear twice. With paging, count() counts the same.
        $this->product(1);
        $this->product(2);
        $this->product(3);

        self::assertSame([1, 3], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de', excluded: [2]));
        self::assertSame(2, $this->query()->count(self::SHOP, [], 'de', 'EUR', excluded: [2]));
    }

    public function testNothingExcludedIsTheListingAsBefore(): void
    {
        $this->product(1);
        $this->product(2);

        self::assertSame([1, 2], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de', excluded: []));
    }

    public function testVisibleAmongKeepsTheDraggedOrderAndTheFirstOfTwoPins(): void
    {
        $this->product(1);
        $this->product(2);
        $this->product(3);

        self::assertSame([3, 1], $this->query()->visibleAmong([3, 1, 3], self::SHOP, [], 'de', 'EUR'));
    }

    public function testVisibleAmongDropsEverythingTheListingWouldNotShow(): void
    {
        // Rule 3: the pin beats the filter default, not visibility.
        $this->product(1);
        $this->product(2, published: false);
        $this->product(3, shop: 2);
        $this->product(4, priced: false);
        $this->product(5, priced: false);
        $this->variant(6, of: 5);

        self::assertSame([1], $this->query()->visibleAmong([2, 3, 4, 99, 6, 1], self::SHOP, [], 'de', 'EUR'));
    }

    public function testVisibleAmongAppliesOnlyTheFilterItIsGiven(): void
    {
        // The caller passes the CUSTOMER's filter, not the editor's
        // default: blue does not match "rot" and drops out.
        $this->product(1, properties: [self::RED]);
        $this->product(2, properties: [self::BLUE]);

        self::assertSame([1], $this->query()->visibleAmong([2, 1], self::SHOP, [1 => [self::RED]], 'de', 'EUR'));
        self::assertSame([2, 1], $this->query()->visibleAmong([2, 1], self::SHOP, [], 'de', 'EUR'));
    }

    public function testVisibleAmongWithoutPinsAsksNothing(): void
    {
        self::assertSame([], $this->query()->visibleAmong([], self::SHOP, [], 'de', 'EUR'));
    }

    // ── Variants in one language (B-8) ───────────────────────────────────

    public function testAVariantHiddenInTheLanguageDoesNotCountForItsOption(): void
    {
        /*
         * Variant 2 carries its own untranslated warning and is
         * invisible on fr (VisibilityCriteria, rule 3). The facet count
         * still counted it — the link led to zero hits.
         */
        $this->product(1, priced: false);
        $this->translate(1, 'fr', alias: 'produit-1');
        $this->variant(2, of: 1, options: [self::SIZE_M]);
        $this->connection->update('tl_consho_product', ['safetyWarnings' => 'Nicht für Kinder'], ['id' => 2]);
        $this->variant(3, of: 1, options: [self::SIZE_L]);

        $german = $this->query()->countsFor(self::SHOP, [], null, 'de', 'EUR');
        $french = $this->query()->countsFor(self::SHOP, [], null, 'fr', 'EUR');

        self::assertSame(1, $german[self::SIZE_M] ?? 0);
        self::assertArrayNotHasKey(self::SIZE_M, $french);
        self::assertSame(1, $french[self::SIZE_L] ?? 0);
    }

    public function testAFilterOnAVariantAxisIgnoresAVariantHiddenInTheLanguage(): void
    {
        /*
         * B-10: a group reads variant rows of the same shop AND the same
         * language. Variant 2 ("M") is invisible on fr (own untranslated
         * warning) — its row in de must not make the main product a hit in
         * fr.
         */
        $this->product(1, priced: false);
        $this->translate(1, 'fr', alias: 'produit-1');
        $this->variant(2, of: 1, options: [self::SIZE_M]);
        $this->connection->update('tl_consho_product', ['safetyWarnings' => 'Nicht für Kinder'], ['id' => 2]);
        $this->variant(3, of: 1, options: [self::SIZE_L]);

        self::assertSame([1], $this->query()->find(self::SHOP, [3 => [self::SIZE_M]], 'de', 'EUR', 'de'));
        self::assertSame([], $this->query()->find(self::SHOP, [3 => [self::SIZE_M]], 'fr', 'EUR', 'de'));
        self::assertSame([1], $this->query()->find(self::SHOP, [3 => [self::SIZE_L]], 'fr', 'EUR', 'de'));
    }

    public function testSortingByPriceInALanguageIgnoresAVariantHiddenThere(): void
    {
        $this->product(1, priced: false);
        $this->translate(1, 'fr', alias: 'produit-1');
        $this->variant(2, of: 1, gross: '5.0000');
        $this->connection->update('tl_consho_product', ['safetyWarnings' => 'Nicht für Kinder'], ['id' => 2]);
        $this->variant(3, of: 1, gross: '20.0000');
        $this->product(4, gross: '10.0000');
        $this->translate(4, 'fr', alias: 'produit-4');

        self::assertSame([1, 4], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de', ProductSort::PRICE_ASC));
        self::assertSame([4, 1], $this->query()->find(self::SHOP, [], 'fr', 'EUR', 'de', ProductSort::PRICE_ASC));
    }

    // ── Two currencies in one language ───────────────────────────────────

    public function testZweiWaehrungenInEinerSpracheLiefernJedesProduktEinmal(): void
    {
        // The DACH case: example.de in euros, example.ch in francs, both in
        // German. Since C-1c, task 5b, there are two rows per product for this in
        // the projection. If the list only asked for shop and language, it would
        // get both — every product twice.
        $this->insertRootPage(4, 'de', self::SHOP, 'CHF');
        $this->product(1);
        $this->price(1, '15.0000', 'CHF');

        self::assertSame([1], $this->find([]));
        self::assertSame(1, $this->query()->count(self::SHOP, [], 'de', 'EUR'));
        self::assertSame([1], $this->query()->visibleAmong([1], self::SHOP, [], 'de', 'EUR'));
    }

    public function testEinProduktOhneFrankenpreisFehltNurAufDerFrankenseite(): void
    {
        // The counter-check: without the currency in the key both pages would show
        // the same — either both products or none.
        $this->insertRootPage(4, 'de', self::SHOP, 'CHF');
        $this->product(1);
        $this->product(2);
        $this->price(2, '15.0000', 'CHF');

        self::assertSame([1, 2], $this->query()->find(self::SHOP, [], 'de', 'EUR', 'de'));
        self::assertSame([2], $this->query()->find(self::SHOP, [], 'de', 'CHF', 'de'));
    }

    public function testDieFilterzahlenGeltenJeWaehrung(): void
    {
        $this->insertRootPage(4, 'de', self::SHOP, 'CHF');
        $this->product(1, properties: [self::RED]);
        $this->product(2, properties: [self::RED]);
        $this->price(2, '15.0000', 'CHF');

        self::assertSame([self::RED => 2], $this->query()->countsFor(self::SHOP, [], null, 'de', 'EUR'));
        self::assertSame([self::RED => 1], $this->query()->countsFor(self::SHOP, [], null, 'de', 'CHF'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * The query over a projection built from the fixtures as they stand — at
     * NOW, the moment the tests used to pass to the query itself.
     */
    private function query(): ProductQuery
    {
        (new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        ))->rebuild(self::NOW);

        return new ProductQuery($this->connection);
    }

    /**
     * @param array<int, list<int>> $groups
     *
     * @return array<int, int>
     */
    private function counts(array $groups, int|null $without): array
    {
        return $this->query()->countsFor(self::SHOP, $groups, $without, 'de', 'EUR');
    }

    /**
     * @param array<int, list<int>> $groups
     *
     * @return list<int>
     */
    private function find(array $groups): array
    {
        return $this->query()->find(self::SHOP, $groups, 'de', 'EUR', 'de');
    }

    /**
     * @param list<int> $properties
     */
    private function product(int $id, array $properties = [], bool $published = true, bool $priced = true, int $shop = self::SHOP, string $title = '', int $sorting = 0, string $gross = '12.0000'): void
    {
        $this->connection->insert('tl_consho_product', [
            'id' => $id,
            'pid' => 0,
            'published' => $published ? 1 : 0,
            'title' => '' !== $title ? $title : 'Produkt '.$id,
            'sorting' => $sorting,
        ]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => $id, 'shop' => $shop]);

        foreach ($properties as $optionId) {
            $this->connection->insert('tl_consho_product_property', ['pid' => $id, 'optionId' => $optionId]);
        }

        if ($priced) {
            $this->price($id, $gross);
        }
    }

    /**
     * @param list<int> $options
     */
    private function variant(int $id, int $of, array $options = [], bool $published = true, bool $priced = true, string $gross = '12.0000'): void
    {
        $this->connection->insert('tl_consho_product', ['id' => $id, 'pid' => $of, 'published' => $published ? 1 : 0, 'title' => 'Variante '.$id]);

        foreach ($options as $optionId) {
            $this->connection->insert('tl_consho_product_option', ['pid' => $id, 'optionId' => $optionId]);
        }

        if ($priced) {
            $this->price($id, $gross);
        }
    }

    /**
     * Amount before currency — as in the sister files
     * DbalProductTileRepositoryTest::price() and
     * VisibilityProjectorTest::listPrice(). The order was swapped
     * here, and two test helpers of the same name in the same folder with
     * swapped parameters are a stumbling block with no benefit.
     */
    private function price(int $productId, string $gross = '12.0000', string $currency = 'EUR'): void
    {
        $this->connection->insert('tl_consho_product_list_price', [
            'pid' => $productId,
            'currency' => $currency,
            'country' => 'AT',
            'net' => number_format(((float) $gross) / 1.2, 4, '.', ''),
            'gross' => $gross,
        ]);
    }

    private function soldOut(int $article): void
    {
        $this->connection->insert('tl_consho_sold_out', ['article' => $article]);
    }

    private function translate(int $pid, string $language, ?string $alias, string $title = ''): void
    {
        $this->connection->insert('tl_consho_translation', [
            'pid' => $pid,
            'ptable' => 'tl_consho_product',
            'language' => $language,
            'alias' => $alias,
            'title' => $title,
        ]);
    }
}

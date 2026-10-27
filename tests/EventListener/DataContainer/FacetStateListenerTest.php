<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterResolver;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingPageRepository;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\FacetStateListener;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Against SQLite instead of mocks: FilterResolver and ListingPageRepository
 * are final and run real queries — exactly those are to be checked here too.
 */
class FacetStateListenerTest extends TestCase
{
    private const PAGE = 12;

    private Connection $connection;

    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['errors'] = [
            'facetStateEmpty' => 'empty: %s',
            'facetStateSorted' => 'sorted: %s',
            'facetStateAvailable' => 'available: %s',
            'facetStateDuplicate' => 'duplicate: %s',
            'facetStateUnknown' => 'unknown: %s',
            'facetStateOutsideCategory' => 'outside: %s',
            'facetPageWithoutListing' => 'without listing: %s',
            'facetStateMatchesCategory' => 'matches category: %s',
        ];

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Properties as in FilterResolverTest.
        DcaSchema::create($this->connection, 'tl_consho_property_group');
        DcaSchema::create($this->connection, 'tl_consho_property_option');

        // Articles and elements as in ListingPageRepositoryTest.
        $this->connection->executeStatement(
            "CREATE TABLE tl_article (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                sorting INTEGER NOT NULL DEFAULT 0,
                published INTEGER NOT NULL DEFAULT 0,
                start VARCHAR(10) NOT NULL DEFAULT '',
                stop VARCHAR(10) NOT NULL DEFAULT ''
            )",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_content (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                ptable VARCHAR(64) NOT NULL DEFAULT 'tl_article',
                sorting INTEGER NOT NULL DEFAULT 0,
                tstamp INTEGER NOT NULL DEFAULT 1,
                type VARCHAR(64) NOT NULL DEFAULT 'text',
                invisible INTEGER NOT NULL DEFAULT 0,
                start VARCHAR(10) NOT NULL DEFAULT '',
                stop VARCHAR(10) NOT NULL DEFAULT '',
                conshoFilter BLOB NULL,
                conshoShowCounts INTEGER NOT NULL DEFAULT 1,
                conshoFollowsAddress INTEGER NOT NULL DEFAULT 1
            )",
        );

        // From the DCA, so as strict as it is: both UNIQUE indexes hang on pid.
        DcaSchema::create($this->connection, 'tl_consho_facet_page');

        $this->group(1, 'colour');
        $this->option(11, 1, 'red');
        $this->option(12, 1, 'blue');

        $this->group(2, 'volume');
        $this->option(21, 2, '250ml');
        $this->option(22, 2, '750ml');

        $this->group(3, 'intern', filterable: false);
        $this->option(31, 3, 'ja');

        // Page 12: category list without a preset.
        $this->listingOn(self::PAGE, article: 100, element: 636);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testKeepsACombinationThatIsAlreadyNormalised(): void
    {
        self::assertSame('colour:red;volume:250ml', $this->save('colour:red;volume:250ml'));
    }

    public function testNormalisesTheOrderOfGroupsAndOptions(): void
    {
        // ⚠️ This is what makes the unique index work. Stored as typed, the same
        // combination could be released twice in two spellings — two indexed
        // pages with identical content, the very thing this table prevents.
        self::assertSame('colour:red;volume:250ml,750ml', $this->save('volume:750ml,250ml;colour:red'));
    }

    public function testDropsWhitespaceTheEditorTyped(): void
    {
        self::assertSame('colour:red;volume:250ml', $this->save('  volume:250ml ; colour:red  '));
    }

    public function testDropsAnOptionTypedTwice(): void
    {
        // Saved twice it would give "colour:red,red" — a URL that the filter
        // controls never produce. The landing page would be unreachable.
        self::assertSame('colour:red', $this->save('colour:red,red'));
    }

    public function testRefusesACombinationThatSurvivesAsNothing(): void
    {
        // Refusing beats storing an empty string: a landing page that matches
        // no filter state would never be reached, and nobody would find out why.
        $this->expectExceptionMessage('empty: Rot & Blau');

        $this->save('Rot & Blau');
    }

    public function testRefusesAnEmptySubmission(): void
    {
        $this->expectExceptionMessage('empty: ');

        $this->save('');
    }

    public function testRefusesASortOrder(): void
    {
        // A sort order produces the same products in another sequence — always
        // duplicate content, never worth its own indexed address.
        $this->expectExceptionMessage('sorted: volume:250ml;sort_by:price_asc');

        $this->save('volume:250ml;sort_by:price_asc');
    }

    public function testRefusesAvailableOnly(): void
    {
        // Block Verfügbarkeit, V5: a view by stock, never a landing page —
        // said, not silently left out.
        $this->expectExceptionMessage('available: volume:250ml;only_available:1');

        $this->save('volume:250ml;only_available:1');
    }

    // ── Against the properties ───────────────────────────────────────────

    public function testRefusesAnUnknownOption(): void
    {
        // Silently discarded, the landing page would show the whole category
        // under its own title.
        $this->expectExceptionMessage('unknown: volume:999ml');

        $this->save('volume:999ml');
    }

    public function testRefusesACombinationOfWhichOnlyAPartExists(): void
    {
        $this->expectExceptionMessage('unknown: colour:red;volume:999ml');

        $this->save('colour:red;volume:999ml');
    }

    public function testRefusesAGroupThatIsNotFilterable(): void
    {
        // The visitor could never choose it — FilterResolver discards it in
        // the front end too, so the landing page would show the whole category.
        $this->expectExceptionMessage('unknown: intern:ja');

        $this->save('intern:ja');
    }

    // ── Against the list page ────────────────────────────────────────────

    public function testRefusesAPageWithoutAFollowingListing(): void
    {
        $this->expectExceptionMessage('without listing: 13');

        $this->save('volume:250ml', pid: 13);
    }

    public function testAnOldRecordStillPointingAtAStartPointGetsASentenceInsteadOfACrash(): void
    {
        /*
         * Before B-4, pid pointed to the shop. Such a legacy record silently
         * points to the page with the same ID after the rebuild — say shop 1
         * → page 1, a root page without articles. On save Contao reports
         * "mandatory field" for pid (page 1 is not among the options), and
         * getActiveRecord() then keeps delivering the saved 1.
         */
        $this->expectExceptionMessage('without listing: 1');

        $this->save('colour:red;volume:250ml,750ml', pid: 1);
    }

    public function testDoesNotAskForAListingBeforeAPageIsChosen(): void
    {
        // pid is mandatory and reports itself. A second message "page 0 has
        // no list" would help nobody.
        self::assertSame('volume:250ml', $this->save('volume:250ml', pid: 0));
    }

    public function testRefusesACombinationOutsideTheCategory(): void
    {
        $this->listingOn(20, article: 200, element: 700, preset: [11]);

        $this->expectExceptionMessage('outside: colour:blue');

        $this->save('colour:blue', pid: 20);
    }

    public function testKeepsACombinationWithinTheCategory(): void
    {
        $this->listingOn(20, article: 200, element: 700, preset: [11, 12]);

        self::assertSame('colour:blue', $this->save('colour:blue', pid: 20));
    }

    public function testKeepsACombinationInAGroupThePresetDoesNotTouch(): void
    {
        $this->listingOn(20, article: 200, element: 700, preset: [11]);

        self::assertSame('volume:250ml', $this->save('volume:250ml', pid: 20));
    }

    public function testKeepsACombinationWithinAPresetOverTwoGroups(): void
    {
        // The preset narrows down in BOTH groups; the combination stays inside
        // in both — not just in a single group as above.
        $this->listingOn(20, article: 200, element: 700, preset: [11, 12, 21, 22]);

        self::assertSame('colour:red;volume:250ml', $this->save('colour:red;volume:250ml', pid: 20));
    }

    public function testRefusesACombinationTouchingBothGroupsOfAPresetButOutsideOne(): void
    {
        // The same two-group preset as above — the combination touches both
        // groups but lies outside in "volume" (1000ml is a known, filterable
        // option, just not part of the preset).
        $this->option(23, 2, '1000ml');
        $this->listingOn(20, article: 200, element: 700, preset: [11, 12, 21, 22]);

        $this->expectExceptionMessage('outside: colour:red;volume:1000ml');

        $this->save('colour:red;volume:1000ml', pid: 20);
    }

    // ── Congruent with the category ──────────────────────────────────────

    public function testRefusesACombinationThatMatchesTheCategoryPresetExactly(): void
    {
        // The same products as the category itself — a landing page for it
        // would have the same content.
        $this->listingOn(20, article: 200, element: 700, preset: [11, 21]);

        $this->expectExceptionMessage('matches category: colour:red;volume:250ml');

        $this->save('colour:red;volume:250ml', pid: 20);
    }

    public function testRefusesTheSameCategoryPresetTypedInAnotherOrder(): void
    {
        // The comparison runs on the normalised state (codec) — the order in
        // which preset and input name the groups is independent of each other
        // and must not matter.
        $this->listingOn(20, article: 200, element: 700, preset: [21, 11]);

        $this->expectExceptionMessage('matches category: colour:red;volume:250ml');

        $this->save('volume:250ml;colour:red', pid: 20);
    }

    public function testKeepsACombinationNarrowerThanTheCategoryPreset(): void
    {
        // Narrower than the preset: one of two options of the preset group.
        $this->listingOn(20, article: 200, element: 700, preset: [11, 12]);

        self::assertSame('colour:red', $this->save('colour:red', pid: 20));
    }

    public function testRefusesAMatchWhenThePresetAlsoUsesAGroupNobodyCanFilterBy(): void
    {
        // Preset "colour red" plus the non-filterable group "internal: yes" —
        // the case from FilterResolver::group() ("promotion"). Only the
        // filterable part can be typed. It shows the same products as the
        // category, because "internal: yes" always applies anyway.
        $this->listingOn(20, article: 200, element: 700, preset: [11, 31]);

        $this->expectExceptionMessage('matches category: colour:red');

        $this->save('colour:red', pid: 20);
    }

    public function testKeepsANarrowerCombinationWhenThePresetAlsoUsesAGroupNobodyCanFilterBy(): void
    {
        $this->listingOn(20, article: 200, element: 700, preset: [11, 12, 31]);

        self::assertSame('colour:red', $this->save('colour:red', pid: 20));
    }

    public function testDoesNotCompareAgainstACategoryWithoutAPreset(): void
    {
        // Edge case: page 12 (see setUp) has no preset — nothing to compare,
        // the combination stays allowed.
        self::assertSame('colour:red;volume:250ml', $this->save('colour:red;volume:250ml'));
    }

    // ── Duplicates ───────────────────────────────────────────────────────

    public function testRefusesACombinationAnotherLandingPageOnThePageAlreadyHolds(): void
    {
        // Without this the unique index answers instead — with a 500 and a raw
        // SQL message. The normalisation makes the collision happen even when
        // the second editor typed the groups in another order, which is exactly
        // the case that needs a sentence rather than a stack trace.
        $this->facet(id: 3, page: self::PAGE, state: 'colour:red;volume:250ml', alias: 'rote-farben-250-ml');

        $this->expectExceptionMessage('duplicate: rote-farben-250-ml');

        $this->save('volume:250ml;colour:red');
    }

    public function testKeepsTheSameCombinationOnAnotherPage(): void
    {
        // Two categories, two sets — the approval belongs to the page.
        $this->listingOn(20, article: 200, element: 700);
        $this->facet(id: 3, page: 20, state: 'volume:250ml', alias: 'schwedenbitter-250-ml');

        self::assertSame('volume:250ml', $this->save('volume:250ml'));
    }

    public function testARecordDoesNotCollideWithItself(): void
    {
        $this->facet(id: 7, page: self::PAGE, state: 'volume:250ml', alias: 'schwedenbitter-250-ml');

        self::assertSame('volume:250ml', $this->save('volume:250ml', id: 7));
    }

    private function save(string $value, int $pid = self::PAGE, int $id = 7): string
    {
        $listener = new FacetStateListener(
            new FilterStateCodec(),
            $this->connection,
            new FilterResolver($this->connection),
            new ListingPageRepository($this->connection),
        );

        return $listener($value, $this->dc($id, $pid));
    }

    private function dc(int $id, int $pid): DC_Table
    {
        // getActiveRecord(), because on a new record the page the editor picked
        // exists only in the submitted values, not in the stored row.
        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnMap([['id', $id]]);
        $dc->method('getActiveRecord')->willReturn(['id' => $id, 'pid' => $pid]);

        return $dc;
    }

    private function group(int $id, string $alias, bool $filterable = true): void
    {
        $this->connection->insert('tl_consho_property_group', ['id' => $id, 'alias' => $alias, 'filterable' => (int) $filterable]);
    }

    private function option(int $id, int $group, string $alias): void
    {
        $this->connection->insert('tl_consho_property_option', ['id' => $id, 'pid' => $group, 'alias' => $alias]);
    }

    /**
     * @param list<int> $preset
     */
    private function listingOn(int $page, int $article, int $element, array $preset = []): void
    {
        $this->connection->insert('tl_article', ['id' => $article, 'pid' => $page, 'published' => 1]);
        $this->connection->insert('tl_content', [
            'id' => $element,
            'pid' => $article,
            'type' => 'consho_product_list',
            'conshoFilter' => [] === $preset ? null : serialize(array_map(strval(...), $preset)),
        ]);
    }

    private function facet(int $id, int $page, string $state, string $alias): void
    {
        $this->connection->insert('tl_consho_facet_page', [
            'id' => $id, 'pid' => $page, 'filterState' => $state, 'alias' => $alias, 'pageTitle' => 'Titel',
        ]);
    }
}

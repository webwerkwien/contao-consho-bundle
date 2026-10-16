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
 * Gegen SQLite statt gegen Mocks: FilterResolver und ListingPageRepository sind
 * final und stellen echte Abfragen — genau die sollen hier mitgeprüft werden.
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

        // Eigenschaften wie in FilterResolverTest.
        DcaSchema::create($this->connection, 'tl_consho_property_group');
        DcaSchema::create($this->connection, 'tl_consho_property_option');

        // Artikel und Elemente wie in ListingPageRepositoryTest.
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

        // Aus der DCA, also so streng wie sie: beide UNIQUE-Indizes hängen an pid.
        DcaSchema::create($this->connection, 'tl_consho_facet_page');

        $this->group(1, 'colour');
        $this->option(11, 1, 'red');
        $this->option(12, 1, 'blue');

        $this->group(2, 'volume');
        $this->option(21, 2, '250ml');
        $this->option(22, 2, '750ml');

        $this->group(3, 'intern', filterable: false);
        $this->option(31, 3, 'ja');

        // Seite 12: Kategorieliste ohne Vorgabe.
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
        // Doppelt gespeichert ergäbe „colour:red,red" — eine Adresse, die die
        // Filterbedienung nie erzeugt. Die Landingpage wäre unerreichbar.
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
        // Block Verfügbarkeit, V5: eine Sicht nach Lager, nie eine Landingpage —
        // gesagt, nicht still weggelassen.
        $this->expectExceptionMessage('available: volume:250ml;only_available:1');

        $this->save('volume:250ml;only_available:1');
    }

    // ── Gegen die Eigenschaften ──────────────────────────────────────────

    public function testRefusesAnUnknownOption(): void
    {
        // Stillschweigend verworfen zeigte die Landingpage unter eigenem Titel
        // die ganze Kategorie.
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
        // Der Besucher könnte sie nie wählen — FilterResolver verwirft sie auch
        // vorne, die Landingpage zeigte also die ganze Kategorie.
        $this->expectExceptionMessage('unknown: intern:ja');

        $this->save('intern:ja');
    }

    // ── Gegen die Listenseite ────────────────────────────────────────────

    public function testRefusesAPageWithoutAFollowingListing(): void
    {
        $this->expectExceptionMessage('without listing: 13');

        $this->save('volume:250ml', pid: 13);
    }

    public function testAnOldRecordStillPointingAtAStartPointGetsASentenceInsteadOfACrash(): void
    {
        /*
         * Vor B-4 zeigte pid auf den Shop. Ein solcher Altdatensatz zeigt nach
         * dem Umbau still auf die Seite mit derselben ID — auf dem Testserver
         * Shop 1 → Seite 1, ein Startpunkt ohne Artikel. Beim Speichern meldet
         * Contao für pid „Pflichtfeld" (Seite 1 steht nicht in der Auswahl),
         * und getActiveRecord() liefert dann weiter die gespeicherte 1.
         */
        $this->expectExceptionMessage('without listing: 1');

        $this->save('colour:red;volume:250ml,750ml', pid: 1);
    }

    public function testDoesNotAskForAListingBeforeAPageIsChosen(): void
    {
        // pid ist Pflichtfeld und meldet sich selbst. Eine zweite Meldung
        // „Seite 0 hat keine Liste" hülfe niemandem.
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
        // Die Vorgabe grenzt in BEIDEN Gruppen ein; die Kombination bleibt in
        // beiden innerhalb — nicht nur die einer einzelnen Gruppe wie oben.
        $this->listingOn(20, article: 200, element: 700, preset: [11, 12, 21, 22]);

        self::assertSame('colour:red;volume:250ml', $this->save('colour:red;volume:250ml', pid: 20));
    }

    public function testRefusesACombinationTouchingBothGroupsOfAPresetButOutsideOne(): void
    {
        // Dieselbe zweigruppige Vorgabe wie oben — die Kombination berührt
        // beide Gruppen, liegt aber in „volume" außerhalb (1000ml ist zwar
        // eine bekannte, filterbare Option, nur nicht Teil der Vorgabe).
        $this->option(23, 2, '1000ml');
        $this->listingOn(20, article: 200, element: 700, preset: [11, 12, 21, 22]);

        $this->expectExceptionMessage('outside: colour:red;volume:1000ml');

        $this->save('colour:red;volume:1000ml', pid: 20);
    }

    // ── Deckungsgleich mit der Kategorie (Entscheidung 2026-09-13) ─────────

    public function testRefusesACombinationThatMatchesTheCategoryPresetExactly(): void
    {
        // Dieselben Produkte wie die Kategorie selbst — eine Landingpage
        // dafür hätte denselben Inhalt.
        $this->listingOn(20, article: 200, element: 700, preset: [11, 21]);

        $this->expectExceptionMessage('matches category: colour:red;volume:250ml');

        $this->save('colour:red;volume:250ml', pid: 20);
    }

    public function testRefusesTheSameCategoryPresetTypedInAnotherOrder(): void
    {
        // Der Vergleich läuft über den normalisierten Zustand (Codec) — die
        // Reihenfolge, in der Vorgabe und Eingabe die Gruppen nennen, ist
        // unabhängig voneinander und darf keine Rolle spielen.
        $this->listingOn(20, article: 200, element: 700, preset: [21, 11]);

        $this->expectExceptionMessage('matches category: colour:red;volume:250ml');

        $this->save('volume:250ml;colour:red', pid: 20);
    }

    public function testKeepsACombinationNarrowerThanTheCategoryPreset(): void
    {
        // Enger als die Vorgabe: eine von zwei Optionen der Vorgabe-Gruppe.
        $this->listingOn(20, article: 200, element: 700, preset: [11, 12]);

        self::assertSame('colour:red', $this->save('colour:red', pid: 20));
    }

    public function testRefusesAMatchWhenThePresetAlsoUsesAGroupNobodyCanFilterBy(): void
    {
        // Vorgabe „Farbe rot" plus die nicht filterbare Gruppe „intern: ja" —
        // der Fall aus FilterResolver::group() („Aktion"). Getippt werden kann
        // nur der filterbare Teil. Er zeigt dieselben Produkte wie die
        // Kategorie, weil „intern: ja" ohnehin immer gilt.
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
        // Randfall: Seite 12 (siehe setUp) hat keine Vorgabe — nichts zu
        // vergleichen, die Kombination bleibt erlaubt.
        self::assertSame('colour:red;volume:250ml', $this->save('colour:red;volume:250ml'));
    }

    // ── Dubletten ────────────────────────────────────────────────────────

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
        // Zwei Kategorien, zwei Mengen — die Freigabe gehört der Seite.
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

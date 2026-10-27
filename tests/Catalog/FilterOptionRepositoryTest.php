<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterGroupView;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterOptionRepository;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Against a real database — the class is an SQL query, and an
 * assertion about the query text would prove nothing about its result.
 */
class FilterOptionRepositoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Built from contao/dca/tl_consho_property_group.php and
        // tl_consho_property_option.php, not copied.
        DcaSchema::create($this->connection, 'tl_consho_property_group');
        DcaSchema::create($this->connection, 'tl_consho_property_option');

        // Only translations() creates tl_consho_translation: the test for the
        // default language deliberately runs WITHOUT this table.
    }

    // ── Default language ─────────────────────────────────────────────────

    public function testInTheDefaultLanguageNoJoinIsBuilt(): void
    {
        // 🔴 The table tl_consho_translation does not exist here. If a join were
        // created, the query would abort with "no such table" — so the test proves
        // that a single-language shop gets exactly the query without translation.
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');

        foreach ([['de', 'de'], ['', '']] as [$language, $fallback]) {
            $groups = $this->repository()->forGroups([], $language, $fallback);

            self::assertSame('Farbe', $groups[0]->label);
            self::assertSame('Rot', $groups[0]->options[0]->label);
        }
    }

    // ── Translated labels ────────────────────────────────────────────────

    public function testTheOptionLabelIsTranslatedButItsAliasIsNot(): void
    {
        // The core of multilingualism 6.1: cq stays the same in every language,
        // only the display changes.
        //
        // The translation row deliberately carries an alias of its own here. M-4
        // stores none for properties, but an old or hand-made
        // row can have one — and only with it does it show if the
        // alias ran through coalesce(). With NULL the same value would come out.
        $this->translations();
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'rot');
        $this->translation('tl_consho_property_option', 11, 'en', 'Red', alias: 'red');

        $option = $this->english()[0]->options[0];

        self::assertSame('Red', $option->label);
        self::assertSame('rot', $option->alias);
    }

    public function testTheGroupLabelIsTranslatedButItsAliasIsNot(): void
    {
        $this->translations();
        $this->group(1, 'Farbe', 'farbe');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');
        $this->translation('tl_consho_property_group', 1, 'en', 'Colour', alias: 'colour');

        $group = $this->english()[0];

        self::assertSame('Colour', $group->label);
        self::assertSame('farbe', $group->alias);
    }

    public function testWithoutATranslationTheDefaultLanguageLabelStays(): void
    {
        $this->translations();
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');

        $groups = $this->english();

        self::assertSame('Farbe', $groups[0]->label);
        self::assertSame('Rot', $groups[0]->options[0]->label);
    }

    public function testATranslationRowWithAnEmptyTitleFallsBack(): void
    {
        // A translation row with an empty title is no translation — without
        // NULLIF, COALESCE would take the empty string and the label would
        // disappear.
        $this->translations();
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');
        $this->translation('tl_consho_property_option', 11, 'en', '');
        $this->translation('tl_consho_property_group', 1, 'en', '');

        $groups = $this->english();

        self::assertSame('Farbe', $groups[0]->label);
        self::assertSame('Rot', $groups[0]->options[0]->label);
    }

    public function testATranslationOfAnotherTableWithTheSameIdDoesNotApply(): void
    {
        // Group 1 and option 1 share the ID. Without ptable in the join the
        // group would carry the option's title or the other way round.
        $this->translations();
        $this->group(1, 'Farbe', 'colour');
        $this->option(1, group: 1, title: 'Rot', alias: 'red');
        $this->translation('tl_consho_property_option', 1, 'en', 'Red');

        $groups = $this->english();

        self::assertSame('Farbe', $groups[0]->label);
        self::assertSame('Red', $groups[0]->options[0]->label);
    }

    public function testATranslationInAnotherLanguageDoesNotApply(): void
    {
        $this->translations();
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');
        $this->translation('tl_consho_property_option', 11, 'fr', 'Rouge');

        self::assertSame('Rot', $this->english()[0]->options[0]->label);
    }

    // ── What is missing ──────────────────────────────────────────────────

    public function testAGroupThatIsNotFilterableIsLeftOut(): void
    {
        // Even with an explicit selection: the element can name a group
        // that the editor later set to not filterable. A link
        // to it would be silently discarded on click (FilterResolver::resolve()).
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');
        $this->group(2, 'Interne Markierung', 'intern', filterable: false);
        $this->option(21, group: 2, title: 'Lager', alias: 'lager');

        self::assertSame([1], $this->ids($this->repository()->forGroups([])));
        self::assertSame([1], $this->ids($this->repository()->forGroups([2, 1])));
    }

    public function testAnOptionWithoutAliasIsLeftOut(): void
    {
        // It could never appear in cq.
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');
        $this->option(12, group: 1, title: 'Unfertig', alias: '');

        $options = $this->repository()->forGroups([])[0]->options;

        self::assertSame([11], array_map(static fn ($o) => $o->id, $options));
    }

    public function testAGroupWithoutAliasIsLeftOut(): void
    {
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');
        $this->group(2, 'Unfertig', '');
        $this->option(21, group: 2, title: 'Klein', alias: 'klein');

        self::assertSame([1], $this->ids($this->repository()->forGroups([])));
    }

    public function testAnOptionWhoseAliasTheAddressCannotCarryIsLeftOut(): void
    {
        // Contao's alias check allows upper case,
        // umlauts, dot and underscore; FilterStateCodec::decode() silently
        // discards them. A link to "0,5 l" would thus lead to a different state
        // than shown — usually back to the unfiltered list.
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');
        $this->option(12, group: 1, title: 'Blau', alias: 'Blau');
        $this->option(13, group: 1, title: 'Grün', alias: 'grün');
        $this->option(14, group: 1, title: '0,5 l', alias: '0.5l');
        $this->option(15, group: 1, title: 'Hell_Rot', alias: 'hell_rot');
        $this->option(16, group: 1, title: 'Dunkelrot', alias: 'dunkel-rot-2');

        $options = $this->repository()->forGroups([])[0]->options;

        self::assertSame([11, 16], array_map(static fn ($o) => $o->id, $options));
    }

    public function testAGroupWhoseAliasTheAddressCannotCarryIsLeftOut(): void
    {
        // Even with an explicit selection — the codec discards the group key
        // just like the value.
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');
        $this->group(2, 'Füllmenge', 'füllmenge');
        $this->option(21, group: 2, title: '250 ml', alias: '250ml');
        $this->group(3, 'Größe', 'Size');
        $this->option(31, group: 3, title: 'M', alias: 'm');

        self::assertSame([1], $this->ids($this->repository()->forGroups([])));
        self::assertSame([1], $this->ids($this->repository()->forGroups([3, 2, 1])));
    }

    // ── Selection and order ──────────────────────────────────────────────

    public function testASelectionReturnsOnlyThoseGroupsInTheGivenOrder(): void
    {
        // The order of the selection is the editor's, not the alphabet's.
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');
        $this->group(2, 'Volumen', 'volume');
        $this->option(21, group: 2, title: '250 ml', alias: '250ml');
        $this->group(3, 'Größe', 'size');
        $this->option(31, group: 3, title: 'M', alias: 'm');

        self::assertSame([2], $this->ids($this->repository()->forGroups([2])));
        self::assertSame([3, 1], $this->ids($this->repository()->forGroups([3, 1])));
    }

    public function testWithoutASelectionEveryFilterableGroupComesByLabel(): void
    {
        $this->group(1, 'Volumen', 'volume');
        $this->option(11, group: 1, title: '250 ml', alias: '250ml');
        $this->group(2, 'Farbe', 'colour');
        $this->option(21, group: 2, title: 'Rot', alias: 'red');
        $this->group(3, 'Größe', 'size');
        $this->option(31, group: 3, title: 'M', alias: 'm');

        self::assertSame([2, 3, 1], $this->ids($this->repository()->forGroups([])));
    }

    public function testWithoutASelectionTheOrderFollowsTheTranslatedLabel(): void
    {
        // What the visitor reads is sorted: "Colour" before "Size" before "Volume",
        // although the German titles are ordered differently.
        $this->translations();
        $this->group(1, 'Volumen', 'volume');
        $this->option(11, group: 1, title: '250 ml', alias: '250ml');
        $this->group(2, 'Größe', 'size');
        $this->option(21, group: 2, title: 'M', alias: 'm');
        $this->group(3, 'Farbe', 'colour');
        $this->option(31, group: 3, title: 'Rot', alias: 'red');
        $this->translation('tl_consho_property_group', 1, 'en', 'Volume');
        $this->translation('tl_consho_property_group', 2, 'en', 'Size');
        $this->translation('tl_consho_property_group', 3, 'en', 'Colour');

        self::assertSame([3, 2, 1], $this->ids($this->english()));
    }

    public function testOptionsComeBySortingThenId(): void
    {
        // sorting collides after pasting from the clipboard — then
        // id decides, not the mood of the database.
        $this->group(1, 'Farbe', 'colour');
        $this->option(14, group: 1, title: 'Grün', alias: 'green', sorting: 64);
        $this->option(12, group: 1, title: 'Blau', alias: 'blue', sorting: 128);
        $this->option(13, group: 1, title: 'Gelb', alias: 'yellow', sorting: 32);
        $this->option(11, group: 1, title: 'Rot', alias: 'red', sorting: 64);

        $options = $this->repository()->forGroups([])[0]->options;

        self::assertSame([13, 11, 14, 12], array_map(static fn ($o) => $o->id, $options));
    }

    public function testNothingToOfferIsAnEmptyList(): void
    {
        self::assertSame([], $this->repository()->forGroups([]));
        self::assertSame([], $this->repository()->forGroups([7]));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @return list<FilterGroupView>
     */
    private function english(): array
    {
        return $this->repository()->forGroups([], 'en', 'de');
    }

    /**
     * @param list<FilterGroupView> $groups
     *
     * @return list<int>
     */
    private function ids(array $groups): array
    {
        return array_map(static fn (FilterGroupView $g) => $g->id, $groups);
    }

    private function repository(): FilterOptionRepository
    {
        return new FilterOptionRepository($this->connection);
    }

    private function translations(): void
    {
        // From contao/dca/tl_consho_translation.php: alias and the text fields
        // are `notnull => false` there, title NOT NULL DEFAULT ''.
        DcaSchema::create($this->connection, 'tl_consho_translation');
    }

    private function group(int $id, string $title, string $alias, bool $filterable = true): void
    {
        $this->connection->insert('tl_consho_property_group', [
            'id' => $id,
            'title' => $title,
            'alias' => $alias,
            'filterable' => $filterable ? 1 : 0,
        ]);
    }

    private function option(int $id, int $group, string $title, string $alias, int $sorting = 0): void
    {
        $this->connection->insert('tl_consho_property_option', [
            'id' => $id,
            'pid' => $group,
            'sorting' => $sorting,
            'title' => $title,
            'alias' => $alias,
        ]);
    }

    private function translation(string $ptable, int $pid, string $language, string $title, ?string $alias = null): void
    {
        // Properties have no translated alias (multilingualism 6.1) —
        // it stays NULL, as M-4 stores it. Only the alias tests set one.
        $this->connection->insert('tl_consho_translation', [
            'ptable' => $ptable,
            'pid' => $pid,
            'language' => $language,
            'title' => $title,
            'alias' => $alias,
        ]);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterGroupView;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterOptionRepository;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Gegen eine echte Datenbank — die Klasse ist eine SQL-Abfrage, und eine
 * Zusicherung über den Abfragetext bewiese nichts über ihr Ergebnis.
 */
class FilterOptionRepositoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Aus contao/dca/tl_consho_property_group.php und
        // tl_consho_property_option.php gebaut, nicht abgeschrieben.
        DcaSchema::create($this->connection, 'tl_consho_property_group');
        DcaSchema::create($this->connection, 'tl_consho_property_option');

        // tl_consho_translation legt erst translations() an: Der Test für die
        // Standardsprache läuft bewusst OHNE diese Tabelle.
    }

    // ── Standardsprache ──────────────────────────────────────────────────

    public function testInTheDefaultLanguageNoJoinIsBuilt(): void
    {
        // 🔴 Die Tabelle tl_consho_translation existiert hier nicht. Entstünde ein
        // Join, bräche die Abfrage mit „no such table" ab — der Test belegt also,
        // dass ein einsprachiger Shop exakt die Abfrage ohne Übersetzung bekommt.
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');

        foreach ([['de', 'de'], ['', '']] as [$language, $fallback]) {
            $groups = $this->repository()->forGroups([], $language, $fallback);

            self::assertSame('Farbe', $groups[0]->label);
            self::assertSame('Rot', $groups[0]->options[0]->label);
        }
    }

    // ── Übersetzte Beschriftungen ────────────────────────────────────────

    public function testTheOptionLabelIsTranslatedButItsAliasIsNot(): void
    {
        // Der Kern von Mehrsprachigkeit 6.1: cq bleibt in jeder Sprache gleich,
        // nur die Anzeige wechselt.
        //
        // Die Übersetzungszeile trägt hier bewusst einen eigenen Alias. M-4
        // speichert für Eigenschaften keinen, aber eine alte oder von Hand
        // angelegte Zeile kann einen haben — und nur mit ihm fällt auf, wenn der
        // Alias durch coalesce() liefe. Mit NULL käme derselbe Wert heraus.
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
        // Eine Übersetzungszeile mit leerem Titel ist keine Übersetzung — ohne
        // NULLIF nähme COALESCE die leere Zeichenkette und die Beschriftung
        // verschwände.
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
        // Gruppe 1 und Option 1 teilen die ID. Ohne ptable im Join trüge die
        // Gruppe den Titel der Option oder umgekehrt.
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

    // ── Was fehlt ────────────────────────────────────────────────────────

    public function testAGroupThatIsNotFilterableIsLeftOut(): void
    {
        // Auch bei ausdrücklicher Auswahl: Das Element kann eine Gruppe nennen,
        // die der Redakteur später auf nicht filterbar gestellt hat. Ein Link
        // darauf würde beim Klicken still verworfen (FilterResolver::resolve()).
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');
        $this->group(2, 'Interne Markierung', 'intern', filterable: false);
        $this->option(21, group: 2, title: 'Lager', alias: 'lager');

        self::assertSame([1], $this->ids($this->repository()->forGroups([])));
        self::assertSame([1], $this->ids($this->repository()->forGroups([2, 1])));
    }

    public function testAnOptionWithoutAliasIsLeftOut(): void
    {
        // Sie könnte nie in cq stehen.
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
        // Übergabe aus Task 5 und 8. Contaos Alias-Prüfung lässt Großbuchstaben,
        // Umlaute, Punkt und Unterstrich zu, FilterStateCodec::decode() verwirft
        // sie still. Ein Link auf „0,5 l" führte also auf einen anderen Zustand
        // als angezeigt — meist zurück auf die ungefilterte Liste.
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
        // Auch bei ausdrücklicher Auswahl — der Codec verwirft den Gruppenschlüssel
        // genauso wie den Wert.
        $this->group(1, 'Farbe', 'colour');
        $this->option(11, group: 1, title: 'Rot', alias: 'red');
        $this->group(2, 'Füllmenge', 'füllmenge');
        $this->option(21, group: 2, title: '250 ml', alias: '250ml');
        $this->group(3, 'Größe', 'Size');
        $this->option(31, group: 3, title: 'M', alias: 'm');

        self::assertSame([1], $this->ids($this->repository()->forGroups([])));
        self::assertSame([1], $this->ids($this->repository()->forGroups([3, 2, 1])));
    }

    // ── Auswahl und Reihenfolge ──────────────────────────────────────────

    public function testASelectionReturnsOnlyThoseGroupsInTheGivenOrder(): void
    {
        // Die Reihenfolge der Auswahl ist die des Redakteurs, nicht das Alphabet.
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
        // Sortiert wird, was der Besucher liest: „Colour" vor „Size" vor „Volume",
        // obwohl die deutschen Titel anders stehen.
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
        // sorting kollidiert nach dem Einfügen aus der Zwischenablage — dann
        // entscheidet id, nicht die Laune der Datenbank.
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

    // ── Hilfen ───────────────────────────────────────────────────────────

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
        // Aus contao/dca/tl_consho_translation.php: alias und die Textfelder
        // sind dort `notnull => false`, title NOT NULL DEFAULT ''.
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
        // Eigenschaften haben keinen übersetzten Alias (Mehrsprachigkeit 6.1) —
        // er bleibt NULL, wie ihn M-4 speichert. Nur die Alias-Tests setzen einen.
        $this->connection->insert('tl_consho_translation', [
            'ptable' => $ptable,
            'pid' => $pid,
            'language' => $language,
            'title' => $title,
            'alias' => $alias,
        ]);
    }
}

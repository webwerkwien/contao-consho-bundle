<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Die Kundengruppen mit Nettopreisen am Shop (C20, C-4).
 *
 * ⚠️ Warum es diesen Test gibt: Im Abschlussreview C-4 als Prüflücke nach
 * Regel 25 gemeldet. Ein Feld, das in der Palette fehlt oder dessen Legende
 * sich vertippt, ist im Backend unsichtbar — und kein einziger der anderen
 * Tests käme dem nahe, weil alle die Klassen direkt bauen und nie eine Maske.
 * Derselbe Bautyp wie der Fehler, der am 2026-09-21 das Backend lahmlegte.
 */
class NetPriceGroupsDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_shop.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testDasFeldIstEineMehrfachauswahlUeberDieMitgliedergruppen(): void
    {
        $field = $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['netPriceGroups'];

        self::assertSame('select', $field['inputType']);
        self::assertTrue($field['eval']['multiple']);
        // Der Fremdschlüssel füllt die Optionen; ohne ihn stünde die Maske
        // leer da, und zwar ohne Fehlermeldung.
        self::assertSame('tl_member_group.name', $field['foreignKey']);
        self::assertSame('blob', $field['sql']['type']);
    }

    public function testDasFeldIstKeinPflichtfeld(): void
    {
        // Der Normalfall ist der Shop ohne Nettogruppen. Ein Pflichtfeld
        // zwänge jeden Betreiber, sich zu einer Frage zu äußern, die ihn
        // nichts angeht.
        $field = $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['netPriceGroups'];

        self::assertArrayNotHasKey('mandatory', $field['eval']);
    }

    public function testDasFeldStehtInDerPaletteUnterEinerEigenenLegende(): void
    {
        $palette = $GLOBALS['TL_DCA']['tl_consho_shop']['palettes']['default'];

        // Seit D-4 steht die Basisgruppe der Kunden davor (K5).
        self::assertStringContainsString('{customergroup_legend},customerGroup,netPriceGroups', $palette);
    }

    public function testDieLegendeUndDasFeldSindInBeidenSprachenBenannt(): void
    {
        // Eine Legende ohne Text erscheint im Backend als ihr eigener
        // Schlüssel — „customergroup_legend" über der Auswahl.
        foreach (['de', 'en'] as $language) {
            $labels = $this->labels($language);

            self::assertArrayHasKey('customergroup_legend', $labels, $language);
            self::assertArrayHasKey('netPriceGroups', $labels, $language);
            self::assertCount(2, $labels['netPriceGroups'], $language);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function labels(string $language): array
    {
        $GLOBALS['TL_LANG'] = [];

        require __DIR__.'/../../contao/languages/'.$language.'/tl_consho_shop.php';

        /** @var array<string, mixed> $labels */
        $labels = $GLOBALS['TL_LANG']['tl_consho_shop'];

        unset($GLOBALS['TL_LANG']);

        return $labels;
    }
}

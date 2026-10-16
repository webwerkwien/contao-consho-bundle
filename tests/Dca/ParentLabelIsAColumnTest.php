<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * 🔴 Gefunden von Michael am 2026-09-25: „Info" an einem Rechtstext der
 * Bestellung war ein 500er — `Unknown column 'signals'`.
 *
 * Für `pid` eines Kinddatensatzes ohne eigenen `foreignKey` nimmt Contao das
 * ERSTE Listenfeld der Elterntabelle als Anzeigewert und fragt es per SQL ab
 * (ValueFormatter::format(), Contao 5.7). Bei tl_consho_order ist das
 * `signals` — eine Anzeigespalte der Liste ohne Datenbankspalte. Damit brach
 * „Info" in allen sieben Kindtabellen der Bestellung.
 *
 * Die Regel, allgemein: Ist das erste Listenfeld der Elterntabelle keine
 * Spalte, braucht `pid` der Kindtabelle einen ausdrücklichen `foreignKey`.
 */
class ParentLabelIsAColumnTest extends TestCase
{
    /**
     * @dataProvider childTables
     */
    public function testDasPidEinesKindesZeigtAufEineEchteSpalte(string $child, string $parent): void
    {
        $childDca = self::load($child);
        $parentDca = self::load($parent);

        if (isset($childDca['fields']['pid']['foreignKey'])) {
            [$table, $column] = explode('.', (string) $childDca['fields']['pid']['foreignKey'], 2);

            self::assertSame($parent, $table, $child);
            self::assertArrayHasKey('sql', $parentDca['fields'][$column] ?? [], $child.': foreignKey '.$column.' ist keine Spalte');

            return;
        }

        $first = $parentDca['list']['label']['fields'][0] ?? 'id';

        self::assertArrayHasKey(
            'sql',
            $parentDca['fields'][$first] ?? [],
            \sprintf('%s: Contao zeigt pid über "%s.%s" an, und das ist keine Spalte. Einen foreignKey am pid setzen.', $child, $parent, $first),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function childTables(): iterable
    {
        foreach (glob(__DIR__.'/../../contao/dca/tl_consho_*.php') ?: [] as $file) {
            $table = basename($file, '.php');
            $ptable = self::load($table)['config']['ptable'] ?? null;

            if (\is_string($ptable) && str_starts_with($ptable, 'tl_consho_')) {
                yield $table => [$table, $ptable];
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(string $table): array
    {
        $saved = $GLOBALS['TL_DCA'] ?? null;
        $GLOBALS['TL_DCA'] = [];

        require __DIR__.'/../../contao/dca/'.$table.'.php';

        $dca = $GLOBALS['TL_DCA'][$table] ?? [];
        $GLOBALS['TL_DCA'] = $saved;

        return $dca;
    }
}

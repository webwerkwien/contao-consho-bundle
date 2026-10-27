<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * 🔴 "Info" on a legal text of the order was a 500 —
 * `Unknown column 'signals'`.
 *
 * For the `pid` of a child record without its own `foreignKey`, Contao takes
 * the FIRST list field of the parent table as the display value and queries
 * it via SQL (ValueFormatter::format(), Contao 5.7). For tl_consho_order that
 * is `signals` — a display column of the list without a database column. So
 * "Info" broke in all seven child tables of the order.
 *
 * The rule, in general: if the first list field of the parent table is not
 * a column, the `pid` of the child table needs an explicit `foreignKey`.
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

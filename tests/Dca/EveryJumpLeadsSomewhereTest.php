<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Jede Sprung-Operation muss in einem Modul landen, das ihre Tabelle führt.
 *
 * ── Warum es diesen Test gibt ─────────────────────────────────────────────
 *
 * 🔴 Der Knopf „Rechtstexte" an jeder Bestellung antwortete mit **403**.
 * `tl_consho_order` zeigt seit C-7 auf `tl_consho_order_legal`, aber das
 * Backend-Modul `consho_order` führte die Tabelle nicht — und Contao lehnt
 * eine Tabelle ab, die ihr Modul nicht listet:
 *
 *     Table "tl_consho_order_legal" is not allowed in module "consho_order".
 *
 * Am 2026-09-23 auf c5 gefunden, beim Durchsehen aller Listen nach der
 * Umstellung der Operationen. **Nicht von ihr verursacht** — der Sprung war
 * von Anfang an kaputt. Er fiel nur nie auf, weil ihn niemand angeklickt
 * hatte; dasselbe Muster wie beim Löschen-Knopf ohne Dialog.
 *
 * Elf der zwölf Sprünge waren in Ordnung. Genau deshalb steht hier ein Test
 * und keine Notiz: Eine Sichtprüfung, die elfmal „passt" sagt, sagt es beim
 * zwölften Mal auch.
 */
class EveryJumpLeadsSomewhereTest extends TestCase
{
    public function testJederSprungLandetInEinemModulDasDieTabelleFuehrt(): void
    {
        $modules = self::modulesWithTables();
        $owners = [];

        foreach ($modules as $module => $tables) {
            foreach ($tables as $table) {
                $owners[$table][] = $module;
            }
        }

        $jumps = self::jumps();

        // Nie nichts: Findet die Suche keine Sprünge mehr, ist der Test
        // stillgelegt statt erfüllt.
        self::assertNotEmpty($jumps, 'No jump operations found at all — has the DCA syntax changed?');

        foreach ($jumps as [$source, $target]) {
            $modulesOfSource = $owners[$source] ?? [];

            self::assertNotEmpty(
                $modulesOfSource,
                \sprintf('%s is not listed in any back end module, so its jumps cannot work.', $source),
            );

            foreach ($modulesOfSource as $module) {
                self::assertContains(
                    $target,
                    $modules[$module],
                    \sprintf(
                        '%s jumps to %s, but the module "%s" does not list that table. Contao '
                        .'answers such a link with 403 ("Table … is not allowed in module …"), so '
                        .'the button is there and leads nowhere.',
                        $source,
                        $target,
                        $module,
                    ),
                );
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private static function modulesWithTables(): array
    {
        $GLOBALS['TL_LANG']['MOD'] = [];
        unset($GLOBALS['BE_MOD']);

        require __DIR__.'/../../contao/config/config.php';

        $modules = [];

        foreach ($GLOBALS['BE_MOD'] ?? [] as $group) {
            if (!\is_array($group)) {
                continue;
            }

            foreach ($group as $name => $config) {
                if (\is_array($config) && \is_array($config['tables'] ?? null)) {
                    $modules[$name] = array_values(array_filter($config['tables'], 'is_string'));
                }
            }
        }

        return $modules;
    }

    /**
     * @return list<array{string, string}>
     */
    private static function jumps(): array
    {
        $jumps = [];

        foreach (glob(__DIR__.'/../../contao/dca/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (!preg_match('/\$GLOBALS\[.TL_DCA.\]\[.(tl_consho_[a-z_]+).\]/', $source, $match)) {
                continue;
            }

            preg_match_all('/=> \[.href. => .table=(tl_consho_[a-z_]+)./', $source, $targets);

            foreach ($targets[1] as $target) {
                $jumps[] = [$match[1], $target];
            }
        }

        return $jumps;
    }
}

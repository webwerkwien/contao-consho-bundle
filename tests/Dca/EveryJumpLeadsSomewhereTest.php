<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Every jump operation must land in a module that lists its table.
 *
 * ── Why this test exists ─────────────────────────────────────────────────
 *
 * 🔴 The "Legal texts" button on every order answered with **403**.
 * `tl_consho_order` has pointed to `tl_consho_order_legal` since C-7, but
 * the backend module `consho_order` did not list the table — and Contao
 * rejects a table that its module does not list:
 *
 *     Table "tl_consho_order_legal" is not allowed in module "consho_order".
 *
 * It showed while going through all lists after the operations were
 * switched. **Not caused by that switch** — the jump was broken from the
 * start. It only never showed because nobody had clicked it; the same
 * pattern as with the delete button without a dialog.
 *
 * Eleven of the twelve jumps were fine. That is exactly why there is a test
 * here and not a note: a visual check that says "fine" eleven times says it
 * the twelfth time too.
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

        // Never nothing: if the search no longer finds any jumps, the test
        // is disabled rather than satisfied.
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

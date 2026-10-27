<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Every DCA of this bundle, checked against what Contao's own migrations
 * assume about a field's `eval`.
 *
 * ── Why this test exists ─────────────────────────────────────────────────
 *
 * 🔴 C-7 shipped `'rte' => false` on a textarea — an explicit
 * way of saying "no rich text editor here". Contao's
 * PrepareForOutputEncodingMigration does:
 *
 *     str_starts_with($fieldConfig['eval']['rte'] ?? '', 'tiny')
 *
 * `??` catches null, not false. So `contao:migrate` died with a TypeError
 * thrown from inside the core — AFTER applying the schema changes, which made
 * it look like something the server had done rather than something a file in
 * this repository said. Every later migration would have failed the same way
 * on every installation.
 *
 * The lesson is not "remember that one key". It is that a DCA is read by code
 * nobody here wrote, and a value that is merely FALSY is not the same as an
 * absent one. So the test walks every file and every field rather than
 * guarding the one that bit.
 */
class EveryDcaSurvivesContaoMigrationsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dcaFiles(): iterable
    {
        foreach (glob(__DIR__.'/../../contao/dca/*.php') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    /**
     * @dataProvider dcaFiles
     */
    public function testKeinFeldSetztEineZeichenketteAufFalse(string $file): void
    {
        unset($GLOBALS['TL_DCA']);

        /*
         * Two of these files EXTEND core tables and reach for a palette that
         * Contao declares, not this bundle — PaletteManipulator throws
         * without it. Stubbed rather than skipped: the fields those files add
         * are exactly as able to break a migration as any other, and skipping
         * the awkward two is how a check ends up not covering what it claims
         * to (the same shape of mistake as the test schema that renamed
         * `order` to avoid quoting it).
         */
        $GLOBALS['TL_DCA']['tl_member']['palettes']['default'] = '{personal_legend},firstname,lastname;';
        $GLOBALS['TL_DCA']['tl_page']['palettes']['root'] = '{title_legend},title,alias;';
        $GLOBALS['TL_DCA']['tl_page']['palettes']['rootfallback'] = '{title_legend},title,alias;';
        $GLOBALS['TL_DCA']['tl_module']['palettes']['registration'] = '{title_legend},name,type;{email_legend},reg_activate;';

        require $file;

        $dca = $GLOBALS['TL_DCA'] ?? [];
        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach ($dca as $table => $definition) {
            foreach ($definition['fields'] ?? [] as $field => $config) {
                foreach (self::STRING_KEYS as $key) {
                    if (!\array_key_exists($key, $config['eval'] ?? [])) {
                        continue;
                    }

                    self::assertIsString(
                        $config['eval'][$key],
                        \sprintf(
                            '%s.%s: eval.%s must be a string or absent. Contao reads it with `?? \'\'`, '
                            .'and that catches null but not false — a falsy value there breaks contao:migrate.',
                            $table,
                            $field,
                            $key,
                        ),
                    );
                }
            }
        }
    }

    /**
     * 🔴 **This test checks that the list above is still the whole list.**
     *
     * The list started as `['rte', 'rgxp', 'inputType']`, and two of the three
     * checked nothing. Measured against the installed core:
     *
     * - `rgxp` is read as `?? null` and COMPARED (`'date' === …`), never
     *   passed to a string function. `false === 'date'` is simply false.
     * - `inputType` does not live under `eval` at all — it sits one level up,
     *   on the field. The test looked where it could not be.
     *
     * A list of names is a guess unless something checks it, and a guess in a
     * test reads exactly like knowledge. So the list is derived here from the
     * core that is actually installed: every `['eval'][…] ?? ''` in it.
     *
     * 🎯 **It earned itself immediately.** Written by hand, the list was
     * `rte` — from two places, PrepareForOutputEncodingMigration (the one that
     * broke) and Widget::getAttributesFromDca(), which throws in the backend
     * for the same reason. This found `tl_class` in tl_files.php as well,
     * which a grep over src/ and contao/ had missed.
     *
     * `tl_class` does not throw: it is CONCATENATED, and `false . ' cbx'` is
     * a string. It is in the list anyway, because the rule worth keeping is
     * not "which key crashes" but "a value Contao reads as a string is a
     * string or is absent". A `tl_class` of false would silently drop a CSS
     * class, which is the kind of wrong nobody goes looking for.
     *
     * When a future Contao reads another key that way, this fails and names
     * it, rather than the DCA test quietly covering less than it claims.
     */
    public function testDieListeDerZeichenkettenSchluesselIstVollstaendig(): void
    {
        $core = __DIR__.'/../../vendor/contao/core-bundle';

        if (!is_dir($core)) {
            self::markTestSkipped('Contao core-bundle is not installed.');
        }

        $found = [];

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($core)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            preg_match_all(
                "/\\['eval'\\]\\['([a-zA-Z_]+)'\\]\\s*\\?\\?\\s*''/",
                file_get_contents((string) $file) ?: '',
                $matches,
            );

            foreach ($matches[1] as $key) {
                $found[$key] = true;
            }
        }

        // Not `assertSame` on the order: the question is whether a key is
        // missing, not how the list is sorted.
        $found = array_keys($found);
        sort($found);

        $expected = self::STRING_KEYS;
        sort($expected);

        self::assertSame(
            $expected,
            $found,
            'Contao reads other eval keys with `?? \'\'` than STRING_KEYS lists. '
            .'Add them there — a falsy value in one of them breaks contao:migrate.',
        );
    }

    /**
     * Keys Contao reads with `?? ''` and then treats as a string. Derived from
     * the installed core rather than guessed, and the test above keeps it
     * honest.
     */
    private const STRING_KEYS = ['rte', 'tl_class'];
}

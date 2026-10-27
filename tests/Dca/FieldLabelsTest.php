<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Every input field that Consho creates has a label in both languages.
 *
 * Issue #2 (version 0.1.3): `conshoSort` and `conshoPerPage` on the content
 * element showed their field names. Until then this check only existed for
 * single tables (SubscriptionDcaTest, NetPriceGroupsDcaTest) — exactly the
 * remaining ones were missing.
 *
 * The package's own DCA files are read. For Contao's own tables
 * (tl_content, tl_page, …) only the fields that Consho adds count: what the
 * file newly writes into `fields`.
 *
 * In separate processes: Contao's DCA files declare classes (`tl_content`,
 * …), and other tests load the same files.
 */
#[RunTestsInSeparateProcesses]
class FieldLabelsTest extends TestCase
{
    private const DCA = __DIR__.'/../../contao/dca';

    private const LANGUAGES = __DIR__.'/../../contao/languages';

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG'], $GLOBALS['BE_MOD']);
    }

    public function testJedesEingabefeldHatEineBeschriftungInBeidenSprachen(): void
    {
        $missing = [];

        foreach (glob(self::DCA.'/*.php') ?: [] as $file) {
            $table = basename($file, '.php');

            foreach (['de', 'en'] as $language) {
                foreach ($this->fieldsOf($table, $file) as $field => $config) {
                    if (!isset($config['inputType']) || isset($config['label'])) {
                        continue;
                    }

                    $label = $this->labels($table, $language)[$field] ?? null;

                    if (!\is_array($label) || !\is_string($label[0] ?? null) || '' === $label[0]) {
                        $missing[] = \sprintf('%s: %s.%s', $language, $table, $field);
                    }
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testDerTestFindetFelder(): void
    {
        // A test that finds nothing would always be green.
        self::assertArrayHasKey('conshoSort', $this->fieldsOf('tl_content', self::DCA.'/tl_content.php'));
        self::assertGreaterThan(20, \count(glob(self::DCA.'/*.php') ?: []));
    }

    /**
     * The fields this file adds — for Contao's own tables only its own.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fieldsOf(string $table, string $file): array
    {
        // Contao's DCA files declare classes (tl_content, …): load each once.
        static $cache = [];

        return $cache[$table] ??= $this->load($table, $file);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(string $table, string $file): array
    {
        unset($GLOBALS['TL_DCA']);
        $GLOBALS['TL_DCA'][$table] = ['config' => [], 'list' => [], 'palettes' => ['__selector__' => [], 'default' => ''], 'subpalettes' => [], 'fields' => []];
        $GLOBALS['TL_LANG'] = $GLOBALS['TL_LANG'] ?? [];

        // Contao's own table first: Consho's file extends its palettes.
        $core = __DIR__.'/../../vendor/contao/core-bundle/contao/dca/'.$table.'.php';
        $own = [];

        if (is_file($core)) {
            require $core;
            $own = array_keys($GLOBALS['TL_DCA'][$table]['fields'] ?? []);
        }

        require $file;

        return array_diff_key($GLOBALS['TL_DCA'][$table]['fields'] ?? [], array_flip($own));
    }

    /**
     * @return array<string, mixed>
     */
    private function labels(string $table, string $language): array
    {
        $saved = $GLOBALS['TL_DCA'] ?? null;
        $GLOBALS['TL_LANG'] = [];

        foreach (['default', $table] as $name) {
            $path = self::LANGUAGES.'/'.$language.'/'.$name.'.php';

            if (is_file($path)) {
                require $path;
            }
        }

        $GLOBALS['TL_DCA'] = $saved;

        return $GLOBALS['TL_LANG'][$table] ?? [];
    }
}

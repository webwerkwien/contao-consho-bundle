<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * G-8: The shopping pages were English in the German backend. Their
 * language file appended the texts with `+=`; but Contao first loads
 * English as the fallback and then German, and `+=` leaves every key that
 * already exists in place. Every test loaded only one language and did not
 * see it.
 *
 * Here loading happens as in Contao: first en, then de. Every value the
 * German file sets must be the German one afterwards.
 */
class LanguageFallbackOrderTest extends TestCase
{
    private const DIR = __DIR__.'/../../contao/languages';

    /** @var array<mixed>|null */
    private array|null $saved = null;

    protected function setUp(): void
    {
        $this->saved = $GLOBALS['TL_LANG'] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->saved) {
            unset($GLOBALS['TL_LANG']);
        } else {
            $GLOBALS['TL_LANG'] = $this->saved;
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function germanFiles(): iterable
    {
        foreach (glob(self::DIR.'/de/*.php') ?: [] as $file) {
            yield basename($file) => [basename($file)];
        }
    }

    #[DataProvider('germanFiles')]
    public function testDeutschUeberschreibtDenEnglischenRueckfall(string $file): void
    {
        $GLOBALS['TL_LANG'] = [];
        self::load(self::DIR.'/de/'.$file);
        $germanOnly = $GLOBALS['TL_LANG'];

        $GLOBALS['TL_LANG'] = [];

        if (is_file(self::DIR.'/en/'.$file)) {
            self::load(self::DIR.'/en/'.$file);
        }

        self::load(self::DIR.'/de/'.$file);

        self::assertSame($germanOnly, array_intersect_key_recursive($GLOBALS['TL_LANG'], $germanOnly), $file);
    }

    private static function load(string $path): void
    {
        include $path;
    }
}

/**
 * The part of $actual that $expected has keys for, recursively — the English
 * file may well have keys the German one lacks; those are not the question.
 *
 * @param array<mixed> $actual
 * @param array<mixed> $expected
 *
 * @return array<mixed>
 */
function array_intersect_key_recursive(array $actual, array $expected): array
{
    $result = [];

    foreach ($expected as $key => $value) {
        if (!\array_key_exists($key, $actual)) {
            continue;
        }

        $result[$key] = \is_array($value) && \is_array($actual[$key]) && !array_is_list($value)
            ? array_intersect_key_recursive($actual[$key], $value)
            : $actual[$key];
    }

    return $result;
}

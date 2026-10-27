<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Config;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;

class ConfigurationFindingLanguageTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testJederBefundHatEinenDeutschenText(): void
    {
        self::assertFindingTextsExist('de');
    }

    public function testJederBefundHatEinenEnglischenText(): void
    {
        self::assertFindingTextsExist('en');
    }

    /**
     * 🔴 **Both languages must use the same placeholders.**
     *
     * The check above only says **that** a text is there. When `%shop%` was
     * added to the tax rate finding, writing it only into the German text
     * would have left the English text silently without a shop, and exactly
     * the ambiguity the addition is meant to remove would have survived in
     * one language. Nothing would have reported it: the text exists and is
     * not empty.
     *
     * ⚠️ **What this test does NOT check:** whether a placeholder also occurs
     * in the finding's context. A `%shipping%` in both languages would pass
     * it and appear literally in the back end. That would require calling
     * every check method with its arguments — which would couple the test to
     * six call forms. The asymmetry between the languages is the more likely
     * error and cheap to catch here.
     */
    public function testBeideSprachenBenutzenDieselbenPlatzhalter(): void
    {
        $de = self::placeholdersOf('de');
        $en = self::placeholdersOf('en');

        self::assertSame(array_keys($de), array_keys($en));

        foreach ($de as $finding => $platzhalter) {
            self::assertSame(
                $platzhalter,
                $en[$finding],
                \sprintf('Der Befund "%s" nennt in beiden Sprachen verschiedene Platzhalter.', $finding),
            );
        }
    }

    /**
     * @return array<string, list<string>> finding => sorted placeholders
     */
    private static function placeholdersOf(string $language): array
    {
        unset($GLOBALS['TL_LANG']);

        require __DIR__.'/../../contao/languages/'.$language.'/default.php';

        $found = [];

        foreach ($GLOBALS['TL_LANG']['CONSHO']['findings'] as $finding => $text) {
            preg_match_all('/%([a-zA-Z]+)%/', (string) $text, $matches);

            $platzhalter = array_values(array_unique($matches[1]));
            sort($platzhalter);

            $found[(string) $finding] = $platzhalter;
        }

        ksort($found);

        return $found;
    }

    private static function assertFindingTextsExist(string $language): void
    {
        // Load in isolation: the file replaces $GLOBALS['TL_LANG']['CONSHO']['findings']
        // completely; a second require without this unset would still have the
        // previous language in memory, without the test noticing.
        unset($GLOBALS['TL_LANG']);

        require __DIR__.'/../../contao/languages/'.$language.'/default.php';

        $findings = $GLOBALS['TL_LANG']['CONSHO']['findings'];

        foreach (ConfigurationFinding::cases() as $finding) {
            self::assertArrayHasKey($finding->value, $findings, $finding->value);
            self::assertNotSame('', $findings[$finding->value], $finding->value);
        }
    }
}

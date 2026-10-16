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
     * 🔴 **Beide Sprachen müssen dieselben Platzhalter benutzen.**
     *
     * Die Prüfung oben sagt nur, **dass** ein Text da ist. Am 2026-09-23 kam
     * `%shop%` in den Steuersatz-Befund — hätte ich ihn nur ins Deutsche
     * geschrieben, wäre der englische Text still ohne Shop geblieben, und
     * genau die Mehrdeutigkeit, die der Zusatz beseitigen soll, wäre in einer
     * Sprache erhalten geblieben. Nichts hätte es gemeldet: Der Text ist
     * vorhanden und nicht leer.
     *
     * ⚠️ **Was dieser Test NICHT prüft:** ob ein Platzhalter im Kontext des
     * Befunds auch vorkommt. Ein `%shipping%` in beiden Sprachen bestünde ihn
     * und stünde im Backend wörtlich da. Dafür müsste jede Prüfmethode
     * aufgerufen werden, mit ihren Argumenten — das koppelte den Test an
     * sechs Aufrufformen. Die Asymmetrie zwischen den Sprachen ist der
     * wahrscheinlichere Fehler und hier billig zu haben.
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
        // Isoliert laden: die Datei ersetzt $GLOBALS['TL_LANG']['CONSHO']['findings']
        // komplett, ein zweites require ohne dieses unset würde die vorige
        // Sprache noch im Speicher haben, ohne dass der Test es merkt.
        unset($GLOBALS['TL_LANG']);

        require __DIR__.'/../../contao/languages/'.$language.'/default.php';

        $findings = $GLOBALS['TL_LANG']['CONSHO']['findings'];

        foreach (ConfigurationFinding::cases() as $finding) {
            self::assertArrayHasKey($finding->value, $findings, $finding->value);
            self::assertNotSame('', $findings[$finding->value], $finding->value);
        }
    }
}

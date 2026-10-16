<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Ein gesperrtes Auswahlfeld mit `foreignKey` braucht eine leere Option.
 *
 * Gefunden bei der Abnahme von WG-2 (2026-10-06): Ein von Hand ausgestellter
 * Gutschein gehört zu keiner Bestellung (`orderId` 0). Ohne leere Option hat
 * die Auswahl keinen passenden Eintrag, und der Browser zeigt den ERSTEN —
 * „Gekauft mit WEB-00093". Gespeichert wird das nicht (gesperrte Felder gehen
 * nicht mit), aber die Maske behauptet es. Dasselbe stand seit Block G in
 * jeder Lagerbewegung von Hand und in 18 weiteren Feldern.
 */
class DisabledSelectShowsNothingTest extends TestCase
{
    public function testJedesGesperrteAuswahlfeldHatEineLeereOption(): void
    {
        $missing = [];

        // Consho's own tables: the extensions of Contao's (tl_member …) need
        // Contao's DCA loaded first.
        foreach (glob(__DIR__.'/../../contao/dca/tl_consho_*.php') ?: [] as $file) {
            $GLOBALS['TL_DCA'] = [];
            include $file;

            foreach ($GLOBALS['TL_DCA'] as $table => $dca) {
                foreach ($dca['fields'] ?? [] as $name => $config) {
                    if ('select' === ($config['inputType'] ?? '') && ($config['eval']['disabled'] ?? false) && isset($config['foreignKey']) && !($config['eval']['includeBlankOption'] ?? false)) {
                        $missing[] = $table.'.'.$name;
                    }
                }
            }
        }

        unset($GLOBALS['TL_DCA']);

        self::assertSame([], $missing);
    }
}

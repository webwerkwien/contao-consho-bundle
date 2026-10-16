<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Ein kopierter Shop kopiert seine Nummernkreise (ctable) — der Zählerstand
 * darf nicht mitwandern. Gefunden bei der Abnahme von C-12 (2026-09-26):
 * „Testshop 2" war eine Kopie und stand bei vier Rechnungen, ohne je eine
 * ausgestellt zu haben; seine erste Rechnung wäre RE-00005 gewesen.
 *
 * Geprüft wird die DCA-Datei, nicht DC_Table::copy(): Contao lässt beim
 * Kopieren jedes Feld mit `doNotCopy` auf seinem Vorgabewert (0).
 */
class NumberRangeCopyTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testDerZaehlerstandWirdNichtKopiert(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_number_range.php';

        $current = $GLOBALS['TL_DCA']['tl_consho_number_range']['fields']['current'];

        self::assertTrue($current['eval']['doNotCopy'] ?? false);
        self::assertSame(0, $current['sql']['default']);
        self::assertContains('tl_consho_number_range', (static function (): array {
            require __DIR__.'/../../contao/dca/tl_consho_shop.php';

            return $GLOBALS['TL_DCA']['tl_consho_shop']['config']['ctable'];
        })(), 'Nur als Kind des Shops wird der Kreis überhaupt mitkopiert.');
    }
}

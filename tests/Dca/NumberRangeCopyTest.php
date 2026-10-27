<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * A copied shop copies its number ranges (ctable) — the counter must not
 * travel along. C-12: a shop that was a copy stood at four invoices without
 * ever having issued one; its first invoice would have been the fifth.
 *
 * What is checked is the DCA file, not DC_Table::copy(): when copying,
 * Contao leaves every field with `doNotCopy` at its default value (0).
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

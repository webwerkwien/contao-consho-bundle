<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Bestellung, Zahlung, Lieferung und Position werden nicht kopiert (D-1,
 * beide Fable-Reviews, S1 und S-a).
 *
 * Zwei Wege, zwei Schalter: `notCopyable` sperrt das Kopieren der Tabelle
 * selbst, `doNotCopyRecords` das Mitkopieren als Kind — DC_Table::copyChildren()
 * fragt nur Letzteres. Eine kopierte Bestellung brachte ihre gesperrten
 * Zahlungen und gemeldeten Lieferungen mit, unlöschbar.
 */
final class OrderRecordsAreNotCopiedTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tables(): iterable
    {
        yield 'Bestellung' => ['tl_consho_order'];
        yield 'Zahlung' => ['tl_consho_payment'];
        yield 'Lieferung' => ['tl_consho_delivery'];
        yield 'Lieferposition' => ['tl_consho_delivery_item'];
    }

    /**
     * @dataProvider tables
     */
    public function testDieTabelleIstNichtKopierbar(string $table): void
    {
        require __DIR__.'/../../contao/dca/'.$table.'.php';

        self::assertTrue($GLOBALS['TL_DCA'][$table]['config']['notCopyable'] ?? false);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function children(): iterable
    {
        yield 'Zahlung' => ['tl_consho_payment'];
        yield 'Lieferung' => ['tl_consho_delivery'];
        yield 'Lieferposition' => ['tl_consho_delivery_item'];
    }

    /**
     * @dataProvider children
     */
    public function testAlsKindWirdNichtsMitkopiert(string $table): void
    {
        require __DIR__.'/../../contao/dca/'.$table.'.php';

        self::assertTrue($GLOBALS['TL_DCA'][$table]['config']['doNotCopyRecords'] ?? false);
    }
}

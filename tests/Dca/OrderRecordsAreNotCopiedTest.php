<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Order, payment, delivery and item are not copied (D-1).
 *
 * Two paths, two switches: `notCopyable` blocks copying the table itself,
 * `doNotCopyRecords` copying along as a child — DC_Table::copyChildren()
 * asks only the latter. A copied order brought along its locked payments
 * and reported deliveries, undeletable.
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

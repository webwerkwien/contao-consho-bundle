<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Lists that are only ever read, and the stock module's header.
 */
class ReadOnlyListsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    /**
     * Without an operations list Contao adds its defaults — in a list ordered
     * by time that is a "cut" that stays grey forever.
     */
    public function testBewegungenUndBuchungenZeigenNurDetails(): void
    {
        foreach (['tl_consho_stock_movement', 'tl_consho_voucher_booking'] as $table) {
            require __DIR__.'/../../contao/dca/'.$table.'.php';

            self::assertSame(['!show'], $GLOBALS['TL_DCA'][$table]['list']['operations'], $table);
        }
    }

    public function testEinkaufUndEinkaufslistenStehenImKopf(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_stock.php';

        foreach (['purchase', 'purchaseLists'] as $operation) {
            self::assertTrue($GLOBALS['TL_DCA']['tl_consho_stock']['list']['global_operations'][$operation]['primary'] ?? false, $operation);
        }
    }
}

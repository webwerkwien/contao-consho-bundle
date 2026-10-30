<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * An icon of our own is a path under bundles/contaoconsho/ — a typo there
 * only shows as a broken image in the back end, never as an error.
 */
class BundleIconsTest extends TestCase
{
    public function testJedesEigeneIconGibtEs(): void
    {
        $found = 0;

        foreach (glob(__DIR__.'/../../contao/dca/*.php') ?: [] as $file) {
            preg_match_all('#bundles/contaoconsho/(icons/[\w.-]+\.svg)#', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $path) {
                ++$found;
                self::assertFileExists(__DIR__.'/../../public/'.$path, basename($file));
            }
        }

        self::assertGreaterThan(0, $found, 'the pattern must find the price icon');
    }

    /**
     * Contao's own icons the same way: `lock.svg` does not exist in the
     * theme, and the entitlements button was an empty link for it.
     */
    public function testJedesContaoIconGibtEs(): void
    {
        $theme = __DIR__.'/../../vendor/contao/core-bundle/contao/themes/flexible/icons/';
        $found = 0;

        foreach (glob(__DIR__.'/../../contao/dca/*.php') ?: [] as $file) {
            preg_match_all("#'icon' => '([\\w.-]+\\.svg)'#", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $icon) {
                ++$found;
                self::assertFileExists($theme.$icon, basename($file));
            }
        }

        self::assertGreaterThan(0, $found, 'the pattern must find show.svg');
    }

    public function testDieProduktpreiseTragenDasPreisschild(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_product.php';

        self::assertSame('bundles/contaoconsho/icons/price.svg', $GLOBALS['TL_DCA']['tl_consho_product']['list']['operations']['prices']['icon']);

        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testVerlaufUndSaldoTragenIhreIcons(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_stock.php';
        require __DIR__.'/../../contao/dca/tl_consho_voucher.php';

        self::assertSame('bundles/contaoconsho/icons/history.svg', $GLOBALS['TL_DCA']['tl_consho_stock']['list']['operations']['movements']['icon']);
        self::assertSame('bundles/contaoconsho/icons/history.svg', $GLOBALS['TL_DCA']['tl_consho_voucher']['list']['operations']['bookings']['icon']);
        self::assertSame('bundles/contaoconsho/icons/balance.svg', $GLOBALS['TL_DCA']['tl_consho_voucher']['list']['operations']['correctVoucher']['icon']);

        require __DIR__.'/../../contao/dca/tl_consho_withdrawal.php';
        self::assertSame('bundles/contaoconsho/icons/items.svg', $GLOBALS['TL_DCA']['tl_consho_withdrawal']['list']['operations']['items']['icon']);

        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }
}

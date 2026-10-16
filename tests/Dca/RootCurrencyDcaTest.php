<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Was an tl_page wirklich in der Palette steht — an der DCA-Datei gemessen,
 * nicht an einer Konstante im Listener-Test.
 *
 * ⚠️ Der Grund für diese Datei: Die Reihenfolge `consho_shop` vor
 * `consho_currency` ist funktional. `DC_Table::edit()` arbeitet die
 * Palettenfelder der Reihe nach ab und legt einen Wert erst **nach** der
 * Verarbeitung seines Feldes in `arrSubmit`; steht die Währung vorher, liest
 * ihr Callback den Shop vom letzten Speichern. Ein Test, der die Reihenfolge
 * aus einem selbst getippten Palettenstring liest, kann eine Umsortierung in
 * der DCA nicht bemerken — der String lügt dann einfach weiter.
 */
class RootCurrencyDcaTest extends TestCase
{
    protected function setUp(): void
    {
        /*
         * `PaletteManipulator::applyToPalette()` wirft, wenn die Palette nicht
         * existiert — im Betrieb liefert sie der Contao-Kern. Ein
         * Minimal-String reicht: Geprüft wird, was die DCA-Datei daran ändert.
         */
        $GLOBALS['TL_DCA']['tl_page']['palettes']['root'] = '{title_legend},title,alias,type;{publish_legend},published';
        $GLOBALS['TL_DCA']['tl_page']['palettes']['rootfallback'] = '{title_legend},title,alias,type;{publish_legend},published';

        require __DIR__.'/../../contao/dca/tl_page.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    /**
     * Beide Paletten, nicht nur „root": Contao nimmt „rootfallback" für den
     * Startpunkt, der die Sprach-Rückfallebene trägt — das ist die
     * Hauptsprache jeder Website.
     *
     * @dataProvider rootPalettes
     */
    public function testDieWaehrungStehtHinterDemShop(string $palette): void
    {
        $fields = $GLOBALS['TL_DCA']['tl_page']['palettes'][$palette];

        $shop = strpos($fields, 'consho_shop');
        $currency = strpos($fields, 'consho_currency');

        self::assertIsInt($shop, 'consho_shop fehlt in der Palette '.$palette);
        self::assertIsInt($currency, 'consho_currency fehlt in der Palette '.$palette);
        self::assertLessThan($currency, $shop, 'Die Währung muss hinter dem Shop stehen, sonst liest ihr Callback den alten Shop.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rootPalettes(): iterable
    {
        yield 'root' => ['root'];
        yield 'rootfallback' => ['rootfallback'];
    }

    /**
     * Kein `submitOnChange` am Shop: Ein Auto-Submit setzte `tstamp = time()`
     * und höbe damit den `tstamp = 0`-Zustand auf, an dem `reviseTable()`
     * unvollständige Neuanlagen aufräumt. Aus genau diesem Grund hat die
     * Preismaske es am 2026-09-15 verloren.
     */
    public function testDerShopLoestKeinAutoSubmitAus(): void
    {
        self::assertArrayNotHasKey('submitOnChange', $GLOBALS['TL_DCA']['tl_page']['fields']['consho_shop']['eval']);
    }

    /**
     * Kein `doNotCopy`, und das ist gewollt: `DC_Table::copy()` übernimmt jedes
     * Feld ohne diese Marke, eine kopierte Startseite bringt ihre Währung also
     * mit. So legt der Rollout-Ablauf die zweite Währungsseite an.
     */
    public function testEineKopierteStartseiteBringtIhreWaehrungMit(): void
    {
        $field = $GLOBALS['TL_DCA']['tl_page']['fields']['consho_currency'];

        self::assertArrayNotHasKey('doNotCopy', $field['eval']);
        self::assertTrue($field['eval']['mandatory']);
        self::assertSame('select', $field['inputType']);
        self::assertSame('string', $field['sql']['type']);
        self::assertSame(3, $field['sql']['length']);
    }
}

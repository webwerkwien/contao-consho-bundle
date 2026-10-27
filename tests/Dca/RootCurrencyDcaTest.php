<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * What really is in the tl_page palette — measured against the DCA file,
 * not against a constant in the listener test.
 *
 * ⚠️ The reason for this file: the order `consho_shop` before
 * `consho_currency` is functional. `DC_Table::edit()` processes the palette
 * fields in order and puts a value into `arrSubmit` only **after** its field
 * has been processed; if the currency comes first, its callback reads the
 * shop from the last save. A test that reads the order from a palette string
 * typed by itself cannot notice a reordering in the DCA — the string then
 * simply keeps lying.
 */
class RootCurrencyDcaTest extends TestCase
{
    protected function setUp(): void
    {
        /*
         * `PaletteManipulator::applyToPalette()` throws if the palette does not
         * exist — in operation the Contao core provides it. A minimal string
         * is enough: what is checked is what the DCA file changes about it.
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
     * Both palettes, not just "root": Contao uses "rootfallback" for the root
     * page that carries the language fallback — that is the main language of
     * every website.
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
     * No `submitOnChange` on the shop: an auto-submit would set
     * `tstamp = time()` and thereby lift the `tstamp = 0` state by which
     * `reviseTable()` cleans up incomplete new records. For exactly this reason
     * the price form lost it.
     */
    public function testDerShopLoestKeinAutoSubmitAus(): void
    {
        self::assertArrayNotHasKey('submitOnChange', $GLOBALS['TL_DCA']['tl_page']['fields']['consho_shop']['eval']);
    }

    /**
     * No `doNotCopy`, and that is intended: `DC_Table::copy()` takes over every
     * field without this marker, so a copied root page brings its currency
     * along. That is how the rollout creates the second currency page.
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

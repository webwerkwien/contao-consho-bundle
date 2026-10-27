<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\InvoiceLogoFieldListener;

/**
 * The file picker offers only file types that are suitable and allowed in
 * Contao.
 *
 * Against the real DCA, not against a copy.
 */
class InvoiceLogoFieldListenerTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../../contao/dca/tl_consho_shop.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testDasLogoNimmtNurGeeigneteUndErlaubteTypen(): void
    {
        (new InvoiceLogoFieldListener(['jpg', 'jpeg', 'gif', 'png', 'svg', 'webp']))();

        self::assertSame('png,jpg,jpeg', $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['invoiceLogo']['eval']['extensions']);
    }

    /**
     * What the installation does not allow as an image drops out — even if it
     * would be suitable for the invoice.
     */
    public function testWasContaoNichtErlaubtFaelltHeraus(): void
    {
        (new InvoiceLogoFieldListener(['jpg', 'gif']))();

        self::assertSame('jpg', $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['invoiceLogo']['eval']['extensions']);
    }

    /**
     * 🔴 Empty intersection: NO `extensions => ''`. FileTree only checks if the
     * value is truthy — an empty string would switch the check off and allow
     * any file. Instead, the field disappears.
     */
    public function testOhneErlaubtenTypVerschwindetDasFeldStattJedeDateiZuzulassen(): void
    {
        (new InvoiceLogoFieldListener(['gif', 'webp']))();

        self::assertSame('png,jpg,jpeg', $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['invoiceLogo']['eval']['extensions']);
        self::assertStringNotContainsString('invoiceLogo', $GLOBALS['TL_DCA']['tl_consho_shop']['palettes']['default']);
        self::assertStringContainsString('invoiceContactEmail,deliveryDaysMin', $GLOBALS['TL_DCA']['tl_consho_shop']['palettes']['default']);
    }

    /**
     * The storage folder: `files => false` is the condition under which Contao
     * checks "folders only" on the server side (FileTree::checkValue, strictly
     * on false). Without it only the picker dialog filters.
     */
    public function testDerAblageordnerNimmtServerseitigNurOrdner(): void
    {
        self::assertFalse($GLOBALS['TL_DCA']['tl_consho_shop']['fields']['invoiceArchiveFolder']['eval']['files'] ?? null);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\InvoiceLogoFieldListener;

/**
 * Michael, 2026-09-24: „bei der Dateiauswahl darauf achten, dass nur geeignete
 * und in Contao erlaubte Dateitypen auswählbar sind".
 *
 * Gegen die echte DCA, nicht gegen eine Abschrift.
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
     * Was die Installation nicht als Bild zulässt, fällt heraus — auch wenn
     * es für die Rechnung geeignet wäre.
     */
    public function testWasContaoNichtErlaubtFaelltHeraus(): void
    {
        (new InvoiceLogoFieldListener(['jpg', 'gif']))();

        self::assertSame('jpg', $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['invoiceLogo']['eval']['extensions']);
    }

    /**
     * 🔴 Leere Schnittmenge: KEIN `extensions => ''`. FileTree prüft nur,
     * wenn der Wert wahr ist — ein Leerstring schaltete die Prüfung ab und
     * ließe jede Datei zu. Das Feld verschwindet stattdessen.
     */
    public function testOhneErlaubtenTypVerschwindetDasFeldStattJedeDateiZuzulassen(): void
    {
        (new InvoiceLogoFieldListener(['gif', 'webp']))();

        self::assertSame('png,jpg,jpeg', $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['invoiceLogo']['eval']['extensions']);
        self::assertStringNotContainsString('invoiceLogo', $GLOBALS['TL_DCA']['tl_consho_shop']['palettes']['default']);
        self::assertStringContainsString('invoiceContactEmail,deliveryDaysMin', $GLOBALS['TL_DCA']['tl_consho_shop']['palettes']['default']);
    }

    /**
     * Der Ablageordner: `files => false` ist die Bedingung, mit der Contao
     * serverseitig „nur Ordner" prüft (FileTree::checkValue, strikt auf
     * false). Ohne sie filtert nur der Auswahldialog.
     */
    public function testDerAblageordnerNimmtServerseitigNurOrdner(): void
    {
        self::assertFalse($GLOBALS['TL_DCA']['tl_consho_shop']['fields']['invoiceArchiveFolder']['eval']['files'] ?? null);
    }
}

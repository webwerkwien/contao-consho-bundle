<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Contao\CoreBundle\EventListener\VirtualFieldsMappingListener;
use PHPUnit\Framework\TestCase;

class PriceDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_price.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testDerBetragHatBewusstKeineSpalte(): void
    {
        // If the `sql` key is missing entirely, Contao's VirtualFieldsMappingListener
        // puts the field into a JSON column `jsonData` — the table would get a
        // column, and the amount would be saved after all.
        $amount = $GLOBALS['TL_DCA']['tl_consho_price']['fields']['amount'];

        self::assertArrayHasKey('sql', $amount);
        self::assertNull($amount['sql']);
    }

    public function testBetragEingabeartUndLandKommenBeimSpeichernImmerAn(): void
    {
        // DC_Table passes only changed fields on to onbeforesubmit.
        foreach (['amount', 'inputMode', 'inputCountry'] as $field) {
            self::assertTrue($GLOBALS['TL_DCA']['tl_consho_price']['fields'][$field]['eval']['alwaysSave'] ?? false, $field);
        }
    }

    public function testBruttoIstKeineEingabespalteMehr(): void
    {
        self::assertArrayNotHasKey('gross', $GLOBALS['TL_DCA']['tl_consho_price']['fields']);
        self::assertStringNotContainsString('gross', $GLOBALS['TL_DCA']['tl_consho_price']['palettes']['default']);
    }

    public function testNettoWirdNichtMehrDirektEingegeben(): void
    {
        self::assertStringNotContainsString(',net', $GLOBALS['TL_DCA']['tl_consho_price']['palettes']['default']);
        self::assertArrayNotHasKey('inputType', $GLOBALS['TL_DCA']['tl_consho_price']['fields']['net']);
    }

    public function testDieWaehrungLaedtDieMaskeNichtNeu(): void
    {
        // An automatic submit saves the row — a saved price row would go live
        // with the new currency and the old net amount.
        $currency = $GLOBALS['TL_DCA']['tl_consho_price']['fields']['currency'];

        self::assertSame('select', $currency['inputType']);
        self::assertFalse($currency['eval']['submitOnChange'] ?? false);
    }

    public function testNeuePreiszeilenStartenBruttoAlteOeffnenNetto(): void
    {
        $inputMode = $GLOBALS['TL_DCA']['tl_consho_price']['fields']['inputMode'];

        self::assertSame('gross', $inputMode['default']);
        self::assertSame('net', $inputMode['sql']['default']);
    }

    public function testDerBetragIstEinPflichtfeldMitZiffernpruefung(): void
    {
        // No maxval: Widget::validator() checks it before Contao turns a comma
        // into a point — CoupledPriceInputListener::assertFits() checks the
        // calculated net amount instead.
        $eval = $GLOBALS['TL_DCA']['tl_consho_price']['fields']['amount']['eval'];

        self::assertTrue($eval['mandatory']);
        self::assertSame('digit', $eval['rgxp']);
        self::assertArrayNotHasKey('maxval', $eval);
    }

    public function testEinePreiszeileErbtKeinenEuroVorgabewert(): void
    {
        // Loud rather than silent — which also suits an international product.
        // The form has preset the currency correctly since C-1b —
        // CoupledPriceInputListener overwrites the default per request — but that
        // only reaches the form. A row created past it (model, CLI, migration)
        // otherwise silently became a euro price, even in a shop that does not
        // sell in euros at all.
        self::assertSame('', $GLOBALS['TL_DCA']['tl_consho_price']['fields']['currency']['sql']['default']);
    }

    public function testContaoLegtFuerDenBetragKeineJsonSpalteAn(): void
    {
        // VirtualFieldsMappingListener runs as a loadDataContainer hook before
        // any adjustment of our own and would divert a field without 'sql' into
        // a jsonData column — exactly what 'sql' => null is meant to prevent.
        (new VirtualFieldsMappingListener())('tl_consho_price');

        self::assertArrayNotHasKey('targetColumn', $GLOBALS['TL_DCA']['tl_consho_price']['fields']['amount']);
        self::assertArrayNotHasKey('jsonData', $GLOBALS['TL_DCA']['tl_consho_price']['fields']);
    }
}

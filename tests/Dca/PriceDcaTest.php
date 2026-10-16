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
        // Fehlt der Schlüssel `sql` ganz, legt Contaos VirtualFieldsMappingListener
        // das Feld in eine JSON-Spalte `jsonData` — die Tabelle bekäme eine
        // Spalte, und der Betrag würde doch gespeichert.
        $amount = $GLOBALS['TL_DCA']['tl_consho_price']['fields']['amount'];

        self::assertArrayHasKey('sql', $amount);
        self::assertNull($amount['sql']);
    }

    public function testBetragEingabeartUndLandKommenBeimSpeichernImmerAn(): void
    {
        // DC_Table reicht nur geänderte Felder an onbeforesubmit weiter.
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
        // Ein automatisches Absenden speichert die Zeile — eine gespeicherte
        // Preiszeile ginge mit neuer Währung und altem Netto live.
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
        // Kein maxval: Widget::validator() prüft es, bevor Contao ein Komma in
        // einen Punkt verwandelt — CoupledPriceInputListener::assertFits() prüft
        // stattdessen das errechnete Netto.
        $eval = $GLOBALS['TL_DCA']['tl_consho_price']['fields']['amount']['eval'];

        self::assertTrue($eval['mandatory']);
        self::assertSame('digit', $eval['rgxp']);
        self::assertArrayNotHasKey('maxval', $eval);
    }

    public function testEinePreiszeileErbtKeinenEuroVorgabewert(): void
    {
        // „Lieber laut als still, ich denke das wäre auch internationaler"
        // (Michael, 2026-09-21). Die Maske belegt die Währung seit C-1b richtig
        // vor — CoupledPriceInputListener überschreibt den Vorgabewert je
        // Request —, aber das erreicht nur die Maske. Eine Zeile, die daran
        // vorbei entsteht (Modell, CLI, Migration), wurde sonst still zu einem
        // Europreis, auch in einem Shop, der gar nicht in Euro verkauft.
        self::assertSame('', $GLOBALS['TL_DCA']['tl_consho_price']['fields']['currency']['sql']['default']);
    }

    public function testContaoLegtFuerDenBetragKeineJsonSpalteAn(): void
    {
        // VirtualFieldsMappingListener läuft als loadDataContainer-Hook vor
        // jeder eigenen Anpassung und würde ein Feld ohne 'sql' in eine
        // jsonData-Spalte umbiegen — genau das soll 'sql' => null verhindern.
        (new VirtualFieldsMappingListener())('tl_consho_price');

        self::assertArrayNotHasKey('targetColumn', $GLOBALS['TL_DCA']['tl_consho_price']['fields']['amount']);
        self::assertArrayNotHasKey('jsonData', $GLOBALS['TL_DCA']['tl_consho_price']['fields']);
    }
}

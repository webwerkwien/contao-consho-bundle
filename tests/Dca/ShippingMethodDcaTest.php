<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Contao\CoreBundle\EventListener\VirtualFieldsMappingListener;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;

class ShippingMethodDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_shipping_method.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testDerTypIstPflichtUndWechseltDiePalette(): void
    {
        $dca = $GLOBALS['TL_DCA']['tl_consho_shipping_method'];

        self::assertSame(['type'], $dca['palettes']['__selector__']);
        self::assertTrue($dca['fields']['type']['eval']['mandatory']);
        self::assertTrue($dca['fields']['type']['eval']['submitOnChange']);
        self::assertArrayHasKey('flat_rate', $dca['palettes']);
    }

    public function testDieSteuerregelKenntGenauDieWerteDesEnums(): void
    {
        // C4: anteilig ist der Standard.
        $taxMode = $GLOBALS['TL_DCA']['tl_consho_shipping_method']['fields']['taxMode'];

        self::assertSame(array_map(static fn (ShippingTaxMode $m): string => $m->value, ShippingTaxMode::cases()), $taxMode['options']);
        self::assertSame('proportional', $taxMode['sql']['default']);
    }

    public function testDieBezugsSteuerklasseIstImmerPflicht(): void
    {
        // Entscheidung 2026-09-15: auch bei „anteilig" — sie rechnet eine
        // Brutto-Eingabe des Versandbetrags in netto um.
        $taxClass = $GLOBALS['TL_DCA']['tl_consho_shipping_method']['fields']['taxClass'];

        self::assertTrue($taxClass['eval']['mandatory']);
        self::assertStringContainsString('taxClass', $GLOBALS['TL_DCA']['tl_consho_shipping_method']['palettes']['flat_rate']);
    }

    public function testDieJsonSpalteFuerModuleinstellungenIstAuchOhneModulfelderDa(): void
    {
        // Spec 5.1. Contao legte sie erst mit dem ersten virtuellen Feld an —
        // DbalShippingMethodRepository liest sie aber für jede Art.
        (new VirtualFieldsMappingListener())('tl_consho_shipping_method');

        $jsonData = $GLOBALS['TL_DCA']['tl_consho_shipping_method']['fields']['jsonData'];

        self::assertSame('json', $jsonData['sql']['type']);
        self::assertArrayNotHasKey('inputType', $jsonData);
        // Ohne Modulfeld kein virtuelles Ziel — „Details" zeigte sonst eine leere Zeile.
        self::assertTrue($jsonData['eval']['doNotShow']);
    }

    public function testOhneTypUndPauschaleTeilenSichEinePaletteMitTitelUndBeschreibung(): void
    {
        // Einmal gebaut: eine Änderung an einer der beiden fehlte sonst still in der anderen.
        // Die Pauschale hat seit E-3 zusätzlich das Häkchen „Nachnahme möglich" (Z2).
        $palettes = $GLOBALS['TL_DCA']['tl_consho_shipping_method']['palettes'];

        self::assertSame(str_replace(';{publish_legend}', ';{cod_legend},cashOnDelivery;{publish_legend}', $palettes['default']), $palettes['flat_rate']);
        self::assertStringContainsString('title,type,description', $palettes['default']);
        self::assertStringNotContainsString('cashOnDelivery', $palettes['default'], 'ein Modul erklärt Nachnahme im Code, nicht per Häkchen');
    }

    public function testLoeschenIstDieStandardOperationDesKerns(): void
    {
        // Nur eine namentliche Operation (ohne eigenes Array) bekommt von
        // DefaultOperationsListener die DeleteAction-Prüfung angehängt — sonst
        // greift MethodInUseVoter nicht und der Button wird nie ausgegraut.
        //
        // Und das `!` ist kein Schmuck: Es setzt `primary`. Ohne das stünde
        // der Knopf nur im ⋯-Menü, und eine ausgegraute Operation zeichnet
        // das Template dort überhaupt nicht — der Hinweis wäre unsichtbar.
        $operations = $GLOBALS['TL_DCA']['tl_consho_shipping_method']['list']['operations'];

        self::assertContains('!delete', $operations);
        self::assertArrayNotHasKey('delete', $operations);
    }
}

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
        // C4: pro rata is the default.
        $taxMode = $GLOBALS['TL_DCA']['tl_consho_shipping_method']['fields']['taxMode'];

        self::assertSame(array_map(static fn (ShippingTaxMode $m): string => $m->value, ShippingTaxMode::cases()), $taxMode['options']);
        self::assertSame('proportional', $taxMode['sql']['default']);
    }

    public function testDieBezugsSteuerklasseIstImmerPflicht(): void
    {
        // Also with "pro rata" — it converts a gross entry of the shipping
        // amount into net.
        $taxClass = $GLOBALS['TL_DCA']['tl_consho_shipping_method']['fields']['taxClass'];

        self::assertTrue($taxClass['eval']['mandatory']);
        self::assertStringContainsString('taxClass', $GLOBALS['TL_DCA']['tl_consho_shipping_method']['palettes']['flat_rate']);
    }

    public function testDieJsonSpalteFuerModuleinstellungenIstAuchOhneModulfelderDa(): void
    {
        // Spec 5.1. Contao only created it with the first virtual field —
        // but DbalShippingMethodRepository reads it for every method.
        (new VirtualFieldsMappingListener())('tl_consho_shipping_method');

        $jsonData = $GLOBALS['TL_DCA']['tl_consho_shipping_method']['fields']['jsonData'];

        self::assertSame('json', $jsonData['sql']['type']);
        self::assertArrayNotHasKey('inputType', $jsonData);
        // Without a module field there is no virtual target — "Details" would otherwise show an empty row.
        self::assertTrue($jsonData['eval']['doNotShow']);
    }

    public function testOhneTypUndPauschaleTeilenSichEinePaletteMitTitelUndBeschreibung(): void
    {
        // Built once: a change to one of the two would otherwise be silently missing from the other.
        // Since E-3 the flat rate also has the "Cash on delivery possible" checkbox (Z2).
        $palettes = $GLOBALS['TL_DCA']['tl_consho_shipping_method']['palettes'];

        self::assertSame(str_replace(';{publish_legend}', ';{cod_legend},cashOnDelivery;{publish_legend}', $palettes['default']), $palettes['flat_rate']);
        self::assertStringContainsString('title,type,description', $palettes['default']);
        self::assertStringNotContainsString('cashOnDelivery', $palettes['default'], 'ein Modul erklärt Nachnahme im Code, nicht per Häkchen');
    }

    public function testLoeschenIstDieStandardOperationDesKerns(): void
    {
        // Only a named operation (without an array of its own) gets the
        // DeleteAction check attached by DefaultOperationsListener — otherwise
        // MethodInUseVoter does not take effect and the button is never greyed out.
        //
        // And the `!` is not decoration: it sets `primary`. Without it the
        // button would only stand in the ⋯ menu, and the template does not draw
        // a greyed-out operation there at all — the hint would be invisible.
        $operations = $GLOBALS['TL_DCA']['tl_consho_shipping_method']['list']['operations'];

        self::assertContains('!delete', $operations);
        self::assertArrayNotHasKey('delete', $operations);
    }
}

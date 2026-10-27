<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Contao\CoreBundle\EventListener\VirtualFieldsMappingListener;
use PHPUnit\Framework\TestCase;

class ShippingPriceDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_shipping_price.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testDieBetraegeHaengenAnDerVersandart(): void
    {
        self::assertSame('tl_consho_shipping_method', $GLOBALS['TL_DCA']['tl_consho_shipping_price']['config']['ptable']);
    }

    public function testDerBetragHatBewusstKeineSpalte(): void
    {
        // As with the product prices: without 'sql' => null Contao would create jsonData.
        $amount = $GLOBALS['TL_DCA']['tl_consho_shipping_price']['fields']['amount'];

        self::assertArrayHasKey('sql', $amount);
        self::assertNull($amount['sql']);

        (new VirtualFieldsMappingListener())('tl_consho_shipping_price');

        self::assertArrayNotHasKey('jsonData', $GLOBALS['TL_DCA']['tl_consho_shipping_price']['fields']);
    }

    public function testBetragEingabeartUndLandKommenBeimSpeichernImmerAn(): void
    {
        foreach (['amount', 'inputMode', 'inputCountry'] as $field) {
            self::assertTrue($GLOBALS['TL_DCA']['tl_consho_shipping_price']['fields'][$field]['eval']['alwaysSave'] ?? false, $field);
        }
    }

    public function testDieWaehrungLaedtDieMaskeNichtNeu(): void
    {
        self::assertFalse($GLOBALS['TL_DCA']['tl_consho_shipping_price']['fields']['currency']['eval']['submitOnChange'] ?? false);
    }

    public function testNeueBetraegeStartenBrutto(): void
    {
        self::assertSame('gross', $GLOBALS['TL_DCA']['tl_consho_shipping_price']['fields']['inputMode']['default']);
    }

    public function testEinVersandbetragErbtKeinenEuroVorgabewert(): void
    {
        // The same reasoning as on tl_consho_price.currency:
        // a flat shipping rate that is silently in euros is not available in a
        // shop that only sells in pounds — and FlatRateShipping only throws in
        // the checkout.
        self::assertSame('', $GLOBALS['TL_DCA']['tl_consho_shipping_price']['fields']['currency']['sql']['default']);
    }
}

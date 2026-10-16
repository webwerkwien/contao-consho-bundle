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
        // Wie bei den Produktpreisen: ohne 'sql' => null legte Contao jsonData an.
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
        // Dieselbe Begründung wie an tl_consho_price.currency (2026-09-21):
        // Eine Versandpauschale, die still auf Euro lautet, ist in einem Shop,
        // der nur in Pfund verkauft, nicht verfügbar — und FlatRateShipping
        // wirft dann erst im Checkout.
        self::assertSame('', $GLOBALS['TL_DCA']['tl_consho_shipping_price']['fields']['currency']['sql']['default']);
    }
}

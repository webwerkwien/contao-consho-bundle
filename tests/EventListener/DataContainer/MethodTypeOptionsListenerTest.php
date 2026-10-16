<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\MethodTypeOptionsListener;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Shipping\FlatRateShipping;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingContext;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypeInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypes;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingQuote;

class MethodTypeOptionsListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testZurWahlStehenDieInstalliertenTypenMitIhremNamen(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_shipping_method']['types']['flat_rate'] = 'Versandpauschale';

        self::assertSame(['flat_rate' => 'Versandpauschale'], $this->listener()->shippingTypes());
    }

    public function testEinTypOhneSprachtextZeigtSeinenSchluessel(): void
    {
        // Ein Modul ohne deutsche Sprachdatei soll wählbar bleiben.
        self::assertSame(['flat_rate' => 'flat_rate'], $this->listener()->shippingTypes());
    }

    public function testDieTypenSindNachIhremNamenSortiertNichtNachDemSchluessel(): void
    {
        // Nach Schlüssel käme „Zustellung" (a_type) vor „Abholung" (flat_rate).
        $GLOBALS['TL_LANG']['tl_consho_shipping_method']['types'] = ['a_type' => 'Zustellung', 'flat_rate' => 'Abholung'];

        $delivery = new class implements ShippingMethodTypeInterface {
            public static function getType(): string
            {
                return 'a_type';
            }

            public function isAvailable(ShippingContext $context, array $config): bool
            {
                return true;
            }

            public function calculate(ShippingContext $context, array $config): ShippingQuote
            {
                return new ShippingQuote('0.0000');
            }

            public function capabilities(array $config): ShippingCapabilities
            {
                return new ShippingCapabilities(0, false);
            }
        };

        $listener = new MethodTypeOptionsListener(
            new ShippingMethodTypes(new ServiceLocator([
                'a_type' => static fn (): ShippingMethodTypeInterface => $delivery,
                'flat_rate' => static fn (): FlatRateShipping => new FlatRateShipping(),
            ])),
            new PaymentHandlers(new ServiceLocator([])),
        );

        self::assertSame(['flat_rate' => 'Abholung', 'a_type' => 'Zustellung'], $listener->shippingTypes());
    }

    public function testBeiDenZahlungsartenStehenDieInstalliertenHandlerZurWahl(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_payment_method']['types']['prepayment'] = 'Vorkasse';

        self::assertSame(['prepayment' => 'Vorkasse'], $this->listener()->paymentTypes());
    }

    private function listener(): MethodTypeOptionsListener
    {
        return new MethodTypeOptionsListener(
            new ShippingMethodTypes(new ServiceLocator(['flat_rate' => static fn (): FlatRateShipping => new FlatRateShipping()])),
            new PaymentHandlers(new ServiceLocator(['prepayment' => static fn (): PrepaymentHandler => new PrepaymentHandler()])),
        );
    }
}

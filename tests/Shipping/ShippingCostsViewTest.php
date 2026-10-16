<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use Contao\CoreBundle\Intl\Countries;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Shipping\FlatRateShipping;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingLimits;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethod;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypeInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypes;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCostsView;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;
use Webwerkwien\ContaoConshoBundle\Shipping\WeightRateShipping;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;

/**
 * E-6, V5: Die Versandkostenseite zeigt, was die Daten sagen — brutto mit dem
 * Satz des Shop-Landes (C25), Staffeln als Tabelle, Länder mit Namen.
 */
class ShippingCostsViewTest extends TestCase
{
    public function testPauschaleUndStaffel(): void
    {
        $view = $this->view([
            new ShippingMethod(1, 'flat_rate', 'Paket Österreich', ShippingTaxMode::Fixed, 1, ['net' => '5.0000', 'cashOnDelivery' => '1'], ['AT']),
            new ShippingMethod(2, 'weight_rate', 'Paket EU', ShippingTaxMode::Proportional, 1, ['rates' => [
                ['maxWeight' => '2.0000', 'net' => '10.0000'],
                ['maxWeight' => '0.0000', 'net' => '1.0000'],
                ['maxWeight' => '31.5000', 'net' => '20.0000'],
            ]], [], new ShippingLimits('120.0000', '0.0000', '31.5000')),
        ], [new PaymentMethod(5, 'cod', 'Nachnahme', [])])->of(1, 'EUR', 'AT', 'de', '', 1_790_000_000);

        [$flat, $weight] = $view['methods'];

        self::assertSame(['Österreich'], $flat['countries']);
        self::assertSame([['upTo' => '', 'amount' => '6,00 €']], array_map(static fn (array $p): array => ['upTo' => $p['upTo'], 'amount' => str_replace("\u{a0}", ' ', $p['amount'])], $flat['prices']));
        self::assertFalse($flat['proportional']);
        self::assertTrue($flat['cashOnDelivery']);

        self::assertSame([], $weight['countries'], 'leer heißt: alle Lieferländer');
        self::assertSame(['2', '31,5'], array_column($weight['prices'], 'upTo'), 'die Zeile „bis 0 kg" ist keine Stufe');
        self::assertTrue($weight['proportional']);
        self::assertSame(['length' => '120', 'girth' => '', 'weight' => '31,5'], $weight['limits']);
        self::assertFalse($view['codFee']);
    }

    /**
     * Eine Pauschale ohne Betrag in der Währung bietet auch der Checkout nicht
     * an — die Seite zeigt sie deshalb nicht.
     */
    public function testOhneBetragInDerWaehrungKeineZeile(): void
    {
        $view = $this->view([
            new ShippingMethod(1, 'flat_rate', 'Paket', ShippingTaxMode::Fixed, 1, ['net' => null]),
        ])->of(1, 'EUR', 'AT', 'de', '', 1_790_000_000);

        self::assertSame([], $view['methods']);
    }

    public function testEinModulRechnetImCheckout(): void
    {
        $view = $this->view([
            new ShippingMethod(1, 'carrier', 'Kurier', ShippingTaxMode::Fixed, 1, []),
        ])->of(1, 'EUR', 'AT', 'de', '', 1_790_000_000);

        self::assertTrue($view['methods'][0]['calculated']);
        self::assertSame([], $view['methods'][0]['prices']);
    }

    /**
     * Fable-Review E, K4: Das Häkchen an der Versandart allein verspricht
     * nichts, solange der Shop keine Nachnahme als Zahlungsart anbietet.
     */
    public function testOhneNachnahmeZahlungsartKeinHinweis(): void
    {
        $view = $this->view([
            new ShippingMethod(1, 'flat_rate', 'Paket', ShippingTaxMode::Fixed, 1, ['net' => '5.0000', 'cashOnDelivery' => '1']),
        ])->of(1, 'EUR', 'AT', 'de', '', 1_790_000_000);

        self::assertFalse($view['methods'][0]['cashOnDelivery']);
    }

    public function testDieNachnahmegebuehrAlsHinweis(): void
    {
        $payments = [new PaymentMethod(5, 'cod', 'Nachnahme', ['fee' => '3.0000', 'feeCurrency' => 'EUR'])];

        self::assertTrue($this->view([], $payments)->of(1, 'EUR', 'AT', 'de', '', 1_790_000_000)['codFee']);
    }

    /**
     * @param list<ShippingMethod> $methods
     * @param list<PaymentMethod>  $payments
     */
    private function view(array $methods, array $payments = []): ShippingCostsView
    {
        $repository = $this->createStub(ShippingMethodRepositoryInterface::class);
        $repository->method('forShop')->willReturn($methods);

        $paymentRepository = $this->createStub(PaymentMethodRepositoryInterface::class);
        $paymentRepository->method('forShop')->willReturn($payments);

        $carrier = new class implements ShippingMethodTypeInterface {
            public static function getType(): string
            {
                return 'carrier';
            }

            public function isAvailable(\Webwerkwien\ContaoConshoBundle\Shipping\ShippingContext $context, array $config): bool
            {
                return true;
            }

            public function calculate(\Webwerkwien\ContaoConshoBundle\Shipping\ShippingContext $context, array $config): \Webwerkwien\ContaoConshoBundle\Shipping\ShippingQuote
            {
                return new \Webwerkwien\ContaoConshoBundle\Shipping\ShippingQuote('9.0000');
            }

            public function capabilities(array $config): \Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities
            {
                return new \Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities(0, false);
            }
        };

        $types = new ShippingMethodTypes(new ServiceLocator([
            'flat_rate' => static fn (): ShippingMethodTypeInterface => new FlatRateShipping(),
            'weight_rate' => static fn (): ShippingMethodTypeInterface => new WeightRateShipping(),
            'carrier' => static fn (): ShippingMethodTypeInterface => $carrier,
        ]));

        $countries = $this->createStub(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE']);
        $countries->method('getCountries')->willReturn(['AT' => 'Österreich', 'DE' => 'Deutschland']);
        $provider = new CountryProvider($countries);

        $rates = $this->createStub(TaxRateRepositoryInterface::class);
        $rates->method('findRate')->willReturn('20.0000');

        return new ShippingCostsView($repository, $types, $paymentRepository, new GrossCalculator(new TaxResolver($rates, $provider)), new MoneyFormatter(), $provider);
    }
}

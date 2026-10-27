<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\CashOnDeliveryHandler;
use Webwerkwien\ContaoConshoBundle\Payment\OrderView;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustmentOrigin;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentContext;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStartOutcome;
use Webwerkwien\ContaoConshoBundle\Shipping\FlatRateShipping;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities;

/**
 * Cash on delivery (E-3, Z2): only with a shipping method that collects;
 * the only core payment method with a fee (Z4), net and in one currency.
 */
class CashOnDeliveryHandlerTest extends TestCase
{
    private const FEE = ['fee' => '3.5000', 'feeCurrency' => 'eur'];

    public function testDerSchluesselIstFestUndDieRechnungGehtInsPaket(): void
    {
        self::assertSame('cod', CashOnDeliveryHandler::getType());
        self::assertSame(DocumentTrigger::OnShipment, (new CashOnDeliveryHandler())->documentTrigger([]));
    }

    public function testNurWennDieGewaehlteVersandartKassiert(): void
    {
        $handler = new CashOnDeliveryHandler();

        self::assertTrue($handler->isAvailable($this->context(new ShippingCapabilities(0, false, true)), []));
        self::assertFalse($handler->isAvailable($this->context(new ShippingCapabilities(0, false, false)), []));
        self::assertFalse($handler->isAvailable($this->context(null), []), 'ohne gewählte Versandart kein Versprechen');
    }

    public function testDieGebuehrIstEinAufschlagNetto(): void
    {
        $adjustment = (new CashOnDeliveryHandler())->adjustment($this->context(new ShippingCapabilities(0, false, true)), self::FEE);

        self::assertNotNull($adjustment);
        self::assertSame(PaymentAdjustmentOrigin::Fee, $adjustment->origin);
        self::assertSame('3.5000', $adjustment->net);
        self::assertNull((new CashOnDeliveryHandler())->adjustment($this->context(null), []), 'ohne Gebühr kein Betrag');
    }

    /**
     * A fee in EUR cannot be charged in CHF — and a fee that cannot be charged
     * does not make cash on delivery free.
     */
    public function testInEinerAnderenWaehrungGibtEsKeinAngebot(): void
    {
        $handler = new CashOnDeliveryHandler();
        $collects = new ShippingCapabilities(0, false, true);

        self::assertTrue($handler->isAvailable($this->context($collects, 'EUR'), self::FEE));
        self::assertFalse($handler->isAvailable($this->context($collects, 'CHF'), self::FEE));
        self::assertTrue($handler->isAvailable($this->context($collects, 'CHF'), []), 'ohne Gebühr in jeder Währung');
    }

    public function testEineUnbrauchbareGebuehrGiltAlsKeine(): void
    {
        self::assertNull(CashOnDeliveryHandler::fee(['fee' => '0', 'feeCurrency' => 'EUR']));
        self::assertNull(CashOnDeliveryHandler::fee(['fee' => '3', 'feeCurrency' => '']));
        self::assertNull(CashOnDeliveryHandler::fee(['fee' => 'drei', 'feeCurrency' => 'EUR']));
        self::assertSame(['net' => '3', 'currency' => 'EUR'], CashOnDeliveryHandler::fee(['fee' => '3', 'feeCurrency' => 'eur']));
    }

    public function testDerStartNenntNurDenHinweis(): void
    {
        $start = (new CashOnDeliveryHandler())->start(
            new PaymentAttempt(1, 7, 3, 'cod', AttemptState::Open, '12.0000', 'EUR', '', 'https://shop.example/return', 'https://shop.example/notify'),
            new OrderView(7, 'WEB-00001', 1, 'EUR', '12.0000', 'kunde@example.com', 'de'),
            ['instructions' => '<p>Bitte halten Sie den Betrag bereit.</p>', 'iban' => 'AT611904300234573201'],
        );

        self::assertSame(PaymentStartOutcome::Completed, $start->outcome);
        self::assertSame('<p>Bitte halten Sie den Betrag bereit.</p>', $start->notice);
        self::assertSame([], $start->details, 'keine Bankverbindung: bezahlt wird an der Tür');
    }

    /**
     * The flat rate collects when the operator ticks the box — it depends on
     * his contract with the parcel service, not on the code.
     */
    public function testDiePauschaleKassiertNurMitHaekchen(): void
    {
        self::assertTrue((new FlatRateShipping())->capabilities(['cashOnDelivery' => true])->cashOnDelivery);
        self::assertTrue((new FlatRateShipping())->capabilities(['cashOnDelivery' => '1'])->cashOnDelivery, 'so speichert Contao ein Häkchen');
        self::assertFalse((new FlatRateShipping())->capabilities([])->cashOnDelivery);
        self::assertSame(0, (new FlatRateShipping())->capabilities(['cashOnDelivery' => true])->ageCheck, 'und prüft weiter kein Alter');
    }

    private function context(ShippingCapabilities|null $shipping, string $currency = 'EUR'): PaymentContext
    {
        return new PaymentContext(1, $currency, 'AT', 'AT', [], '50.0000', $shipping);
    }
}

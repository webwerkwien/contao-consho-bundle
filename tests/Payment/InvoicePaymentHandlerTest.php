<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\OrderView;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentContext;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStartOutcome;

/**
 * „Auf Rechnung" (E-2, Z1): Rechnung beim Versand, ein Zahlungsziel, die
 * üblichen Grenzen gegen das Ausfallrisiko — und keine Gebühr (Z4).
 */
class InvoicePaymentHandlerTest extends TestCase
{
    public function testDerSchluesselIstFestUndDieRechnungEntstehtBeimVersand(): void
    {
        self::assertSame('invoice', InvoicePaymentHandler::getType());
        self::assertSame(DocumentTrigger::OnShipment, (new InvoicePaymentHandler())->documentTrigger([]));
    }

    public function testKeineGebuehr(): void
    {
        self::assertNull((new InvoicePaymentHandler())->adjustment($this->context(), ['maxNet' => '']));
    }

    /**
     * Nachlese E5b (Michael, 2026-10-09: „Nicht anbieten"): mit dem Schalter
     * nicht für einen Korb mit digitalen Inhalten oder Gutscheinen.
     */
    public function testNichtFuerSofortNutzbareGueter(): void
    {
        $handler = new InvoicePaymentHandler();
        $digital = new PaymentContext(1, 'EUR', 'AT', 'AT', [], '10.0000', null, true);

        self::assertFalse($handler->isAvailable($digital, ['notHandedOutAtOnce' => '1']));
        self::assertTrue($handler->isAvailable($digital, []), 'ohne Schalter wie bisher');
        self::assertTrue($handler->isAvailable($this->context(), ['notHandedOutAtOnce' => '1']), 'nur Ware: angeboten');
    }

    /**
     * Nachlese E5a: die Nachfrist, leer 14 Tage wie das Zahlungsziel.
     */
    public function testNachfrist(): void
    {
        self::assertSame(14, InvoicePaymentHandler::graceDays([]));
        self::assertSame(14, InvoicePaymentHandler::graceDays(['graceDays' => '']));
        self::assertSame(0, InvoicePaymentHandler::graceDays(['graceDays' => '0']));
        self::assertSame(30, InvoicePaymentHandler::graceDays(['graceDays' => '30']));
    }

    public function testOhneGrenzenFuerAlleAuchGaeste(): void
    {
        self::assertTrue((new InvoicePaymentHandler())->isAvailable($this->context(groups: []), []));
    }

    public function testMitGruppenNurFuerDieseGruppenUndNichtFuerGaeste(): void
    {
        $handler = new InvoicePaymentHandler();
        // So speichert Contao eine Mehrfachauswahl im JSON: als Zeichenketten.
        $config = ['allowedGroups' => ['3', '5']];

        self::assertTrue($handler->isAvailable($this->context(groups: [1, 5]), $config), 'eine der Gruppen reicht');
        self::assertFalse($handler->isAvailable($this->context(groups: [1, 2]), $config));
        self::assertFalse($handler->isAvailable($this->context(groups: []), $config), 'ein Gast ist in keiner Gruppe');
    }

    public function testNurBeiGleichemLand(): void
    {
        $handler = new InvoicePaymentHandler();

        self::assertTrue($handler->isAvailable($this->context(billing: 'AT', delivery: 'AT'), ['sameCountry' => true]));
        self::assertFalse($handler->isAvailable($this->context(billing: 'AT', delivery: 'DE'), ['sameCountry' => true]));
        self::assertTrue($handler->isAvailable($this->context(billing: 'AT', delivery: 'DE'), ['sameCountry' => false]));
    }

    public function testDerHoechstbetragGiltBisEinschliesslich(): void
    {
        $handler = new InvoicePaymentHandler();
        $config = ['maxNet' => '500', 'maxNetCurrency' => 'eur'];

        self::assertTrue($handler->isAvailable($this->context(net: '500.0000'), $config));
        self::assertFalse($handler->isAvailable($this->context(net: '500.0100'), $config));
    }

    /**
     * Ein Höchstbetrag in EUR sagt nichts über CHF. Eine Grenze, die sich
     * nicht vergleichen lässt, darf nicht zu „keine Grenze" werden.
     */
    public function testInEinerAnderenWaehrungGibtEsKeinAngebot(): void
    {
        $handler = new InvoicePaymentHandler();

        self::assertFalse($handler->isAvailable($this->context(net: '10.0000', currency: 'CHF'), ['maxNet' => '500', 'maxNetCurrency' => 'EUR']));
        self::assertFalse($handler->isAvailable($this->context(net: '10.0000'), ['maxNet' => '500', 'maxNetCurrency' => '']), 'ohne Währung vergleicht er mit nichts');
        self::assertTrue($handler->isAvailable($this->context(net: '10.0000', currency: 'CHF'), ['maxNet' => '', 'maxNetCurrency' => 'EUR']), 'ohne Betrag keine Grenze');
    }

    public function testDasZahlungszielHatEineVorgabe(): void
    {
        self::assertSame(14, InvoicePaymentHandler::dueDays([]));
        self::assertSame(14, InvoicePaymentHandler::dueDays(['dueDays' => '']));
        self::assertSame(30, InvoicePaymentHandler::dueDays(['dueDays' => '30']));
        self::assertSame(0, InvoicePaymentHandler::dueDays(['dueDays' => '0']), 'sofort fällig ist eine Wahl, keine Lücke');
        self::assertSame(14, InvoicePaymentHandler::dueDays(['dueDays' => 'bald']));
    }

    public function testDerStartIstSofortAbgeschlossenMitHinweisUndBankverbindung(): void
    {
        $start = (new InvoicePaymentHandler())->start(
            new PaymentAttempt(1, 7, 3, 'invoice', AttemptState::Open, '12.0000', 'EUR', '', 'https://shop.example/return', 'https://shop.example/notify'),
            new OrderView(7, 'WEB-00001', 1, 'EUR', '12.0000', 'kunde@example.com', 'de'),
            ['instructions' => '<p>Zahlbar binnen 14 Tagen.</p>', 'iban' => 'AT611904300234573201', 'accountHolder' => 'Wanderladen'],
        );

        self::assertSame(PaymentStartOutcome::Completed, $start->outcome);
        self::assertSame('<p>Zahlbar binnen 14 Tagen.</p>', $start->notice);
        self::assertSame(['accountHolder' => 'Wanderladen', 'iban' => 'AT611904300234573201', 'bic' => '', 'bankName' => ''], $start->details);
    }

    /**
     * @param list<int> $groups
     */
    private function context(array $groups = [], string $billing = 'AT', string $delivery = 'AT', string $net = '100.0000', string $currency = 'EUR'): PaymentContext
    {
        return new PaymentContext(1, $currency, $billing, $delivery, $groups, $net);
    }
}

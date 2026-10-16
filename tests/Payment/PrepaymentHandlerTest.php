<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationOutcome;
use Webwerkwien\ContaoConshoBundle\Payment\OrderView;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentContext;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStartOutcome;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;

class PrepaymentHandlerTest extends TestCase
{
    private const CONFIG = [
        'instructions' => '<p>Bitte überweisen Sie binnen 7 Tagen.</p>',
        'accountHolder' => 'web.example.net',
        'iban' => 'AT611904300234573201',
        'bic' => 'BKAUATWW',
        'bankName' => 'Bank Austria',
    ];

    public function testDerSchluesselIstFest(): void
    {
        self::assertSame('prepayment', PrepaymentHandler::getType());
    }

    public function testVorkasseIstImmerVerfuegbarUndOhneBetrag(): void
    {
        $context = new PaymentContext(1, 'EUR', 'AT', 'AT', [], '10.0000');

        self::assertTrue((new PrepaymentHandler())->isAvailable($context, []));
        self::assertNull((new PrepaymentHandler())->adjustment($context, []));
    }

    public function testDieRechnungEntstehtBeiBestellung(): void
    {
        // C6: bei Vorkasse gehört die Rechnungsnummer auf die Rechnung vor der Zahlung.
        self::assertSame(DocumentTrigger::OnOrder, (new PrepaymentHandler())->documentTrigger([]));
    }

    public function testDerStartIstSofortAbgeschlossenMitHinweisUndBankverbindung(): void
    {
        $start = (new PrepaymentHandler())->start($this->attempt(), $this->order(), self::CONFIG);

        self::assertSame(PaymentStartOutcome::Completed, $start->outcome);
        self::assertSame('<p>Bitte überweisen Sie binnen 7 Tagen.</p>', $start->notice);
        self::assertSame(['accountHolder' => 'web.example.net', 'iban' => 'AT611904300234573201', 'bic' => 'BKAUATWW', 'bankName' => 'Bank Austria'], $start->details);
    }

    public function testFehlendeEinstellungenKommenAlsLeereTexteAn(): void
    {
        $start = (new PrepaymentHandler())->start($this->attempt(), $this->order(), ['iban' => 'AT611904300234573201', 'unrelated' => 5]);

        self::assertSame('', $start->notice);
        self::assertSame(['accountHolder' => '', 'iban' => 'AT611904300234573201', 'bic' => '', 'bankName' => ''], $start->details);
    }

    public function testRueckkehrUndBenachrichtigungSchreibenNichts(): void
    {
        // Den Zahlungseingang trägt der Betreiber in A's Maske ein.
        $handler = new PrepaymentHandler();

        self::assertSame(AttemptState::Pending, $handler->handleReturn(new Request(), $this->attempt(), []));
        self::assertSame(NotificationOutcome::Ignored, $handler->handleNotification(new Request(), $this->attempt(), [])->outcome);
    }

    private function attempt(): PaymentAttempt
    {
        return new PaymentAttempt(1, 7, 3, 'prepayment', AttemptState::Open, '12.0000', 'EUR', '', 'https://shop.example/return', 'https://shop.example/notify');
    }

    private function order(): OrderView
    {
        return new OrderView(7, 'B-2026-0001', 1, 'EUR', '12.0000', 'kunde@example.com', 'de');
    }
}

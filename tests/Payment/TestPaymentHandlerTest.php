<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\ChargeOutcome;
use Webwerkwien\ContaoConshoBundle\Payment\RecurringPaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\SubscriptionLineView;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationOutcome;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationRejected;
use Webwerkwien\ContaoConshoBundle\Payment\OrderView;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentContext;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStartOutcome;
use Webwerkwien\ContaoConshoBundle\Payment\TestPaymentHandler;

class TestPaymentHandlerTest extends TestCase
{
    private const CONFIG = ['testSecret' => 'geheim-geheim-geheim'];

    public function testOhneGeheimnisNichtVerfuegbarUndKeinStart(): void
    {
        $handler = $this->handler();
        $context = new PaymentContext(1, 'EUR', 'AT', 'AT', [], '10.0000');

        self::assertTrue($handler->isAvailable($context, self::CONFIG));
        self::assertFalse($handler->isAvailable($context, ['testSecret' => '  ']));
        self::assertSame(PaymentStartOutcome::Failed, $handler->start($this->attempt(), $this->order(), [])->outcome);
    }

    /**
     * Mit QUERY_PARAMS_ALLOW_LIST streicht Contaos Cache-Kernel jeden
     * Parameter, den die Liste nicht trifft — auch an Routen. Der Befund
     * noticeParamStripped empfiehlt „consho_.*"; nur damit überleben diese.
     */
    public function testJederAdressparameterTraegtDasPraefix(): void
    {
        foreach ((new \ReflectionClass(TestPaymentHandler::class))->getReflectionConstants() as $constant) {
            if (str_starts_with($constant->getName(), 'PARAM_')) {
                self::assertMatchesRegularExpression('/^consho_[a-z]+$/', $constant->getValue(), $constant->getName());
            }
        }
    }

    public function testDieRechnungEntstehtBeiZahlung(): void
    {
        self::assertSame(DocumentTrigger::OnPayment, $this->handler()->documentTrigger(self::CONFIG));
    }

    public function testDerStartLeitetZurTestseiteMitUnterschriebenerSitzung(): void
    {
        $captured = [];
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static function (string $name, array $parameters) use (&$captured): string {
            $captured = [$name, $parameters];

            return 'https://shop.test/_consho/test-payment';
        });

        $start = (new TestPaymentHandler($router))->start($this->attempt(), $this->order(), self::CONFIG);

        self::assertSame(PaymentStartOutcome::Redirect, $start->outcome);
        self::assertSame('consho_test_payment', $captured[0]);
        self::assertSame(7, $captured[1]['consho_method']);

        $session = TestPaymentHandler::decode($captured[1]['consho_session'], $captured[1]['consho_signature'], self::CONFIG);

        self::assertSame(3, $session['attempt'] ?? null);
        self::assertSame('13.9000', $session['amount'] ?? null);
        self::assertSame('https://shop.test/notify/tok', $session['notify'] ?? null);
        self::assertSame('https://shop.test/return/tok', $session['return'] ?? null);

        // Gegenprobe: ein anderes Geheimnis erkennt die Sitzung nicht.
        self::assertNull(TestPaymentHandler::decode($captured[1]['consho_session'], $captured[1]['consho_signature'], ['testSecret' => 'anderes-geheimnis!!']));
    }

    /**
     * Abo-4b, P100: Die Sitzung sagt der Testseite, ob ein Mandat auszugeben ist.
     */
    public function testDieSitzungSagtObDieBestellungAbosAnlegt(): void
    {
        $captured = [];
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static function (string $name, array $parameters) use (&$captured): string {
            $captured = $parameters;

            return 'https://shop.test/_consho/test-payment';
        });

        $recurring = new OrderView(1, 'WEB-00001', 1, 'EUR', '13.9000', 'a@b.test', 'de', [new SubscriptionLineView('WEB-00001-1', 'Abo', '13.9000', 1, 12)]);

        (new TestPaymentHandler($router))->start($this->attempt(), $recurring, self::CONFIG);
        $session = TestPaymentHandler::decode($captured['consho_session'], $captured['consho_signature'], self::CONFIG);
        self::assertSame(1, $session['recurring'] ?? null);

        (new TestPaymentHandler($router))->start($this->attempt(), $this->order(), self::CONFIG);
        $session = TestPaymentHandler::decode($captured['consho_session'], $captured['consho_signature'], self::CONFIG);
        self::assertSame(0, $session['recurring'] ?? null);
    }

    public function testDieBezahltMeldungTraegtDasMandat(): void
    {
        $result = $this->handler()->handleNotification($this->notification(['attempt' => 3, 'event' => 'paid', 'amount' => '13.90', 'reference' => 'T-1', 'mandate' => 'test-mandate-3']), $this->attempt(), self::CONFIG);

        self::assertSame('test-mandate-3', $result->mandate);
        self::assertSame('', $this->handler()->handleNotification($this->notification(['attempt' => 3, 'event' => 'paid', 'amount' => '13.90']), $this->attempt(), self::CONFIG)->mandate);
    }

    /**
     * P100: Die Option an der Zahlungsart stellt ein, was die Abbuchung ergibt;
     * ohne Wahl bezahlt. Ein fremdes Mandat oder kein Geheimnis: fehlgeschlagen.
     */
    public function testDieAbbuchungAntwortetWasDieOptionSagt(): void
    {
        // Der Kern fragt instanceof — der Test-Anbieter muss es sein (P100).
        self::assertContains(RecurringPaymentHandlerInterface::class, class_implements(TestPaymentHandler::class) ?: []);
        $handler = $this->handler();

        $paid = $handler->charge($this->attempt(), $this->order(), 'test-mandate-1', self::CONFIG);
        self::assertSame([ChargeOutcome::Paid, '13.9000', 'TEST-CHARGE-3'], [$paid->outcome, $paid->amount, $paid->reference]);

        self::assertSame(ChargeOutcome::Paid, $handler->charge($this->attempt(), $this->order(), 'test-mandate-1', self::CONFIG + ['testFollowUpResult' => 'paid'])->outcome);
        self::assertSame(ChargeOutcome::Pending, $handler->charge($this->attempt(), $this->order(), 'test-mandate-1', self::CONFIG + ['testFollowUpResult' => 'pending'])->outcome);
        self::assertSame(ChargeOutcome::Failed, $handler->charge($this->attempt(), $this->order(), 'test-mandate-1', self::CONFIG + ['testFollowUpResult' => 'failed'])->outcome);
        self::assertSame(ChargeOutcome::Paid, $handler->charge($this->attempt(), $this->order(), 'test-mandate-1', self::CONFIG + ['testFollowUpResult' => 'unsinn'])->outcome);

        self::assertSame(ChargeOutcome::Failed, $handler->charge($this->attempt(), $this->order(), 'cus_1|pm_2', self::CONFIG)->outcome);
        self::assertSame(ChargeOutcome::Failed, $handler->charge($this->attempt(), $this->order(), 'test-mandate-1', [])->outcome);
    }

    public function testDieRueckkehrMeldetErfolgNurMitGueltigerUnterschrift(): void
    {
        $handler = $this->handler();
        $signed = TestPaymentHandler::sign(TestPaymentHandler::returnData(3, 'paid'), 'geheim-geheim-geheim');

        self::assertSame(AttemptState::Succeeded, $handler->handleReturn(new Request(['consho_result' => 'paid', 'consho_signature' => $signed]), $this->attempt(), self::CONFIG));

        // Von Hand getippt, fremder Versuch, ohne Unterschrift: nichts belegt.
        self::assertSame(AttemptState::Pending, $handler->handleReturn(new Request(['consho_result' => 'paid', 'consho_signature' => 'abc']), $this->attempt(), self::CONFIG));
        self::assertSame(AttemptState::Pending, $handler->handleReturn(new Request(['consho_result' => 'paid', 'consho_signature' => $signed]), $this->attempt(4), self::CONFIG));
        self::assertSame(AttemptState::Pending, $handler->handleReturn(new Request(['consho_result' => 'paid']), $this->attempt(), self::CONFIG));

        $cancelled = TestPaymentHandler::sign(TestPaymentHandler::returnData(3, 'cancelled'), 'geheim-geheim-geheim');
        self::assertSame(AttemptState::Cancelled, $handler->handleReturn(new Request(['consho_result' => 'cancelled', 'consho_signature' => $cancelled]), $this->attempt(), self::CONFIG));
    }

    public function testEineUnterschriebeneBenachrichtigungMeldetDieZahlung(): void
    {
        $result = $this->handler()->handleNotification($this->notification(['attempt' => 3, 'event' => 'paid', 'amount' => '13.90', 'reference' => 'T-1']), $this->attempt(), self::CONFIG);

        self::assertSame(NotificationOutcome::Paid, $result->outcome);
        self::assertSame('13.9000', $result->amount);
        self::assertSame('T-1', $result->reference);

        $failed = $this->handler()->handleNotification($this->notification(['attempt' => 3, 'event' => 'failed']), $this->attempt(), self::CONFIG);
        self::assertSame(NotificationOutcome::Failed, $failed->outcome);

        $other = $this->handler()->handleNotification($this->notification(['attempt' => 3, 'event' => 'refund.created']), $this->attempt(), self::CONFIG);
        self::assertSame(NotificationOutcome::Ignored, $other->outcome);
    }

    /**
     * Review C-11, Befund 3: sonst wirft paid(), der Prozessor antwortet 500,
     * und ein Anbieter sendet einen 5xx endlos neu.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function unbrauchbareBetraege(): iterable
    {
        yield 'null' => ['0'];
        yield 'fehlt' => [null];
        yield 'Text' => ['dreizehn'];
        yield 'negativ' => ['-5'];
    }

    /**
     * @dataProvider unbrauchbareBetraege
     */
    public function testBezahltOhneBrauchbarenBetragWirdAbgelehnt(mixed $amount): void
    {
        $data = ['attempt' => 3, 'event' => 'paid'] + (null === $amount ? [] : ['amount' => $amount]);

        $this->expectException(NotificationRejected::class);

        $this->handler()->handleNotification($this->notification($data), $this->attempt(), self::CONFIG);
    }

    public function testEineFalscheUnterschriftWirdAbgelehnt(): void
    {
        $request = $this->notification(['attempt' => 3, 'event' => 'paid', 'amount' => '13.90']);
        $request->headers->set(TestPaymentHandler::SIGNATURE_HEADER, str_repeat('0', 64));

        $this->expectException(NotificationRejected::class);

        $this->handler()->handleNotification($request, $this->attempt(), self::CONFIG);
    }

    public function testEineFuerEinenAnderenVersuchUnterschriebeneBenachrichtigungWirdAbgelehnt(): void
    {
        $this->expectException(NotificationRejected::class);

        $this->handler()->handleNotification($this->notification(['attempt' => 3, 'event' => 'paid', 'amount' => '13.90']), $this->attempt(4), self::CONFIG);
    }

    /**
     * @param array<string, int|string> $data
     */
    private function notification(array $data): Request
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $body);
        $request->headers->set(TestPaymentHandler::SIGNATURE_HEADER, TestPaymentHandler::sign($body, 'geheim-geheim-geheim'));

        return $request;
    }

    private function handler(): TestPaymentHandler
    {
        return new TestPaymentHandler($this->createMock(UrlGeneratorInterface::class));
    }

    private function attempt(int $id = 3): PaymentAttempt
    {
        return new PaymentAttempt($id, 1, 7, 'test', AttemptState::Open, '13.9000', 'EUR', '', 'https://shop.test/return/tok', 'https://shop.test/notify/tok');
    }

    private function order(): OrderView
    {
        return new OrderView(1, 'WEB-00001', 1, 'EUR', '13.9000', 'a@b.test', 'de');
    }
}

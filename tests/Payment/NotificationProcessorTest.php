<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationProcessor;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationResult;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\TestPaymentHandler;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionMandates;

/**
 * 7.4 und Abschnitt 12 mit dem echten Test-Anbieter: doppelt → eine Zahlung,
 * falsche Unterschrift → nichts, unbekannt → 404, Zahlung nach Abbruch wird
 * gebucht (Z3).
 */
class NotificationProcessorTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SECRET = 'geheim-geheim-geheim';

    private InMemoryPaymentAttempts $attempts;

    private Connection $connection;

    /** @var list<array{int, bool}> order id and online flag of every PaymentRecorded call */
    private array $handled = [];

    private \Throwable|null $consequencesFail = null;

    protected function setUp(): void
    {
        $this->attempts = new InMemoryPaymentAttempts();
        $this->attempts->add(new PaymentAttempt(3, 1, 7, 'test', AttemptState::Open, '13.9000', 'EUR', '', '', ''), self::TOKEN);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testEineBezahltMeldungBuchtGenauEinmal(): void
    {
        $processor = $this->processor();

        self::assertSame(200, $processor->process(self::TOKEN, $this->paid(), 100));
        self::assertSame(200, $processor->process(self::TOKEN, $this->paid(), 101));

        self::assertCount(1, $this->attempts->payments);
        self::assertSame(['attempt' => 3, 'amount' => '13.9000', 'reference' => 'T-1', 'method' => 'Test'], $this->attempts->payments[0]);
        self::assertSame(AttemptState::Succeeded, $this->attempts->attempts[3]->state);
        self::assertSame('T-1', $this->attempts->attempts[3]->providerReference);
        self::assertSame(2, $this->attempts->locks, 'jede Meldung sperrt, bevor sie entscheidet');
        self::assertFalse($this->connection->isTransactionActive());
    }

    /**
     * Wenn Sperre und Frühausstieg doch beide passiert würden: Der eindeutige
     * Schlüssel verweigert die zweite Zeile, und die Antwort bleibt 200.
     */
    public function testDerEindeutigeSchluesselHaeltAuchOhneFruehausstieg(): void
    {
        $this->attempts->payments[] = ['attempt' => 3, 'amount' => '13.9000', 'reference' => 'T-1', 'method' => 'Test'];

        self::assertSame(200, $this->processor()->process(self::TOKEN, $this->paid(), 100));
        self::assertCount(1, $this->attempts->payments);
        self::assertSame(AttemptState::Open, $this->attempts->attempts[3]->state, 'zurückgerollt');
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testNachEinemAbbruchWirdAngekommenesGeldTrotzdemGebucht(): void
    {
        $this->attempts->setState(3, AttemptState::Cancelled, 50);

        self::assertSame(200, $this->processor()->process(self::TOKEN, $this->paid(), 100));

        self::assertCount(1, $this->attempts->payments);
        self::assertSame(AttemptState::Succeeded, $this->attempts->attempts[3]->state);
    }

    public function testEinAndererBetragWirdGebuchtUndProtokolliert(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('5.0000 received, 13.9000 expected'));

        self::assertSame(200, $this->processor($logger)->process(self::TOKEN, $this->paid('5.00'), 100));
        self::assertSame('5.0000', $this->attempts->payments[0]['amount']);
    }

    public function testEineFalscheUnterschriftSchreibtNichts(): void
    {
        $request = $this->paid();
        $request->headers->set(TestPaymentHandler::SIGNATURE_HEADER, str_repeat('0', 64));

        self::assertSame(400, $this->processor()->process(self::TOKEN, $request, 100));
        self::assertSame([], $this->attempts->payments);
        self::assertSame(0, $this->attempts->locks);
        self::assertSame(AttemptState::Open, $this->attempts->attempts[3]->state);
    }

    public function testUnbekannterVersuchOderAbgeschalteterAnbieterSind404(): void
    {
        self::assertSame(404, $this->processor()->process(str_repeat('b', 64), $this->paid(), 100));
        self::assertSame(404, $this->processor(testPayment: null)->process(self::TOKEN, $this->paid(), 100));
        self::assertSame([], $this->attempts->payments);
    }

    public function testFehlgeschlagenNurFuerOffeneVersucheUndOhneZahlung(): void
    {
        self::assertSame(200, $this->processor()->process(self::TOKEN, $this->signed(['attempt' => 3, 'event' => 'failed']), 100));
        self::assertSame(AttemptState::Failed, $this->attempts->attempts[3]->state);

        $this->attempts->setState(3, AttemptState::Succeeded, 101);
        self::assertSame(200, $this->processor()->process(self::TOKEN, $this->signed(['attempt' => 3, 'event' => 'failed']), 102));
        self::assertSame(AttemptState::Succeeded, $this->attempts->attempts[3]->state, 'bezahlt bleibt bezahlt');
        self::assertSame([], $this->attempts->payments);
    }

    public function testEineUnwichtigeMeldungSperrtNichts(): void
    {
        self::assertSame(200, $this->processor()->process(self::TOKEN, $this->signed(['attempt' => 3, 'event' => 'refund.created']), 100));
        self::assertSame(0, $this->attempts->locks);
    }

    /**
     * D-1: Rechnung, Status und Mail laufen nach der Buchung — einmal, auch
     * wenn der Anbieter die Meldung zweimal schickt.
     */
    public function testDieFolgenLaufenGenauEinmalNachDerBuchung(): void
    {
        $processor = $this->processor();

        $processor->process(self::TOKEN, $this->paid(), 100);
        $processor->process(self::TOKEN, $this->paid(), 101);

        self::assertSame([[1, true]], $this->handled);
    }

    public function testOhneBuchungGibtEsKeineFolgen(): void
    {
        $this->processor()->process(self::TOKEN, $this->signed(['attempt' => 3, 'event' => 'failed']), 100);

        self::assertSame([], $this->handled);
    }

    /**
     * Das Geld ist gebucht. Eine 500 jetzt ließe den Anbieter eine Zahlung
     * erneut schicken, die längst drin ist.
     */
    public function testScheiternDieFolgenBleibtEs200(): void
    {
        $this->consequencesFail = new \RuntimeException('Mailserver weg');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('The consequences of the payment of attempt 3 failed: Mailserver weg'));

        self::assertSame(200, $this->processor($logger)->process(self::TOKEN, $this->paid(), 100));
        self::assertCount(1, $this->attempts->payments);
    }

    /**
     * P93: Das Mandat aus der Zahlungsmeldung kommt ans Abo — mit der
     * Zahlungsart des Versuchs, in derselben Transaktion wie das Geld.
     */
    public function testDasMandatEinesFolgezahlungsModulsKommtAnsAbo(): void
    {
        $handler = new FakeRecurringHandler();
        $handler->notification = NotificationResult::paid('13.90', 'ch_1', ' cus_1|pm_2 ');

        $mandates = $this->createMock(SubscriptionMandates::class);
        $mandates->expects(self::once())->method('record')
            ->willReturnCallback(function (int $orderId, int $methodId, string $mandate, int $now): int {
                self::assertSame([1, 7, 'cus_1|pm_2', 100], [$orderId, $methodId, $mandate, $now]);
                self::assertTrue($this->connection->isTransactionActive(), 'mit dem Geld, nicht danach');

                return 1;
            });

        self::assertSame(200, $this->moduleProcessor($handler, $mandates)->process(self::TOKEN, new Request(), 100));
        self::assertCount(1, $this->attempts->payments);
    }

    public function testEinModulOhneFolgezahlungenHinterlegtKeinMandat(): void
    {
        $handler = $this->createStub(PaymentHandlerInterface::class);
        $handler->method('handleNotification')->willReturn(NotificationResult::paid('13.90', 'ch_1', 'cus_1'));

        $mandates = $this->createMock(SubscriptionMandates::class);
        $mandates->expects(self::never())->method('record');

        self::assertSame(200, $this->moduleProcessor($handler, $mandates)->process(self::TOKEN, new Request(), 100));
        self::assertCount(1, $this->attempts->payments);
    }

    public function testFehlgeschlagenHinterlegtKeinMandat(): void
    {
        $handler = new FakeRecurringHandler();
        $handler->notification = NotificationResult::failed();

        $mandates = $this->createMock(SubscriptionMandates::class);
        $mandates->expects(self::never())->method('record');

        self::assertSame(200, $this->moduleProcessor($handler, $mandates)->process(self::TOKEN, new Request(), 100));
    }

    private function moduleProcessor(PaymentHandlerInterface $handler, SubscriptionMandates $mandates): NotificationProcessor
    {
        $this->attempts = new InMemoryPaymentAttempts();
        $this->attempts->add(new PaymentAttempt(3, 1, 7, 'card', AttemptState::Open, '13.9000', 'EUR', '', '', ''), self::TOKEN);

        $methods = $this->createMock(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturn(new PaymentMethod(7, 'card', 'Karte', []));

        return new NotificationProcessor(
            $this->connection,
            $this->attempts,
            $methods,
            new PaymentHandlers(new ServiceLocator(['card' => static fn () => $handler])),
            $this->createStub(PaymentRecorded::class),
            $this->createStub(LoggerInterface::class),
            $mandates,
        );
    }

    private function paid(string $amount = '13.90'): Request
    {
        return $this->signed(['attempt' => 3, 'event' => 'paid', 'amount' => $amount, 'reference' => 'T-1']);
    }

    /**
     * @param array<string, int|string> $data
     */
    private function signed(array $data): Request
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], $body);
        $request->headers->set(TestPaymentHandler::SIGNATURE_HEADER, TestPaymentHandler::sign($body, self::SECRET));

        return $request;
    }

    private function processor(LoggerInterface|null $logger = null, string|null $testPayment = '1'): NotificationProcessor
    {
        $methods = $this->createMock(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturn(new PaymentMethod(7, 'test', 'Test', ['testSecret' => self::SECRET]));

        $handler = new TestPaymentHandler($this->createMock(UrlGeneratorInterface::class));

        $recorded = $this->createMock(PaymentRecorded::class);
        $recorded->method('handle')->willReturnCallback(function (int $orderId, bool $online): void {
            $this->handled[] = [$orderId, $online];

            if (null !== $this->consequencesFail) {
                throw $this->consequencesFail;
            }
        });

        return new NotificationProcessor(
            $this->connection,
            $this->attempts,
            $methods,
            new PaymentHandlers(new ServiceLocator(['test' => static fn () => $handler]), $testPayment),
            $recorded,
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }
}

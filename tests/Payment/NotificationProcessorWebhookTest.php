<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationProcessor;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationRejected;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationResult;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\WebhookReference;

/**
 * The fixed webhook address (spec Zahlung PayPal, PP1) and the question
 * before an attempt lapses (PP2 d): both end in the same booking as a
 * per-attempt notification.
 */
class NotificationProcessorWebhookTest extends TestCase
{
    private InMemoryPaymentAttempts $attempts;

    private Connection $connection;

    private WebhookTestHandler $handler;

    /** @var list<int> order ids of every PaymentRecorded call */
    private array $handled = [];

    protected function setUp(): void
    {
        $this->attempts = new InMemoryPaymentAttempts();
        $this->attempts->add(new PaymentAttempt(3, 1, 7, 'hook', AttemptState::Open, '13.9000', 'EUR', 'ORDER-3', '', ''), 'token-3');
        $this->attempts->add(new PaymentAttempt(4, 2, 8, 'hook', AttemptState::Open, '20.0000', 'EUR', 'ORDER-3', '', ''), 'token-4');
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->handler = new WebhookTestHandler();
    }

    public function testUnbekannteZahlungsartIst404(): void
    {
        self::assertSame(404, $this->processor()->processWebhook(99, new Request(), 100));
    }

    public function testEinHandlerOhneWebhookSchnittstelleIst404(): void
    {
        $plain = $this->createStub(PaymentHandlerInterface::class);

        self::assertSame(404, $this->processor($plain)->processWebhook(7, new Request(), 100));
    }

    public function testEineFaelschungIst400UndSchreibtNichts(): void
    {
        $this->handler->reference = static fn () => throw new NotificationRejected('bad signature');

        self::assertSame(400, $this->processor()->processWebhook(7, new Request(), 100));
        self::assertSame([], $this->attempts->payments);
        self::assertSame(0, $this->attempts->locks);
    }

    public function testEinIgnoriertesEreignisIst200UndSchreibtNichts(): void
    {
        $this->handler->reference = static fn () => WebhookReference::ignored('another installation');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('another installation'));

        self::assertSame(200, $this->processor(null, $logger)->processWebhook(7, new Request(), 100));
        self::assertSame([], $this->attempts->payments);
        self::assertSame(0, $this->attempts->locks);
    }

    /**
     * Dropped by the handler, with its reason — the log tells it from a
     * notification that never came.
     */
    public function testEineVerworfeneNachrichtNenntIhrenGrundImLog(): void
    {
        $this->handler->reference = static fn () => WebhookReference::about('ORDER-3');
        $this->handler->notification = static fn () => NotificationResult::ignored('event CHECKOUT.ORDER.SAVED');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('event CHECKOUT.ORDER.SAVED'));

        self::assertSame(200, $this->processor(null, $logger)->processWebhook(7, new Request(), 100));
        self::assertSame([], $this->attempts->payments);
    }

    /**
     * Under way at the provider: nothing booked, nothing failed — the next
     * notification says how it ended.
     */
    public function testEineAusstehendeZahlungBuchtNichtsUndLaesstDenVersuchOffen(): void
    {
        $this->handler->reference = static fn () => WebhookReference::about('ORDER-3');
        $this->handler->notification = static fn () => NotificationResult::pending('ORDER-3');

        self::assertSame(200, $this->processor()->processWebhook(7, new Request(), 100));
        self::assertSame([], $this->attempts->payments);
        self::assertSame(AttemptState::Open, $this->attempts->attempts[3]->state);
        self::assertSame(0, $this->attempts->locks);
    }

    /**
     * Asked before the attempt lapses: pending keeps it — the cron does not
     * cancel what the provider still clears.
     */
    public function testEineNachfrageMitAusstehenderZahlungHaeltDenVersuch(): void
    {
        $this->handler->polled = static fn () => NotificationResult::pending('ORDER-3');

        self::assertTrue($this->processor()->processPolled($this->attempts->attempts[3], 100));
        self::assertSame([], $this->attempts->payments);
        self::assertSame(AttemptState::Open, $this->attempts->attempts[3]->state);
        self::assertSame([], $this->attempts->polled, 'nothing booked, nothing marked');
    }

    public function testEineUnbekannteReferenzIst200MitWarnung(): void
    {
        $this->handler->reference = static fn () => WebhookReference::about('ORDER-UNKNOWN');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('ORDER-UNKNOWN'));

        self::assertSame(200, $this->processor(null, $logger)->processWebhook(7, new Request(), 100));
        self::assertSame([], $this->attempts->payments);
    }

    /**
     * The same reference on another method's attempt is not this one's: the
     * method of the address decides.
     */
    public function testEineBekannteReferenzBuchtDenVersuchDerZahlungsart(): void
    {
        $this->handler->reference = static fn () => WebhookReference::about('ORDER-3');
        $this->handler->notification = static fn () => NotificationResult::paid('13.90', 'EUR', 'ORDER-3', 'CAPTURE-1');

        self::assertSame(200, $this->processor()->processWebhook(7, new Request(), 100));

        self::assertSame([['attempt' => 3, 'amount' => '13.9000', 'reference' => 'CAPTURE-1', 'method' => 'PayPal']], $this->attempts->payments);
        self::assertSame(AttemptState::Succeeded, $this->attempts->attempts[3]->state);
        self::assertSame(AttemptState::Open, $this->attempts->attempts[4]->state);
        self::assertSame([1], $this->handled);
        self::assertSame([], $this->attempts->polled, 'per Webhook, nicht per Nachfrage');
    }

    public function testDieVersuchsIdHilftNurBeiEinemVersuchOhneReferenzDerselbenZahlungsart(): void
    {
        $this->attempts->add(new PaymentAttempt(5, 3, 7, 'hook', AttemptState::Pending, '9.0000', 'EUR', '', '', '', null, 0, true), 'token-5');
        $this->handler->reference = static fn () => WebhookReference::about('ORDER-NEW', 5);
        $this->handler->notification = static fn () => NotificationResult::paid('9.00', 'EUR', 'ORDER-NEW', 'CAPTURE-5');

        self::assertSame(200, $this->processor()->processWebhook(7, new Request(), 100));
        self::assertSame(5, $this->attempts->payments[0]['attempt'] ?? null);
        self::assertSame('ORDER-NEW', $this->attempts->attempts[5]->providerReference, 'die Referenz wird übernommen (P94)');
    }

    public function testEineFremdeVersuchsIdWirdNichtGenommen(): void
    {
        $this->handler->reference = static fn () => WebhookReference::about('ORDER-NEW', 4);
        $this->handler->notification = static fn () => NotificationResult::paid('20.00', 'EUR', 'ORDER-NEW', 'CAPTURE-4');

        self::assertSame(200, $this->processor()->processWebhook(7, new Request(), 100), 'Versuch 4 gehört Zahlungsart 8');
        self::assertSame([], $this->attempts->payments);

        $this->handler->reference = static fn () => WebhookReference::about('ORDER-NEW', 3);

        self::assertSame(200, $this->processor()->processWebhook(7, new Request(), 101), 'Versuch 3 trägt schon eine Referenz');
        self::assertSame([], $this->attempts->payments);
    }

    public function testEineNachfrageBuchtUndMarkiert(): void
    {
        $this->handler->polled = static fn () => NotificationResult::paid('13.90', 'EUR', 'ORDER-3', 'CAPTURE-1');

        self::assertTrue($this->processor()->processPolled($this->attempts->attempts[3], 100));
        self::assertSame('CAPTURE-1', $this->attempts->payments[0]['reference'] ?? null);
        self::assertSame([3 => 100], $this->attempts->polled);
        self::assertSame([1], $this->handled);
    }

    public function testEineNachfrageOhneZahlungBuchtNichts(): void
    {
        $this->handler->polled = static fn () => NotificationResult::ignored();

        self::assertFalse($this->processor()->processPolled($this->attempts->attempts[3], 100));
        self::assertSame([], $this->attempts->payments);
        self::assertSame([], $this->attempts->polled);
    }

    public function testEineNachfrageZuEinemSchonBezahltenVersuchMarkiertNicht(): void
    {
        $this->attempts->setState(3, AttemptState::Succeeded, 90, 'ORDER-3');
        $this->attempts->payments[] = ['attempt' => 3, 'amount' => '13.9000', 'reference' => 'CAPTURE-1', 'method' => 'PayPal'];
        $this->handler->polled = static fn () => NotificationResult::paid('13.90', 'EUR', 'ORDER-3', 'CAPTURE-1');

        self::assertTrue($this->processor()->processPolled($this->attempts->attempts[3], 100));
        self::assertCount(1, $this->attempts->payments);
        self::assertSame([], $this->attempts->polled, 'der Webhook war schneller');
    }

    public function testEinHandlerOhneNachfrageWirdNichtGefragt(): void
    {
        $plain = $this->createMock(PaymentHandlerInterface::class);
        $plain->expects(self::never())->method('handleNotification');

        self::assertFalse($this->processor($plain)->processPolled($this->attempts->attempts[3], 100));
    }

    private function processor(PaymentHandlerInterface|null $handler = null, LoggerInterface|null $logger = null): NotificationProcessor
    {
        $methods = $this->createStub(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturnCallback(static fn (int $id): PaymentMethod|null => match ($id) {
            7, 8 => new PaymentMethod($id, 'hook', 'PayPal', []),
            default => null,
        });

        $recorded = $this->createStub(PaymentRecorded::class);
        $recorded->method('handle')->willReturnCallback(function (int $orderId): void {
            $this->handled[] = $orderId;
        });

        $handler ??= $this->handler;

        return new NotificationProcessor(
            $this->connection,
            $this->attempts,
            $methods,
            new PaymentHandlers(new ServiceLocator(['hook' => static fn () => $handler])),
            $recorded,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}

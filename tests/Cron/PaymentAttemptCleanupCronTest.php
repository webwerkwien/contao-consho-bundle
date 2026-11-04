<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cron;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Cron\PaymentAttemptCleanupCron;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationProcessor;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationResult;
use Webwerkwien\ContaoConshoBundle\Payment\OrderView;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustment;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentContext;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStart;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStarter;
use Webwerkwien\ContaoConshoBundle\Tests\Payment\InMemoryPaymentAttempts;
use Webwerkwien\ContaoConshoBundle\Tests\Payment\WebhookTestHandler;

/**
 * Orphaned open attempts: cancelled after expiry, the provider is told.
 */
class PaymentAttemptCleanupCronTest extends TestCase
{
    public function testAlteOffeneVersucheWerdenAbgebrochenUndDemAnbieterGemeldet(): void
    {
        $now = 100_000;
        $attempts = new InMemoryPaymentAttempts();
        $attempts->add(new PaymentAttempt(1, 1, 7, 'fake', AttemptState::Open, '10', 'EUR', '', '', '', null, $now - PaymentStarter::ATTEMPT_LIFETIME - 1), 'a');
        $attempts->add(new PaymentAttempt(2, 1, 7, 'fake', AttemptState::Open, '10', 'EUR', '', '', '', null, $now - 60), 'b');
        $attempts->add(new PaymentAttempt(3, 1, 7, 'fake', AttemptState::Pending, '10', 'EUR', '', '', '', null, 1), 'c');
        $attempts->add(new PaymentAttempt(4, 1, 7, 'fake', AttemptState::Open, '10', 'EUR', '', '', '', null, 0), 'd');

        $handler = new CancelSpy();
        $methods = $this->createMock(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturn(new PaymentMethod(7, 'fake', 'Karte', ['key' => 'x']));

        (new PaymentAttemptCleanupCron(
            $attempts,
            new PaymentHandlers(new ServiceLocator(['fake' => static fn () => $handler])),
            $methods,
            $this->createMock(LoggerInterface::class),
            static fn (): int => $now,
        ))();

        self::assertSame(AttemptState::Cancelled, $attempts->attempts[1]->state);
        self::assertSame(AttemptState::Open, $attempts->attempts[2]->state, 'jünger als 30 Minuten');
        self::assertSame(AttemptState::Pending, $attempts->attempts[3]->state, 'Vorkasse verfällt nie');
        self::assertSame(AttemptState::Open, $attempts->attempts[4]->state, 'ohne Startzeit nichts');
        self::assertSame([[1, ['key' => 'x']]], $handler->cancelled);
    }

    /**
     * Spec Zahlung PayPal, PP2 d: asked before it lapses, a paid attempt is
     * booked and stays succeeded.
     */
    public function testEinBeimAnbieterBezahlterVersuchWirdGebuchtStattVerworfen(): void
    {
        [$attempts, $handler, $cron] = $this->polling(static fn () => NotificationResult::paid('10.00', 'EUR', 'ORDER-1', 'CAPTURE-1'));

        $cron();

        self::assertSame(AttemptState::Succeeded, $attempts->attempts[1]->state);
        self::assertSame('CAPTURE-1', $attempts->payments[0]['reference'] ?? null);
        self::assertSame([1 => 100_000], $attempts->polled);
        self::assertSame([], $handler->cancelled);
    }

    public function testOhneZahlungBeimAnbieterWirdVerworfenWieBisher(): void
    {
        [$attempts, $handler, $cron] = $this->polling(static fn () => NotificationResult::ignored());

        $cron();

        self::assertSame(AttemptState::Cancelled, $attempts->attempts[1]->state);
        self::assertSame([], $attempts->payments);
        self::assertSame([1], $handler->cancelled);
    }

    /**
     * Still clearing at the provider: kept open, not cancelled there — asked
     * again on the next run.
     */
    public function testEineAusstehendeZahlungBleibtOffen(): void
    {
        [$attempts, $handler, $cron] = $this->polling(static fn () => NotificationResult::pending('ORDER-1'));

        $cron();

        self::assertSame(AttemptState::Open, $attempts->attempts[1]->state);
        self::assertSame([], $attempts->payments);
        self::assertSame([], $handler->cancelled);
    }

    public function testScheitertDieNachfrageWirdVerworfenUndProtokolliert(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('Asking the provider about lapsing payment attempt 1'));

        [$attempts, $handler, $cron] = $this->polling(static fn () => throw new \RuntimeException('down'), $logger);

        $cron();

        self::assertSame(AttemptState::Cancelled, $attempts->attempts[1]->state);
        self::assertSame([1], $handler->cancelled);
    }

    /**
     * @param \Closure(): NotificationResult $answer
     *
     * @return array{InMemoryPaymentAttempts, WebhookTestHandler, PaymentAttemptCleanupCron}
     */
    private function polling(\Closure $answer, LoggerInterface|null $logger = null): array
    {
        $now = 100_000;
        $attempts = new InMemoryPaymentAttempts();
        $attempts->add(new PaymentAttempt(1, 1, 7, 'hook', AttemptState::Open, '10.0000', 'EUR', 'ORDER-1', '', '', null, $now - PaymentStarter::ATTEMPT_LIFETIME - 1), 'a');

        $handler = new WebhookTestHandler();
        $handler->polled = $answer;
        $handlers = new PaymentHandlers(new ServiceLocator(['hook' => static fn () => $handler]));

        $methods = $this->createStub(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturn(new PaymentMethod(7, 'hook', 'PayPal', []));

        $processor = new NotificationProcessor(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $attempts,
            $methods,
            $handlers,
            $this->createStub(PaymentRecorded::class),
            $this->createStub(LoggerInterface::class),
        );

        $cron = new PaymentAttemptCleanupCron($attempts, $handlers, $methods, $logger ?? $this->createStub(LoggerInterface::class), static fn (): int => $now, $processor);

        return [$attempts, $handler, $cron];
    }
}

final class CancelSpy implements PaymentHandlerInterface
{
    /** @var list<array{int, array<string, mixed>}> */
    public array $cancelled = [];

    public static function getType(): string
    {
        return 'fake';
    }

    public function isAvailable(PaymentContext $context, array $config): bool
    {
        return true;
    }

    public function adjustment(PaymentContext $context, array $config): PaymentAdjustment|null
    {
        return null;
    }

    public function documentTrigger(array $config): DocumentTrigger
    {
        return DocumentTrigger::OnPayment;
    }

    public function start(PaymentAttempt $attempt, OrderView $order, array $config): PaymentStart
    {
        return PaymentStart::failed('unused');
    }

    public function handleReturn(Request $request, PaymentAttempt $attempt, array $config): AttemptState
    {
        return AttemptState::Pending;
    }

    public function handleNotification(Request $request, PaymentAttempt $attempt, array $config): NotificationResult
    {
        return NotificationResult::ignored();
    }

    public function cancel(PaymentAttempt $attempt, array $config): void
    {
        $this->cancelled[] = [$attempt->id, $config];
    }
}

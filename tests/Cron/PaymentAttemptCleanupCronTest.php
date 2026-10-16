<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cron;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Cron\PaymentAttemptCleanupCron;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
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

/**
 * Verwaiste offene Versuche (Michael, 2026-09-26: „ja zur doku und
 * aufräumen"): nach dem Verfall abgebrochen, der Anbieter erfährt es.
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

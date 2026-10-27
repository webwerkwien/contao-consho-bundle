<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationResult;
use Webwerkwien\ContaoConshoBundle\Payment\OrderView;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustment;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttemptRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentContext;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStart;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStartOutcome;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStarter;
use Webwerkwien\ContaoConshoBundle\Order\WithdrawalCredit;
use Webwerkwien\ContaoConshoBundle\Payment\SubscriptionLineView;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionMandates;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

class PaymentStarterTest extends TestCase
{
    /** @var array<int, array{order: int, method: int, type: string, amount: string, hash: string, state: AttemptState}> */
    private array $stored = [];

    /** @var list<string> */
    private array $payments = [];

    private int $locks = 0;

    /** @var array<int, int> createdAt per stored attempt */
    private array $created = [];

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_order_item');
    }

    /**
     * D-3: after an accepted withdrawal the online payment starts only what is
     * still owed — not the order total.
     */
    public function testEinAngenommenerWiderrufSenktDenBetrag(): void
    {
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 1, 'quantity' => '2', 'lineTotal' => '7.8000']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => 1, 'number' => 'WEB-00001-W1', 'state' => 'accepted']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'acceptedQuantity' => '1']);

        $this->starter(PaymentStart::redirect('https://anbieter.test/pay'))->start(1, 100);

        // 13,90 − 7,80/2 = 10,00
        self::assertTrue(\Brick\Math\BigDecimal::of('10')->isEqualTo($this->stored[1]['amount']));
    }

    protected function assertPostConditions(): void
    {
        // No transaction left dangling, on any path (C-11).
        self::assertFalse($this->connection->isTransactionActive());
    }

    /** @var array<string, string> */
    private array $order = ['id' => '1', 'orderNumber' => 'WEB-00001', 'shop' => '1', 'currency' => 'EUR', 'grandTotal' => '13.9000', 'email' => 'a@b.test', 'language' => 'de', 'paymentMethod' => '7', 'orderStatus' => '0'];

    public function testDerStartOeffnetEinenVersuchUndGibtDenTokenNurInDenAdressenWeiter(): void
    {
        $seen = null;
        $starter = $this->starter(PaymentStart::redirect('https://anbieter.test/pay'), $seen);

        $start = $starter->start(1, 100);

        self::assertSame(PaymentStartOutcome::Redirect, $start?->outcome);
        self::assertCount(1, $this->stored);

        $attempt = $this->stored[1];
        self::assertSame(AttemptState::Open, $attempt['state']);
        self::assertSame('13.9000', $attempt['amount']);

        // The handler gets the addresses with the plain text, only the hash is stored.
        self::assertInstanceOf(PaymentAttempt::class, $seen);
        self::assertMatchesRegularExpression('~^https://shop\.test/return/([0-9a-f]{64})$~', $seen->returnUrl);
        preg_match('~/return/([0-9a-f]{64})$~', $seen->returnUrl, $m);
        self::assertSame('https://shop.test/notify/'.$m[1], $seen->notifyUrl);
        self::assertSame(hash('sha256', $m[1]), $attempt['hash']);
    }

    /**
     * C-11: two tabs click "Try again" at the same time. The second waits at
     * the order's lock, finds the first one's open attempt and starts
     * nothing — otherwise two attempts, two payments at the provider.
     */
    public function testLaeuftSchonEinVersuchDerArtStartetKeinZweiter(): void
    {
        $starter = $this->starter(PaymentStart::redirect('https://anbieter.test/pay'));

        self::assertSame(PaymentStartOutcome::Redirect, $starter->start(1, 100)?->outcome);
        self::assertNull($starter->start(1, 101));
        self::assertCount(1, $this->stored);
        self::assertSame(2, $this->locks, 'jede Entscheidung unter der Sperre');

        // After a cancellation one may start again.
        $this->stored[1]['state'] = AttemptState::Cancelled;
        self::assertSame(PaymentStartOutcome::Redirect, $starter->start(1, 102)?->outcome);
        self::assertCount(2, $this->stored);

        // An open attempt of a DIFFERENT method (before the switch) holds nothing up.
        $this->stored = [1 => ['order' => 1, 'method' => 2, 'type' => 'prepayment', 'amount' => '13.9000', 'hash' => 'x', 'state' => AttemptState::Pending]];
        self::assertSame(PaymentStartOutcome::Redirect, $starter->start(1, 103)?->outcome);
    }

    /**
     * Whoever closes the provider's page leaves an open attempt behind.
     * After 30 minutes it counts as expired: "Try again" is offered, the old
     * attempt cancelled. Prepayment (`pending`) never expires.
     */
    public function testEinOffenerVersuchVerfaelltNachDreissigMinuten(): void
    {
        $starter = $this->starter(PaymentStart::redirect('https://anbieter.test/pay'));
        $starter->start(1, 1000);
        $lapse = 1000 + PaymentStarter::ATTEMPT_LIFETIME;

        self::assertFalse($starter->canRetry(1, $lapse - 1), 'eine Sekunde davor: noch unterwegs');
        self::assertNull($starter->start(1, $lapse - 1));
        self::assertCount(1, $this->stored);

        self::assertTrue($starter->canRetry(1, $lapse));
        self::assertSame(PaymentStartOutcome::Redirect, $starter->start(1, $lapse)?->outcome);
        self::assertCount(2, $this->stored);
        self::assertSame(AttemptState::Cancelled, $this->stored[1]['state'], 'der verfallene Versuch ist abgebrochen');
        self::assertSame(AttemptState::Open, $this->stored[2]['state']);

        // Prepayment waits for a bank transfer — no expiry.
        $this->stored[2]['state'] = AttemptState::Pending;
        self::assertFalse($starter->canRetry(1, $lapse + 100_000));
        self::assertNull($starter->start(1, $lapse + 100_000));
    }

    /**
     * D-1: a cancelled order owes nothing — even when its status carries no
     * flags afterwards.
     */
    public function testEineStornierteBestellungStartetKeineZahlung(): void
    {
        $this->order['cancelledAt'] = '1790000000';
        $starter = $this->starter(PaymentStart::redirect('https://anbieter.test/pay'));

        self::assertNull($starter->start(1, 100));
        self::assertSame([], $this->stored);
        self::assertFalse($starter->canRetry(1, 100));
    }

    public function testEinAbgeschlossenerStartWartetAufDasGeld(): void
    {
        $this->starter(PaymentStart::completed('<p>Bitte überweisen.</p>'))->start(1, 100);

        self::assertSame(AttemptState::Pending, $this->stored[1]['state']);
    }

    public function testEinWerfenderHandlerIstEinFehlschlagUndWirdProtokolliert(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $start = $this->starter(new \RuntimeException('Anbieter nicht erreichbar'), logger: $logger)->start(1, 100);

        self::assertSame(PaymentStartOutcome::Failed, $start?->outcome);
        self::assertSame(AttemptState::Failed, $this->stored[1]['state']);
    }

    public function testNurDerOffeneRestbetragUndNichtsWennAllesBezahltIst(): void
    {
        $this->payments = ['3.9000'];
        $this->starter(PaymentStart::redirect('https://anbieter.test/pay'))->start(1, 100);
        self::assertSame('10.0000', $this->stored[1]['amount']);

        $this->stored = [];
        $this->payments = ['13.9000'];
        self::assertNull($this->starter(PaymentStart::redirect('https://anbieter.test/pay'))->start(1, 100));
        self::assertSame([], $this->stored);
    }

    public function testOhneZahlungsartKeinVersuch(): void
    {
        $this->order['paymentMethod'] = '99';

        $start = $this->starter(PaymentStart::redirect('https://anbieter.test/pay'))->start(1, 100);

        self::assertSame(PaymentStartOutcome::Failed, $start?->outcome);
        self::assertSame([], $this->stored);
    }

    public function testErneutVersuchenNurNachAbbruchOderFehlerDerAktuellenArt(): void
    {
        $starter = $this->starter(PaymentStart::redirect('https://anbieter.test/pay'));
        self::assertFalse($starter->canRetry(1, 100), 'ohne Versuch');

        foreach ([[AttemptState::Open, false], [AttemptState::Pending, false], [AttemptState::Succeeded, false], [AttemptState::Failed, true], [AttemptState::Cancelled, true]] as [$state, $expected]) {
            $this->stored = [1 => ['order' => 1, 'method' => 7, 'type' => 'test', 'amount' => '13.9000', 'hash' => 'x', 'state' => $state]];
            self::assertSame($expected, $starter->canRetry(1, 100), $state->value);
        }

        // Attempt of an earlier method (before the switch): no retry.
        $this->stored = [1 => ['order' => 1, 'method' => 2, 'type' => 'prepayment', 'amount' => '13.9000', 'hash' => 'x', 'state' => AttemptState::Cancelled]];
        self::assertFalse($starter->canRetry(1, 100));

        // Partly paid or held by the status: no.
        $this->stored = [1 => ['order' => 1, 'method' => 7, 'type' => 'test', 'amount' => '13.9000', 'hash' => 'x', 'state' => AttemptState::Failed]];
        $this->payments = ['1.0000'];
        self::assertFalse($starter->canRetry(1, 100));

        $this->payments = [];
        $this->order['orderStatus'] = '5';
        self::assertFalse($starter->canRetry(1, 100));
    }

    /**
     * P93: if the order contains subscription lines, the view tells the
     * module — with the lines Klarna and PayPal already need at the first
     * purchase.
     */
    public function testDieSichtTraegtDieAboZeilen(): void
    {
        $line = new SubscriptionLineView('WEB-00001-1', 'Mitgliedschaft', '1.2000', 1, 12);
        $mandates = $this->createMock(SubscriptionMandates::class);
        $mandates->method('linesOf')->with(1)->willReturn([$line]);

        $seen = null;
        $view = null;
        $this->starter(PaymentStart::redirect('https://anbieter.test/pay'), $seen, null, $mandates, $view)->start(1, 100);

        self::assertInstanceOf(OrderView::class, $view);
        self::assertTrue($view->recurring);
        self::assertSame([$line], $view->subscriptionLines);
    }

    /**
     * WG-5: if a gift card paid a part, the view tells the module what is
     * open — whoever collected `grandTotal` would take the gift card part twice.
     */
    public function testDieSichtNenntDenOffenenBetrag(): void
    {
        $this->payments = ['10.0000'];

        $seen = null;
        $view = null;
        $this->starter(PaymentStart::redirect('https://anbieter.test/pay'), $seen, null, null, $view)->start(1, 100);

        self::assertInstanceOf(OrderView::class, $view);
        self::assertSame('13.9000', $view->grandTotal);
        self::assertSame('3.9000', $view->outstanding);
        self::assertSame('3.9000', $seen?->amount);
    }

    /**
     * Not being able to read the subscription lines does not cost the payment:
     * it starts without them and logs it — the follow-up orders then go "on
     * invoice".
     */
    public function testOhneLesbareAboZeilenStartetDieZahlungTrotzdem(): void
    {
        $mandates = $this->createMock(SubscriptionMandates::class);
        $mandates->method('linesOf')->willThrowException(new \RuntimeException('Tabelle weg'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('subscription lines for the payment could not be read: Tabelle weg'));

        $seen = null;
        $view = null;
        $start = $this->starter(PaymentStart::redirect('https://anbieter.test/pay'), $seen, $logger, $mandates, $view)->start(1, 100);

        self::assertSame(PaymentStartOutcome::Redirect, $start?->outcome);
        self::assertInstanceOf(OrderView::class, $view);
        self::assertFalse($view->recurring);
    }

    private function starter(PaymentStart|\Throwable $answer, PaymentAttempt|null &$seen = null, LoggerInterface|null $logger = null, SubscriptionMandates|null $mandates = null, OrderView|null &$view = null): PaymentStarter
    {
        $orders = $this->createMock(PaymentChangeRepositoryInterface::class);
        $orders->method('order')->willReturnCallback(fn (int $id): array|null => 1 === $id ? $this->order : null);
        $connection = $this->connection;
        $orders->method('lockOrder')->willReturnCallback(function (int $id) use ($connection): array|null {
            // The lock only holds inside a transaction (C-11).
            self::assertTrue($connection->isTransactionActive(), 'lockOrder() außerhalb einer Transaktion');
            ++$this->locks;

            return 1 === $id ? $this->order : null;
        });
        $orders->method('paymentAmounts')->willReturnCallback(fn (): array => $this->payments);
        $orders->method('statusHolds')->willReturnCallback(static fn (int $status): bool => 5 === $status);

        $methods = $this->createMock(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturnCallback(static fn (int $id): PaymentMethod|null => 7 === $id ? new PaymentMethod(7, 'fake', 'Karte', ['key' => 'x']) : null);

        $handler = new class($answer, $seen, $view) implements PaymentHandlerInterface {
            public function __construct(private readonly PaymentStart|\Throwable $answer, public mixed &$seen, public mixed &$view)
            {
            }

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
                $this->seen = $attempt;
                $this->view = $order;

                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
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
            }
        };

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $p): string => 'https://shop.test/'.('consho_payment_return' === $name ? 'return' : 'notify').'/'.$p['token']);

        return new PaymentStarter(
            $connection,
            $orders,
            $methods,
            new PaymentHandlers(new ServiceLocator(['fake' => static fn () => $handler])),
            $this->attempts(),
            new PaymentStateCalculator(),
            $router,
            new WithdrawalCredit($connection),
            $logger ?? $this->createMock(LoggerInterface::class),
            $mandates,
        );
    }

    private function attempts(): PaymentAttemptRepositoryInterface
    {
        $test = $this;

        return new class($test) implements PaymentAttemptRepositoryInterface {
            public function __construct(private readonly PaymentStarterTest $test)
            {
            }

            public function open(int $orderId, PaymentMethod $method, string $amount, string $currency, string $tokenHash, int $now, bool $charge = false): int
            {
                return $this->test->store($orderId, $method, $amount, $tokenHash, $now);
            }

            public function findByHash(string $tokenHash): PaymentAttempt|null
            {
                return null;
            }

            public function lock(int $attemptId): PaymentAttempt|null
            {
                return null;
            }

            public function latestFor(int $orderId): PaymentAttempt|null
            {
                return $this->test->latest();
            }

            public function setState(int $attemptId, AttemptState $state, int $now, string $providerReference = ''): void
            {
                $this->test->state($attemptId, $state);
            }

            public function recordReturn(int $attemptId, AttemptState $returned, int $now): void
            {
            }

            public function openBefore(int $before): array
            {
                return [];
            }

            public function cancelIfOpen(int $attemptId, int $now): bool
            {
                return false;
            }

            public function recordPayment(PaymentAttempt $attempt, string $amount, string $reference, string $methodTitle, int $now): void
            {
            }
        };
    }

    /**
     * @internal
     */
    public function store(int $orderId, PaymentMethod $method, string $amount, string $hash, int $now = 0): int
    {
        $this->created[\count($this->stored) + 1] = $now;
        self::assertTrue($this->connection->isTransactionActive(), 'Versuch außerhalb der Sperre geöffnet');

        $id = \count($this->stored) + 1;
        $this->stored[$id] = ['order' => $orderId, 'method' => $method->id, 'type' => $method->type, 'amount' => $amount, 'hash' => $hash, 'state' => AttemptState::Open];

        return $id;
    }

    /**
     * @internal
     */
    public function state(int $id, AttemptState $state): void
    {
        $this->stored[$id]['state'] = $state;
    }

    /**
     * @internal
     */
    public function latest(): PaymentAttempt|null
    {
        if ([] === $this->stored) {
            return null;
        }

        $id = max(array_keys($this->stored));
        $row = $this->stored[$id];

        return new PaymentAttempt($id, $row['order'], $row['method'], $row['type'], $row['state'], $row['amount'], 'EUR', '', '', '', null, $this->created[$id] ?? 0);
    }
}

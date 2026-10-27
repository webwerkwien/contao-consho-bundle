<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoConshoBundle\Command\BillSubscriptionsCommand;
use Webwerkwien\ContaoConshoBundle\Command\RunSubscriptionsCommand;
use Webwerkwien\ContaoConshoBundle\Cron\SubscriptionCron;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Subscription\BilledPeriod;
use Webwerkwien\ContaoConshoBundle\Subscription\DelinquencyChange;
use Webwerkwien\ContaoConshoBundle\Subscription\LifecycleChange;
use Webwerkwien\ContaoConshoBundle\Subscription\ReminderDue;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquencyRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLifecycleRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBiller;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionChargeFallback;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCharger;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPause;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-4 (A14, P28, P32): the run over the due subscriptions — hourly for the cron
 * and for `consho:subscription:bill`. The biller itself is tested in
 * SubscriptionBillerTest; what counts here is WHOM the run asks, how often,
 * and that the mail comes only after billing.
 */
class SubscriptionBillingRunTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    /** @var list<string> */
    private array $steps = [];

    /** @var array<int, list<BilledPeriod|\Throwable|null>> what billDue() answers per subscription, in turn */
    private array $answers = [];

    private bool|string $orderPage = true;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_subscription');
        DcaSchema::create($this->connection, 'tl_consho_order');

        $due = ['state' => 'active', 'billingInterval' => 1, 'nextBillingAt' => self::NOW - 60, 'minimumEndsAt' => self::NOW + 86_400 * 300];

        $this->subscription(1, $due);
        $this->subscription(2, ['nextBillingAt' => self::NOW + 60] + $due);
        $this->subscription(3, ['state' => 'cancelled'] + $due);
        // P28: from the end of the minimum term Abo-6 is responsible.
        $this->subscription(4, ['minimumEndsAt' => self::NOW - 60] + $due);
        // A payment for the whole term has no follow-up order.
        // As the recorder writes it: next billing = end of the term.
        $this->subscription(5, ['billingInterval' => 0, 'minimumEndsAt' => self::NOW - 60] + $due);
        $this->subscription(6, $due);
        // Abo-5 (P36): suspended keeps being billed.
        $this->subscription(7, ['state' => 'suspended'] + $due);

        foreach ([91, 92, 93] as $id) {
            $this->connection->insert('tl_consho_order', ['id' => $id, 'orderNumber' => 'WEB-000'.$id, 'shop' => 1]);
        }
    }

    public function testNurFaelligeAbosWerdenGefragt(): void
    {
        $this->answers = [1 => [$this->period(1, 91), null], 6 => [null]];

        $billed = $this->billing()->run(self::NOW);

        self::assertSame(['bill 1', 'mail 91 WEB-00001-1', 'bill 1', 'bill 6', 'bill 7'], $this->steps);
        self::assertSame([91], array_map(static fn (BilledPeriod $p): int => $p->orderId, $billed));
    }

    public function testVersaeumtePeriodenJeEineBestellung(): void
    {
        $this->answers = [1 => [$this->period(1, 91), $this->period(1, 92), $this->period(1, 93), null], 6 => [null]];

        self::assertCount(3, $this->billing()->run(self::NOW));
        self::assertSame(['bill 1', 'mail 91 WEB-00001-1', 'bill 1', 'mail 92 WEB-00001-1', 'bill 1', 'mail 93 WEB-00001-1', 'bill 1', 'bill 6', 'bill 7'], $this->steps);
    }

    public function testHoechstensZwoelfPeriodenJeLauf(): void
    {
        $this->answers = [1 => array_fill(0, 20, $this->period(1, 91)), 6 => [null]];

        $this->billing()->run(self::NOW);

        // P28 says twelve — as a number, not as a constant.
        self::assertCount(12, array_filter($this->steps, static fn (string $s): bool => 'bill 1' === $s));
    }

    public function testEinFehlerHaeltDieAnderenNichtAuf(): void
    {
        $this->answers = [1 => [new \RuntimeException('number range gone')], 6 => [$this->period(6, 92), null]];

        $billed = $this->billing()->run(self::NOW);

        self::assertSame([92], array_map(static fn (BilledPeriod $p): int => $p->orderId, $billed));
        self::assertStringContainsString('Subscription 1 could not be billed: number range gone', implode("\n", $this->logged));
    }

    public function testOhneBestellseiteKeineMailAberDieBestellungSteht(): void
    {
        $this->orderPage = false;
        $this->answers = [1 => [$this->period(1, 91), null], 6 => [null]];

        self::assertCount(1, $this->billing()->run(self::NOW));
        self::assertNotContains('mail 91 WEB-00001-1', $this->steps);
        self::assertStringContainsString('follow-up order 91 was not sent', implode("\n", $this->logged));
    }

    /**
     * If the link to the order page fails, the order still
     * stands, and the further periods run.
     */
    public function testEinFehlerBeimLinkHaeltDieAbrechnungNichtAuf(): void
    {
        $this->orderPage = 'throws';
        $this->answers = [1 => [$this->period(1, 91), $this->period(1, 92), null], 6 => [null]];

        self::assertCount(2, $this->billing()->run(self::NOW));
        self::assertStringContainsString('follow-up order 91 was not sent: framework down', implode("\n", $this->logged));
        self::assertStringNotContainsString('could not be billed', implode("\n", $this->logged));
    }

    /**
     * Abo-5, Abo-6: reminder, billing, transition, billing, payment state
     * — with the same clock. The transition waits for billing, and the
     * first period after it is due immediately.
     */
    public function testDerCronInSeinerReihenfolge(): void
    {
        $run = $this->createMock(SubscriptionBillingRun::class);
        $run->expects(self::exactly(2))->method('run')->with(self::NOW)->willReturnCallback(function (): array {
            $this->steps[] = 'bill';

            return [];
        });

        $delinquency = $this->createMock(SubscriptionDelinquencyRun::class);
        $delinquency->expects(self::once())->method('run')->with(self::NOW)->willReturnCallback(function (): array {
            $this->steps[] = 'delinquency';

            return [];
        });

        (new SubscriptionCron($run, $delinquency, $this->lifecycleRun(), $this->charger(), $this->fallback(), $this->pauses(), static fn (): int => self::NOW))();

        // Abo-4b: catch up and switch after 7 days, before delinquency.
        // Pausing a subscription (P106): pauses first — whoever comes out of the pause is billed in the same run.
        self::assertSame(['pauses', 'remind', 'bill', 'transition', 'bill', 'catch up', 'lapse', 'delinquency'], $this->steps);
    }

    private function pauses(): SubscriptionPause
    {
        $pauses = $this->createMock(SubscriptionPause::class);
        $pauses->expects(self::once())->method('run')->with(self::NOW)->willReturnCallback(function (): array {
            $this->steps[] = 'pauses';

            return ['paused' => ['WEB-00007-1'], 'resumed' => []];
        });

        return $pauses;
    }

    private function charger(): SubscriptionCharger
    {
        $charger = $this->createMock(SubscriptionCharger::class);
        $charger->expects(self::once())->method('chargeOutstanding')->with(self::NOW)->willReturnCallback(function (): array {
            $this->steps[] = 'catch up';

            return [92];
        });

        return $charger;
    }

    private function fallback(): SubscriptionChargeFallback
    {
        $fallback = $this->createMock(SubscriptionChargeFallback::class);
        $fallback->expects(self::once())->method('lapse')->with(self::NOW)->willReturnCallback(function (): array {
            $this->steps[] = 'lapse';

            return [93];
        });

        return $fallback;
    }

    private function lifecycleRun(): SubscriptionLifecycleRun
    {
        $lifecycle = $this->createMock(SubscriptionLifecycleRun::class);
        // The command takes the real clock; the cron's clock is checked on the billing run.
        $lifecycle->method('remind')->willReturnCallback(function (): array {
            $this->steps[] = 'remind';

            return [new ReminderDue(1, 'WEB-00001-1', RenewalKind::Indefinite, self::NOW + 86_400 * 30, self::NOW + 86_400 * 16)];
        });
        $lifecycle->method('transition')->willReturnCallback(function (): array {
            $this->steps[] = 'transition';

            return ['WEB-00006-1' => LifecycleChange::OpenEnded];
        });

        return $lifecycle;
    }

    public function testDerBefehlRunFuehrtAlleStufenAus(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('initialize')->willReturnCallback(function (): void {
            $this->steps[] = 'initialize';
        });

        $run = $this->createMock(SubscriptionBillingRun::class);
        $run->method('run')->willReturnCallback(function (): array {
            $this->steps[] = 'bill';

            return [$this->period(1, 91)];
        });

        $delinquency = $this->createMock(SubscriptionDelinquencyRun::class);
        $delinquency->method('run')->willReturnCallback(function (): array {
            $this->steps[] = 'delinquency';

            return [new DelinquencyChange(1, 'WEB-00001-1', true, [91]), new DelinquencyChange(6, 'WEB-00006-1', false)];
        });

        $charger = $this->createMock(SubscriptionCharger::class);
        $charger->expects(self::once())->method('chargeOutstanding')->willReturnCallback(function (): array {
            $this->steps[] = 'catch up';

            return [92];
        });
        $fallback = $this->createMock(SubscriptionChargeFallback::class);
        $fallback->expects(self::once())->method('lapse')->willReturnCallback(function (): array {
            $this->steps[] = 'lapse';

            return [93];
        });

        $pauses = $this->createMock(SubscriptionPause::class);
        $pauses->expects(self::once())->method('run')->willReturnCallback(function (): array {
            $this->steps[] = 'pauses';

            return ['paused' => ['WEB-00007-1'], 'resumed' => []];
        });

        $tester = new CommandTester(new RunSubscriptionsCommand($framework, $run, $delinquency, $this->lifecycleRun(), $charger, $fallback, $pauses));

        self::assertSame(0, $tester->execute([]));
        // Abo-4b: every stage of the cron, the charges too — and the pauses.
        self::assertSame(['initialize', 'pauses', 'remind', 'bill', 'transition', 'bill', 'catch up', 'lapse', 'delinquency'], $this->steps);
        self::assertStringContainsString('Pauses begun: 1 (WEB-00007-1)', $tester->getDisplay());
        self::assertStringContainsString('Pauses ended: 0', $tester->getDisplay());
        self::assertStringContainsString('Charges caught up: 1 (order ID 92)', $tester->getDisplay());
        self::assertStringContainsString('Charges gone "on account" after 7 days: 1 (order ID 93)', $tester->getDisplay());
        self::assertStringContainsString('Reminders sent: 1', $tester->getDisplay());
        self::assertStringContainsString('WEB-00006-1 openEnded', $tester->getDisplay());
        self::assertStringContainsString('WEB-00001-1 rests (overdue order ID 91)', $tester->getDisplay());
        self::assertStringContainsString('WEB-00006-1 active again', $tester->getDisplay());
    }

    /**
     * Abo-3: the period boundaries are wall time in the installation's
     * zone — Contao sets it on initialisation. The command therefore
     * initialises BEFORE it calculates.
     */
    public function testDerBefehlInitialisiertContaoVorDemLauf(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('initialize')->willReturnCallback(function (): void {
            $this->steps[] = 'initialize';
        });

        $run = $this->createMock(SubscriptionBillingRun::class);
        $run->method('run')->willReturnCallback(function (): array {
            $this->steps[] = 'run';

            return [$this->period(1, 91)];
        });

        $tester = new CommandTester(new BillSubscriptionsCommand($framework, $run));

        self::assertSame(0, $tester->execute([]));
        self::assertSame(['initialize', 'run'], $this->steps);
        self::assertStringContainsString('WEB-00001-1', $tester->getDisplay());
    }

    private function billing(): SubscriptionBillingRun
    {
        $biller = $this->createMock(SubscriptionBiller::class);
        $biller->method('billDue')->willReturnCallback(function (int $id, int $now): BilledPeriod|null {
            self::assertSame(self::NOW, $now);
            $this->steps[] = 'bill '.$id;

            // No answer given: nothing due.
            if (!isset($this->answers[$id])) {
                return null;
            }

            $answer = array_shift($this->answers[$id]);

            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });

        $links = $this->createMock(OrderPageLink::class);
        $links->method('forMail')->willReturnCallback(function (array $order): string|null {
            if ('throws' === $this->orderPage) {
                throw new \RuntimeException('framework down');
            }

            return true === $this->orderPage ? 'https://shop.test/bestellung?t='.$order['id'] : null;
        });

        $mail = $this->createMock(OrderEventMail::class);
        $mail->method('subscriptionInvoice')->willReturnCallback(function (int $orderId, string $number, int $from, int $until, string $url): bool {
            self::assertSame('https://shop.test/bestellung?t='.$orderId, $url);
            $this->steps[] = \sprintf('mail %d %s', $orderId, $number);

            return true;
        });

        $logger = new class(function (string $line): void { $this->logged[] = $line; }) extends AbstractLogger {
            public function __construct(private readonly \Closure $write)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->write)((string) $message);
            }
        };

        return new SubscriptionBillingRun($this->connection, $biller, $links, $mail, $logger);
    }

    private function period(int $subscriptionId, int $orderId): BilledPeriod
    {
        return new BilledPeriod($subscriptionId, \sprintf('WEB-%05d-1', $subscriptionId), $orderId, self::NOW, self::NOW + 86_400 * 30);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function subscription(int $id, array $values): void
    {
        $this->connection->insert('tl_consho_subscription', ['id' => $id, 'subscriptionNumber' => 'WEB-0000'.$id.'-1'] + $values);
    }
}

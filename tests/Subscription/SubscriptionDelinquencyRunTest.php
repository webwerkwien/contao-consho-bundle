<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Webwerkwien\ContaoConshoBundle\Digital\PurchaseDelinquency;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalance;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Subscription\DelinquencyChange;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquency;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquencyRun;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-5 (P35, P37): WEN der Lauf fragt, und welche Mail danach hinausgeht.
 * Das Urteil selbst prüft SubscriptionDelinquencyTest.
 */
final class SubscriptionDelinquencyRunTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    /** @var list<int> */
    private array $asked = [];

    /** @var array<int, DelinquencyChange|\Throwable|null> */
    private array $answers = [];

    /** @var list<string> */
    private array $mails = [];

    /** @var list<string> */
    private array $logged = [];

    private bool $orderPage = true;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_subscription');
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_payment');

        $past = self::NOW - 86_400;

        // 1: läuft, Folgebestellung über dem Ziel, nie beglichen → gefragt.
        $this->subscription(1, 'active', 10);
        $this->order(10, 0);
        $this->order(11, 1, ['paymentDueAt' => $past, 'createdAt' => 5]);
        // 2: ruht → immer gefragt (eine Stornierung meldet sich nicht).
        $this->subscription(2, 'suspended', 20);
        $this->order(20, 0);
        // 3: bezahlt, 4: storniert, 5: Ziel noch nicht erreicht → nicht gefragt.
        $this->subscription(3, 'active', 30);
        $this->order(30, 0, ['paymentDueAt' => $past, 'paymentSettledAt' => $past]);
        $this->connection->insert('tl_consho_payment', ['pid' => 30, 'amount' => '12.0000']);
        // 8: beglichen und danach erstattet (Review L2) → wieder gefragt.
        $this->subscription(8, 'active', 80);
        $this->order(80, 0, ['paymentDueAt' => $past, 'paymentSettledAt' => $past]);
        $this->connection->insert('tl_consho_payment', ['pid' => 80, 'amount' => '12.0000']);
        $this->connection->insert('tl_consho_payment', ['pid' => 80, 'amount' => '-12.0000']);
        $this->subscription(4, 'active', 40);
        $this->order(40, 0, ['paymentDueAt' => $past, 'cancelledAt' => $past]);
        $this->subscription(5, 'active', 50);
        $this->order(50, 0, ['paymentDueAt' => self::NOW + 60]);
        // 6: der Kauf selbst ist überfällig (P34) → gefragt.
        $this->subscription(6, 'active', 60);
        $this->order(60, 0, ['paymentDueAt' => $past]);
        // 7: beendet, nichts ruht → nie, auch überfällig nicht.
        $this->subscription(7, 'ended', 70);
        $this->order(70, 0, ['paymentDueAt' => $past]);
        // 9: beendet mit ruhender Berechtigung → gefragt (P64).
        DcaSchema::create($this->connection, 'tl_consho_entitlement');
        $this->subscription(9, 'ended', 90);
        $this->order(90, 0);
        $this->connection->insert('tl_consho_entitlement', ['pid' => 90, 'subscription' => 9, 'suspendedAt' => $past]);
        $this->connection->insert('tl_consho_entitlement', ['pid' => 70, 'subscription' => 7]);
    }

    public function testNurKandidatenWerdenGefragt(): void
    {
        $this->delinquencyRun()->run(self::NOW);

        self::assertSame([1, 2, 6, 8, 9], $this->asked);
    }

    /**
     * Abo-7b: 12 verlängert das beendete 7, dessen Kauf 70 überfällig ist —
     * gefragt, weil die Lizenz jetzt an 12 hängt. Die Abos ohne Vorgänger
     * (3, 4, 5) bleiben ungefragt, obwohl überfällige Bestellungen ohne Abo
     * (subscription = 0) herumliegen: der Vorgänger 0 trifft keine.
     */
    public function testEineVerlaengerungWirdWegenDesVorgaengersGefragt(): void
    {
        $this->subscription(12, 'active', 120);
        $this->connection->update('tl_consho_subscription', ['predecessor' => 7], ['id' => 12]);
        $this->order(120, 0, ['paymentDueAt' => self::NOW + 60]);

        $this->delinquencyRun()->run(self::NOW);

        self::assertSame([1, 2, 6, 8, 9, 12], $this->asked);
    }

    public function testRuhtMitDerAeltestenUeberfaelligenBestellungUndIhremBetrag(): void
    {
        $this->answers[1] = new DelinquencyChange(1, 'WEB-00010-1', true, [11, 10]);

        $changes = $this->delinquencyRun()->run(self::NOW);

        self::assertCount(1, $changes);
        self::assertSame(['suspended 11 WEB-00010-1 12.5000 https://shop.test/b?t=11'], $this->mails);
    }

    public function testWiederAktivMitDerNeuestenBestellung(): void
    {
        $this->answers[1] = new DelinquencyChange(1, 'WEB-00010-1', false);

        $this->delinquencyRun()->run(self::NOW);

        self::assertSame(['resumed 11 WEB-00010-1 https://shop.test/b?t=11'], $this->mails);
    }

    public function testEinFehlerHaeltDieAnderenNichtAuf(): void
    {
        $this->answers[1] = new \RuntimeException('lock timeout');
        $this->answers[6] = new DelinquencyChange(6, 'WEB-00060-1', true, [60]);

        $changes = $this->delinquencyRun()->run(self::NOW);

        self::assertSame([6], array_map(static fn (DelinquencyChange $c): int => $c->subscriptionId, $changes));
        self::assertStringContainsString('subscription 1 could not be checked: lock timeout', implode("\n", $this->logged));
    }

    public function testOhneBestellseiteKeineMailAberDerZustandSteht(): void
    {
        $this->orderPage = false;
        $this->answers[1] = new DelinquencyChange(1, 'WEB-00010-1', true, [11]);

        self::assertCount(1, $this->delinquencyRun()->run(self::NOW));
        self::assertSame([], $this->mails);
        self::assertStringContainsString('WEB-00010-1 was not sent', implode("\n", $this->logged));
    }

    public function testNachEinerZahlungDieAbosDieserBestellung(): void
    {
        $this->answers[1] = new DelinquencyChange(1, 'WEB-00010-1', false);

        $this->delinquencyRun()->afterPayment(11, self::NOW);

        self::assertSame([1], $this->asked);
        self::assertCount(1, $this->mails);
    }

    /**
     * Nachlese E5a: Derselbe Lauf fragt die Einmalkäufe — im Cron alle, nach
     * einer Zahlung diese Bestellung; ein Fehler dort hält die Abos nicht auf.
     */
    public function testAuchDieEinmalkaeufe(): void
    {
        $purchases = $this->createMock(PurchaseDelinquency::class);
        $purchases->expects(self::once())->method('run')->with(self::NOW)->willThrowException(new \RuntimeException('kaputt'));
        $purchases->expects(self::once())->method('reassess')->with(11, self::NOW);

        $run = $this->delinquencyRun($purchases);
        $run->run(self::NOW);
        $run->afterPayment(11, self::NOW);

        self::assertCount(6, $this->asked, 'die Abos wurden trotzdem gefragt: fünf vom Lauf, eines nach der Zahlung');
        self::assertStringContainsString('kaputt', implode(' ', $this->logged));
    }

    /**
     * Nachlese E5 (Michael, 2026-10-09: „E5 Infomail umsetzen"): Ruht ein
     * Einmalkauf oder lebt er wieder auf, erfährt es der Kunde — nicht aber,
     * wenn ein Storno das Ruhen aufhob.
     */
    public function testEinmalkaeufeBekommenIhreMail(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 140, 'orderNumber' => 'WEB-00140', 'grandTotal' => '30.0000']);
        $this->connection->insert('tl_consho_order', ['id' => 141, 'orderNumber' => 'WEB-00141', 'grandTotal' => '30.0000']);
        $this->connection->insert('tl_consho_order', ['id' => 142, 'orderNumber' => 'WEB-00142', 'grandTotal' => '30.0000', 'cancelledAt' => self::NOW]);

        $purchases = $this->createStub(PurchaseDelinquency::class);
        $purchases->method('run')->willReturn([140 => true, 141 => false, 142 => false]);
        $purchases->method('reassess')->willReturn(false);

        $run = $this->delinquencyRun($purchases);
        $run->run(self::NOW);
        $run->afterPayment(140, self::NOW);

        $purchaseMails = array_values(array_filter($this->mails, static fn (string $m): bool => str_starts_with($m, 'purchase')));

        self::assertSame([
            'purchaseSuspended 140 12.5000 https://shop.test/b?t=140',
            'purchaseResumed 141 https://shop.test/b?t=141',
            'purchaseResumed 140 https://shop.test/b?t=140',
        ], $purchaseMails, 'Bestellung 142 ist storniert: keine Mail');
    }

    private function delinquencyRun(PurchaseDelinquency|null $purchases = null): SubscriptionDelinquencyRun
    {
        $delinquency = $this->createMock(SubscriptionDelinquency::class);
        $delinquency->method('reassess')->willReturnCallback(function (int $id, int $now): DelinquencyChange|null {
            self::assertSame(self::NOW, $now);
            $this->asked[] = $id;
            $answer = $this->answers[$id] ?? null;

            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });
        $delinquency->method('subscriptionsOfOrder')->willReturnCallback(static fn (int $orderId): array => 11 === $orderId ? [1] : []);

        $balances = $this->createStub(OrderBalanceReaderInterface::class);
        $balances->method('of')->willReturn(new OrderBalance('12.5000', '0', PaymentState::Open, DeliveryState::Complete, [], true));

        $links = $this->createMock(OrderPageLink::class);
        $links->method('forMail')->willReturnCallback(fn (array $order): string|null => $this->orderPage ? 'https://shop.test/b?t='.$order['id'] : null);

        $mail = $this->createMock(OrderEventMail::class);
        $mail->method('subscriptionSuspended')->willReturnCallback(function (int $orderId, string $number, string $outstanding, string $url): bool {
            $this->mails[] = \sprintf('suspended %d %s %s %s', $orderId, $number, $outstanding, $url);

            return true;
        });
        $mail->method('purchaseSuspended')->willReturnCallback(function (int $orderId, string $outstanding, string $url): bool {
            $this->mails[] = \sprintf('purchaseSuspended %d %s %s', $orderId, $outstanding, $url);

            return true;
        });
        $mail->method('purchaseResumed')->willReturnCallback(function (int $orderId, string $url): bool {
            $this->mails[] = \sprintf('purchaseResumed %d %s', $orderId, $url);

            return true;
        });
        $mail->method('subscriptionResumed')->willReturnCallback(function (int $orderId, string $number, string $url): bool {
            $this->mails[] = \sprintf('resumed %d %s %s', $orderId, $number, $url);

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

        return new SubscriptionDelinquencyRun($this->connection, $delinquency, $balances, $links, $mail, $logger, $purchases);
    }

    private function subscription(int $id, string $state, int $firstOrder): void
    {
        $this->connection->insert('tl_consho_subscription', ['id' => $id, 'subscriptionNumber' => 'WEB-000'.$firstOrder.'-1', 'state' => $state, 'firstOrder' => $firstOrder]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function order(int $id, int $subscription, array $values = []): void
    {
        $this->connection->insert('tl_consho_order', $values + ['id' => $id, 'orderNumber' => 'WEB-000'.$id, 'subscription' => $subscription, 'grandTotal' => '12.0000']);
    }
}

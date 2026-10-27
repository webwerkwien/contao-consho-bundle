<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cancellation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationDecision;
use Webwerkwien\ContaoConshoBundle\Cancellation\SubscriptionContact;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-7, P57: The decision on an extraordinary cancellation takes effect
 * once and goes to the customer.
 */
final class CancellationDecisionTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const DAY = 86_400;

    private Connection $connection;

    /** @var list<array<string, mixed>> */
    private array $mails = [];

    private bool $mailLeaves = true;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_subscription', 'tl_consho_cancellation', 'tl_consho_order'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 90, 'orderNumber' => 'WEB-00090', 'email' => 'kundin@example.test', 'createdAt' => 1]);
        $this->connection->insert('tl_consho_subscription', ['id' => 1, 'subscriptionNumber' => 'WEB-00090-1', 'firstOrder' => 90, 'state' => 'active']);
        $this->connection->insert('tl_consho_cancellation', ['id' => 5, 'pid' => 1, 'number' => 'WEB-00090-1-K1', 'kind' => 'extraordinary', 'state' => 'received', 'reason' => 'Umzug']);
    }

    public function testAngenommenSetztDasEndeUndMailt(): void
    {
        $this->decide(['state' => 'accepted', 'effectiveAt' => self::NOW + 10 * self::DAY]);

        self::assertTrue($this->decision()->apply(5, self::NOW));

        self::assertSame(self::NOW + 10 * self::DAY, (int) $this->subscription()['endsAt']);
        self::assertSame(self::NOW, (int) $this->cancellation()['decidedAt']);
        self::assertSame([['order' => 90, 'number' => 'WEB-00090-1-K1', 'subscription' => 'WEB-00090-1', 'accepted' => true, 'effectiveAt' => self::NOW + 10 * self::DAY, 'reason' => '']], $this->mails);
    }

    public function testEinFrueheresEndeBleibt(): void
    {
        $this->connection->update('tl_consho_subscription', ['endsAt' => self::NOW + 5 * self::DAY], ['id' => 1]);
        $this->decide(['state' => 'accepted', 'effectiveAt' => self::NOW + 10 * self::DAY]);

        $this->decision()->apply(5, self::NOW);

        self::assertSame(self::NOW + 5 * self::DAY, (int) $this->subscription()['endsAt']);
        // Abo-7: the mail names the end the contract really has.
        self::assertSame(self::NOW + 5 * self::DAY, $this->mails[0]['effectiveAt']);
        // Result page and back end read the same end.
        self::assertSame(self::NOW + 5 * self::DAY, (int) $this->cancellation()['effectiveAt']);
    }

    public function testEinSpaeteresEndeWirdVorgezogen(): void
    {
        $this->connection->update('tl_consho_subscription', ['endsAt' => self::NOW + 50 * self::DAY], ['id' => 1]);
        $this->decide(['state' => 'accepted', 'effectiveAt' => self::NOW + 10 * self::DAY]);

        $this->decision()->apply(5, self::NOW);

        self::assertSame(self::NOW + 10 * self::DAY, (int) $this->subscription()['endsAt']);
    }

    public function testAbgelehntAendertNichtsAmAboUndMailtDieBegruendung(): void
    {
        $this->decide(['state' => 'rejected', 'rejectReason' => '  Kein wichtiger Grund.  ']);

        self::assertTrue($this->decision()->apply(5, self::NOW));

        self::assertSame(0, (int) $this->subscription()['endsAt']);
        self::assertSame(self::NOW, (int) $this->cancellation()['decidedAt']);
        self::assertFalse($this->mails[0]['accepted']);
        self::assertNull($this->mails[0]['effectiveAt']);
        self::assertSame('Kein wichtiger Grund.', $this->mails[0]['reason']);
    }

    public function testNurEinmal(): void
    {
        $this->decide(['state' => 'accepted', 'effectiveAt' => self::NOW + 10 * self::DAY]);
        $this->decision()->apply(5, self::NOW);

        self::assertNull($this->decision()->apply(5, self::NOW + 60));
        self::assertCount(1, $this->mails);
        self::assertSame(self::NOW, (int) $this->cancellation()['decidedAt']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nichtsZuEntscheiden(): iterable
    {
        yield 'wartet noch' => [['state' => 'received']];
        yield 'angenommen ohne Ende' => [['state' => 'accepted']];
        yield 'abgelehnt ohne Begründung' => [['state' => 'rejected', 'rejectReason' => '   ']];
        yield 'ordentlich' => [['kind' => 'ordinary', 'state' => 'accepted', 'effectiveAt' => self::NOW + self::DAY]];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @dataProvider nichtsZuEntscheiden
     */
    public function testNichtsZuEntscheiden(array $values): void
    {
        $this->decide($values);

        self::assertNull($this->decision()->apply(5, self::NOW));
        self::assertNull($this->cancellation()['decidedAt']);
        self::assertSame(0, (int) $this->subscription()['endsAt']);
        self::assertSame([], $this->mails);
    }

    public function testEinBeendetesAboBekommtNurDieEntscheidung(): void
    {
        $this->connection->update('tl_consho_subscription', ['state' => 'ended', 'endsAt' => self::NOW - self::DAY], ['id' => 1]);
        $this->decide(['state' => 'accepted', 'effectiveAt' => self::NOW + 10 * self::DAY]);

        $this->decision()->apply(5, self::NOW);

        self::assertSame(self::NOW - self::DAY, (int) $this->subscription()['endsAt']);
        self::assertSame(self::NOW, (int) $this->cancellation()['decidedAt']);
        self::assertSame(self::NOW - self::DAY, $this->mails[0]['effectiveAt'], 'das Ende, zu dem es schon endete');
    }

    public function testOhneMailBleibtDieEntscheidung(): void
    {
        $this->mailLeaves = false;
        $this->decide(['state' => 'accepted', 'effectiveAt' => self::NOW + 10 * self::DAY]);

        self::assertFalse($this->decision()->apply(5, self::NOW));
        self::assertSame(self::NOW, (int) $this->cancellation()['decidedAt']);
        self::assertNull($this->cancellation()['decisionSentAt'], 'E10: der Befund DecisionNotSent liest genau das');
    }

    public function testDieGesendeteEntscheidungTraegtIhrDatum(): void
    {
        $this->decide(['state' => 'accepted', 'effectiveAt' => self::NOW + 10 * self::DAY]);

        $this->decision()->apply(5, self::NOW);

        self::assertSame(self::NOW, (int) $this->cancellation()['decisionSentAt']);
    }

    public function testErneutSendenSchicktDieselbeEntscheidungOhneSieNochmalAnzuwenden(): void
    {
        $this->mailLeaves = false;
        $this->decide(['state' => 'rejected', 'rejectReason' => 'Kein wichtiger Grund']);
        $this->decision()->apply(5, self::NOW);

        $this->mailLeaves = true;
        $this->mails = [];

        self::assertTrue($this->decision()->resend(5, self::NOW + 60));
        self::assertSame([['order' => 90, 'number' => 'WEB-00090-1-K1', 'subscription' => 'WEB-00090-1', 'accepted' => false, 'effectiveAt' => null, 'reason' => 'Kein wichtiger Grund']], $this->mails);
        self::assertSame(self::NOW + 60, (int) $this->cancellation()['decisionSentAt']);
        self::assertSame(self::NOW, (int) $this->cancellation()['decidedAt'], 'die Entscheidung selbst bleibt');
    }

    public function testErneutSendenNenntDasAngenommeneEnde(): void
    {
        $this->mailLeaves = false;
        $this->decide(['state' => 'accepted', 'effectiveAt' => self::NOW + 10 * self::DAY]);
        $this->decision()->apply(5, self::NOW);

        $this->mailLeaves = true;
        $this->mails = [];

        self::assertTrue($this->decision()->resend(5, self::NOW + 60));
        self::assertSame([['order' => 90, 'number' => 'WEB-00090-1-K1', 'subscription' => 'WEB-00090-1', 'accepted' => true, 'effectiveAt' => self::NOW + 10 * self::DAY, 'reason' => '']], $this->mails);
    }

    public function testErneutSendenOhneAnlass(): void
    {
        // Not decided: nothing to send.
        self::assertNull($this->decision()->resend(5, self::NOW));

        // Already sent, including the migration's 0: no second mail.
        $this->decide(['state' => 'accepted', 'effectiveAt' => self::NOW + 10 * self::DAY, 'decidedAt' => self::NOW, 'decisionSentAt' => 0]);

        self::assertNull($this->decision()->resend(5, self::NOW));
        self::assertSame([], $this->mails);
    }

    public function testEinFehlschlagBeimErneutenSendenLaesstDenBefundStehen(): void
    {
        $this->mailLeaves = false;
        $this->decide(['state' => 'accepted', 'effectiveAt' => self::NOW + 10 * self::DAY]);
        $this->decision()->apply(5, self::NOW);

        self::assertFalse($this->decision()->resend(5, self::NOW + 60));
        self::assertNull($this->cancellation()['decisionSentAt']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function decide(array $values): void
    {
        $this->connection->update('tl_consho_cancellation', $values, ['id' => 5]);
    }

    private function decision(): CancellationDecision
    {
        $lock = new class($this->connection) implements SubscriptionLockInterface {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function lock(int $subscriptionId): array|null
            {
                $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = ?', [$subscriptionId]);

                return false === $row ? null : $row;
            }
        };

        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/bestellung');

        $mail = $this->createMock(OrderEventMail::class);
        $mail->method('cancellationDecided')->willReturnCallback(function (int $orderId, string $number, string $subscription, bool $accepted, int|null $effectiveAt, string $reason): bool {
            $this->mails[] = ['order' => $orderId, 'number' => $number, 'subscription' => $subscription, 'accepted' => $accepted, 'effectiveAt' => $effectiveAt, 'reason' => $reason];

            return $this->mailLeaves;
        });

        return new CancellationDecision($this->connection, $lock, $mail, $links, new SubscriptionContact($this->connection));
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = 1') ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancellation(): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM tl_consho_cancellation WHERE id = 5') ?: [];
    }
}

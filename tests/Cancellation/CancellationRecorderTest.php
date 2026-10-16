<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cancellation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationDeclaration;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationKind;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationOutcome;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationRecorder;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationSource;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationTerm;
use Webwerkwien\ContaoConshoBundle\Cancellation\SubscriptionContact;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-7 (P54–P59, P62): Die Kündigung wird zuerst geschrieben, dann bestätigt.
 */
final class CancellationRecorderTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    private Connection $connection;

    /** @var list<array<string, mixed>> */
    private array $mails = [];

    private bool $mailLeaves = true;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_subscription', 'tl_consho_cancellation', 'tl_consho_order', 'tl_consho_order_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        // Kauf mit alter Adresse, Folgebestellung mit der neuen: die neue gilt (P58).
        $this->connection->insert('tl_consho_order', ['id' => 90, 'orderNumber' => 'WEB-00090', 'email' => 'alt@example.test', 'language' => 'de', 'createdAt' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => 91, 'orderNumber' => 'WEB-00091', 'email' => 'neu@example.test', 'language' => 'de', 'subscription' => 1, 'createdAt' => 2]);
        $this->connection->insert('tl_consho_order_item', ['id' => 5, 'pid' => 90, 'productName' => 'E-Book Kräuterkunde']);

        // Monatsabo, ein Jahr, danach unbefristet; 30 Tage Frist. Ruht gerade.
        $this->connection->insert('tl_consho_subscription', [
            'id' => 1, 'subscriptionNumber' => 'WEB-00090-1', 'firstOrder' => 90, 'orderItem' => 5, 'state' => 'suspended', 'phase' => 'minimum',
            'renewal' => 'indefinite', 'termLength' => 1, 'termUnit' => 'year', 'billingInterval' => 1, 'noticeDays' => 30,
            'startedAt' => $this->at('2026-01-15 10:00'), 'minimumEndsAt' => $this->at('2027-01-15 10:00'),
        ]);
    }

    public function testOrdentlichSetztDasEndeUndLaesstDenZustand(): void
    {
        $result = $this->recorder()->record($this->ordinary(), $this->at('2026-06-01 12:00'));

        self::assertSame(CancellationOutcome::Recorded, $result->outcome);
        self::assertSame('WEB-00090-1-K1', $result->number);
        self::assertSame($this->at('2027-01-15 10:00'), $result->effectiveAt);
        self::assertTrue($result->confirmed);

        $subscription = $this->subscription();
        self::assertSame($this->at('2027-01-15 10:00'), (int) $subscription['endsAt']);
        // Der Verzug wird bis zum Ende weiter beurteilt (P56): der Zustand bleibt.
        self::assertSame('suspended', $subscription['state']);

        $row = $this->cancellation(1);
        self::assertSame('effective', $row['state']);
        self::assertSame('ordinary', $row['kind']);
        self::assertSame('form', $row['source']);
        self::assertSame('Jane Doe', $row['name']);
        self::assertSame('neu@example.test', $row['email']);
        self::assertSame($this->at('2026-06-01 12:00'), (int) $row['receivedAt']);
        self::assertNull($row['requestedAt']);
        self::assertNull($row['reason'], 'nur eine außerordentliche Kündigung hat einen Grund');
        self::assertSame($this->at('2027-01-15 10:00'), (int) $row['effectiveAt']);
        self::assertNotNull($row['confirmationSentAt']);
    }

    public function testDieBestaetigungNenntInhaltEingangUndEnde(): void
    {
        $this->recorder()->record($this->ordinary($this->at('2026-12-01 00:00')), $this->at('2026-06-01 12:00'));

        self::assertCount(1, $this->mails);
        $mail = $this->mails[0];
        self::assertSame(91, $mail['order']);
        self::assertSame('WEB-00090-1-K1', $mail['number']);
        self::assertSame('WEB-00090-1', $mail['subscription']);
        self::assertSame('E-Book Kräuterkunde', $mail['product']);
        self::assertSame($this->at('2026-06-01 12:00'), $mail['receivedAt']);
        self::assertSame($this->at('2026-12-01 00:00'), $mail['requestedAt']);
        self::assertSame($this->at('2027-01-15 10:00'), $mail['effectiveAt']);
        self::assertTrue($mail['earliestApplied']);
        self::assertSame('neu@example.test', $mail['email']);
    }

    public function testEineZweiteOrdentlicheKuendigungAendertNichts(): void
    {
        $this->recorder()->record($this->ordinary(), $this->at('2026-06-01 12:00'));
        $second = $this->recorder()->record($this->ordinary($this->at('2027-06-30 00:00')), $this->at('2026-06-02 12:00'));

        self::assertSame(CancellationOutcome::AlreadyEnding, $second->outcome);
        self::assertSame($this->at('2027-01-15 10:00'), $second->effectiveAt);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cancellation'));
        self::assertSame($this->at('2027-01-15 10:00'), (int) $this->subscription()['endsAt']);
    }

    public function testAusserordentlichWartetAufDenBetreiber(): void
    {
        $result = $this->recorder()->record(
            new CancellationDeclaration(1, CancellationSource::Account, CancellationKind::Extraordinary, 'Jane Doe', null, '  Umzug ins Ausland  '),
            $this->at('2026-06-01 12:00'),
        );

        self::assertSame(CancellationOutcome::Recorded, $result->outcome);
        self::assertNull($result->effectiveAt);
        self::assertSame(0, (int) $this->subscription()['endsAt']);

        $row = $this->cancellation(1);
        self::assertSame('received', $row['state']);
        self::assertSame('Umzug ins Ausland', $row['reason']);
        self::assertNull($row['effectiveAt']);
        self::assertSame('extraordinary', $this->mails[0]['kind']);

        // Eine ordentliche daneben bleibt möglich und zählt weiter.
        self::assertSame('WEB-00090-1-K2', $this->recorder()->record($this->ordinary(), $this->at('2026-06-02 12:00'))->number);
    }

    public function testEinBeendetesAboKannNichtGekuendigtWerden(): void
    {
        $this->connection->update('tl_consho_subscription', ['state' => 'ended', 'endsAt' => $this->at('2026-05-01 10:00')], ['id' => 1]);

        self::assertSame(CancellationOutcome::Ended, $this->recorder()->record($this->ordinary(), $this->at('2026-06-01 12:00'))->outcome);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cancellation'));
        self::assertSame([], $this->mails);
    }

    public function testUnbekanntesAbo(): void
    {
        $declaration = new CancellationDeclaration(99, CancellationSource::Form, CancellationKind::Ordinary, 'Jane Doe');

        self::assertSame(CancellationOutcome::UnknownSubscription, $this->recorder()->record($declaration, $this->at('2026-06-01 12:00'))->outcome);
    }

    public function testOhneMailBleibtDieKuendigungUndDieLueckeSichtbar(): void
    {
        $this->mailLeaves = false;

        $result = $this->recorder()->record($this->ordinary(), $this->at('2026-06-01 12:00'));

        self::assertSame(CancellationOutcome::Recorded, $result->outcome);
        self::assertFalse($result->confirmed);
        self::assertNull($this->cancellation(1)['confirmationSentAt']);
        self::assertSame($this->at('2027-01-15 10:00'), (int) $this->subscription()['endsAt']);
    }

    private function ordinary(int|null $requestedAt = null): CancellationDeclaration
    {
        return new CancellationDeclaration(1, CancellationSource::Form, CancellationKind::Ordinary, ' Jane Doe ', $requestedAt, 'wird verworfen');
    }

    private function recorder(): CancellationRecorder
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
        $mail->method('cancellationReceived')->willReturnCallback(function (int $orderId, array $cancellation): bool {
            $this->mails[] = ['order' => $orderId] + $cancellation;

            return $this->mailLeaves;
        });

        return new CancellationRecorder($this->connection, $lock, new CancellationTerm(new SubscriptionCalendar(self::ZONE)), $mail, $links, new SubscriptionContact($this->connection));
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
    private function cancellation(int $id): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM tl_consho_cancellation WHERE id = ?', [$id]) ?: [];
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }
}

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
 * Abo-7 (P54–P59, P62): The cancellation is written first, then confirmed.
 */
final class CancellationRecorderTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    private Connection $connection;

    /** @var list<array<string, mixed>> */
    private array $mails = [];

    /** @var list<array<string, mixed>> */
    private array $operatorMails = [];

    private bool $mailLeaves = true;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_subscription', 'tl_consho_cancellation', 'tl_consho_order', 'tl_consho_order_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        // Purchase with the old address, follow-up order with the new one: the new one applies (P58).
        $this->connection->insert('tl_consho_order', ['id' => 90, 'orderNumber' => 'WEB-00090', 'email' => 'alt@example.test', 'language' => 'de', 'createdAt' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => 91, 'orderNumber' => 'WEB-00091', 'email' => 'neu@example.test', 'language' => 'de', 'subscription' => 1, 'createdAt' => 2]);
        $this->connection->insert('tl_consho_order_item', ['id' => 5, 'pid' => 90, 'productName' => 'E-Book Kräuterkunde']);

        // Monthly subscription, one year, unlimited after that; 30 days' notice. Currently paused.
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
        // The arrears keep being assessed until the end (P56): the state stays.
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
        self::assertFalse($mail['priceChange']);
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

        // An ordinary one next to it stays possible and keeps counting.
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

    /**
     * Abo-9, P128: the customer's notice ran out on 16.12. — the operator's
     * date stands anyway, it is not moved to the customer's next end.
     */
    public function testDerBetreiberKuendigtGenauZumGewaehltenTermin(): void
    {
        $declaration = new CancellationDeclaration(1, CancellationSource::Operator, CancellationKind::Ordinary, 'Admin', $this->at('2027-01-15 10:00'), ' Preisänderung ', true);

        $result = $this->recorder()->record($declaration, $this->at('2027-01-10 09:00'));

        self::assertSame(CancellationOutcome::Recorded, $result->outcome);
        self::assertSame($this->at('2027-01-15 10:00'), $result->effectiveAt);
        self::assertFalse($result->earliestApplied);
        self::assertSame($this->at('2027-01-15 10:00'), (int) $this->subscription()['endsAt']);

        $row = $this->cancellation(1);
        self::assertSame('operator', $row['source']);
        self::assertSame('Preisänderung', $row['reason'], 'der Grund des Betreibers bleibt');
        self::assertSame('Admin', $row['name']);
        self::assertNotNull($row['confirmationSentAt']);

        self::assertSame([], $this->mails, 'keine Eingangsbestätigung');
        self::assertCount(1, $this->operatorMails);
        self::assertSame([
            'order' => 91,
            'number' => 'WEB-00090-1-K1',
            'subscription' => 'WEB-00090-1',
            'product' => 'E-Book Kräuterkunde',
            'effectiveAt' => $this->at('2027-01-15 10:00'),
            'reason' => 'Preisänderung',
            'renewalHint' => true,
        ], $this->operatorMails[0]);
    }

    /**
     * Abo-9: the operator ends the monthly subscription on 15.04. with his
     * longer notice; the customer's own cancellation reaches 03.03. — it is
     * recorded and moves the end forward. A later one changes nothing.
     */
    public function testDerKundeKannNachDerBetreiberkuendigungFrueherKuendigen(): void
    {
        $this->connection->update('tl_consho_subscription', ['phase' => 'indefinite'], ['id' => 1]);
        $this->recorder()->record(new CancellationDeclaration(1, CancellationSource::Operator, CancellationKind::Ordinary, 'Admin', $this->at('2027-04-15 10:00'), 'Grund'), $this->at('2027-01-20 09:00'));

        // The operator does not cancel again on top of a known end, not even
        // to an earlier date the customer's term would allow.
        $again = $this->recorder()->record(new CancellationDeclaration(1, CancellationSource::Operator, CancellationKind::Ordinary, 'Admin', $this->at('2027-03-15 10:00'), 'Grund'), $this->at('2027-02-01 09:00'));
        self::assertSame(CancellationOutcome::AlreadyEnding, $again->outcome);

        // The same day as the known end spares nothing.
        $sameDay = $this->recorder()->record($this->ordinary($this->at('2027-04-15 00:00')), $this->at('2027-02-01 12:00'));
        self::assertSame(CancellationOutcome::AlreadyEnding, $sameDay->outcome);

        $later =$this->recorder()->record($this->ordinary($this->at('2027-05-15 10:00')), $this->at('2027-02-01 12:00'));
        self::assertSame(CancellationOutcome::AlreadyEnding, $later->outcome);
        self::assertSame($this->at('2027-04-15 10:00'), (int) $this->subscription()['endsAt']);

        $earlier = $this->recorder()->record($this->ordinary(), $this->at('2027-02-01 12:00'));

        self::assertSame(CancellationOutcome::Recorded, $earlier->outcome);
        self::assertSame('WEB-00090-1-K2', $earlier->number);
        // Open-ended: received plus the 30 days' notice (CancellationTerm).
        self::assertSame($this->at('2027-03-03 12:00'), $earlier->effectiveAt);
        self::assertSame($this->at('2027-03-03 12:00'), (int) $this->subscription()['endsAt']);
        self::assertCount(1, $this->mails, 'der Kunde bekommt seine Eingangsbestätigung');
    }

    /**
     * The renewal starts at the known end (P68): an earlier end of the old
     * subscription would leave a gap.
     */
    public function testNachDerVerlaengerungKeinVorziehen(): void
    {
        $this->connection->update('tl_consho_subscription', ['phase' => 'indefinite', 'endsAt' => $this->at('2027-04-15 10:00'), 'renewedBy' => 2], ['id' => 1]);

        $result = $this->recorder()->record($this->ordinary(), $this->at('2027-02-01 12:00'));

        self::assertSame(CancellationOutcome::AlreadyEnding, $result->outcome);
        self::assertSame($this->at('2027-04-15 10:00'), (int) $this->subscription()['endsAt']);
    }

    /**
     * The most frequent case: a second "earliest possible" cancellation
     * reaches exactly the known end — nothing new.
     */
    public function testZweimalFruehestmoeglichAendertNichts(): void
    {
        $this->recorder()->record($this->ordinary(), $this->at('2026-06-01 12:00'));
        $second = $this->recorder()->record($this->ordinary(), $this->at('2026-06-02 12:00'));

        self::assertSame(CancellationOutcome::AlreadyEnding, $second->outcome);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cancellation'));
    }

    public function testDerBetreiberKuendigtNichtInDieVergangenheit(): void
    {
        $declaration = new CancellationDeclaration(1, CancellationSource::Operator, CancellationKind::Ordinary, 'Admin', $this->at('2027-01-15 10:00'));

        try {
            $this->recorder()->record($declaration, $this->at('2027-01-15 10:00'));
            self::fail('expected an exception');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cancellation'));
        self::assertNull($this->subscription()['endsAt']);
    }

    public function testDerBetreiberKuendigtNurOrdentlich(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->recorder()->record(new CancellationDeclaration(1, CancellationSource::Operator, CancellationKind::Extraordinary, 'Admin', $this->at('2027-01-15 10:00'), 'Grund'), $this->at('2026-06-01 12:00'));
    }

    /**
     * Abo-10, P121: a declined price offer ends at its effective day, with
     * the usual confirmation of receipt — and moves a later end forward.
     */
    public function testAbgelehntesPreisangebotEndetZumGueltigkeitstag(): void
    {
        $this->connection->update('tl_consho_subscription', ['phase' => 'indefinite', 'endsAt' => $this->at('2027-04-15 10:00')], ['id' => 1]);

        $result = $this->recorder()->record(new CancellationDeclaration(1, CancellationSource::PriceChange, CancellationKind::Ordinary, 'Jane Doe', $this->at('2027-03-15 10:00')), $this->at('2027-02-01 12:00'));

        self::assertSame(CancellationOutcome::Recorded, $result->outcome);
        self::assertSame($this->at('2027-03-15 10:00'), $result->effectiveAt);
        self::assertSame($this->at('2027-03-15 10:00'), (int) $this->subscription()['endsAt']);
        self::assertSame('priceChange', $this->cancellation(1)['source']);
        self::assertCount(1, $this->mails, 'die gewohnte Eingangsbestätigung');
        self::assertTrue($this->mails[0]['priceChange'], 'die Mail nennt den Anlass');

        // An end on the day or before stands.
        $again = $this->recorder()->record(new CancellationDeclaration(1, CancellationSource::PriceChange, CancellationKind::Ordinary, '', $this->at('2027-04-15 10:00')), $this->at('2027-02-02 12:00'));
        self::assertSame(CancellationOutcome::AlreadyEnding, $again->outcome);
    }

    /**
     * Abo-10, P122: the lapse records inside the offer's commit, without a
     * mail of its own — and only there.
     */
    public function testFristablaufOhneEigeneMailNurInEinerTransaktion(): void
    {
        $declaration = new CancellationDeclaration(1, CancellationSource::PriceChange, CancellationKind::Ordinary, '', $this->at('2027-01-15 10:00'));

        $this->connection->beginTransaction();
        $result = $this->recorder()->recordWithin($declaration, $this->at('2026-12-16 10:00'));
        $this->connection->commit();

        self::assertSame(CancellationOutcome::Recorded, $result->outcome);
        self::assertSame($this->at('2027-01-15 10:00'), (int) $this->subscription()['endsAt']);
        self::assertNull($this->cancellation(1)['confirmationSentAt']);
        self::assertSame([], $this->mails);

        $this->expectException(\LogicException::class);
        $this->recorder()->recordWithin($declaration, $this->at('2026-12-16 10:00'));
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
        $mail->method('cancellationByOperator')->willReturnCallback(function (int $orderId, array $cancellation): bool {
            $this->operatorMails[] = ['order' => $orderId] + $cancellation;

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

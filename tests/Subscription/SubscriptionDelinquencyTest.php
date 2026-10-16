<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalance;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquency;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-5 (A6, P33–P36): Verzug — ruhend und wieder aktiv, immer aus den
 * Bestellungen berechnet.
 */
final class SubscriptionDelinquencyTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    private Connection $connection;

    /** @var array<int, string> what each order still owes, by id */
    private array $outstanding = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_subscription', 'tl_consho_entitlement'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        // Kauf WEB-00090 (bezahlt), Folgebestellung WEB-00091 „Auf Rechnung", Ziel 14.03.
        $this->connection->insert('tl_consho_order', ['id' => 90, 'orderNumber' => 'WEB-00090', 'grandTotal' => '12.0000']);
        $this->connection->insert('tl_consho_order', ['id' => 91, 'orderNumber' => 'WEB-00091', 'subscription' => 1, 'grandTotal' => '12.0000', 'paymentDueAt' => $this->at('2026-03-14 23:59:59')]);
        // Gegenprobe: eine überfällige Bestellung eines anderen Abos.
        $this->connection->insert('tl_consho_order', ['id' => 95, 'orderNumber' => 'WEB-00095', 'subscription' => 2, 'grandTotal' => '12.0000', 'paymentDueAt' => $this->at('2026-01-01 23:59:59')]);
        $this->outstanding = [90 => '0', 91 => '12.0000', 95 => '12.0000'];

        $this->connection->insert('tl_consho_subscription', ['id' => 1, 'subscriptionNumber' => 'WEB-00090-1', 'firstOrder' => 90, 'graceDays' => 14, 'state' => 'active']);
        $this->connection->insert('tl_consho_subscription', ['id' => 2, 'subscriptionNumber' => 'WEB-00094-1', 'firstOrder' => 94, 'graceDays' => 14, 'state' => 'active']);

        $this->connection->insert('tl_consho_entitlement', ['id' => 30, 'pid' => 90, 'subscription' => 1]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 31, 'pid' => 94, 'subscription' => 2]);
    }

    /**
     * Abo-7b (P69): Abo 3 verlängert Abo 1 und hält dessen Lizenz. Die offene
     * Folgebestellung 91 des alten Vertrags lässt sie auch hier ruhen —
     * „Lizenz verlängern" ist kein Weg an einer offenen Rechnung vorbei.
     */
    public function testEineOffeneRechnungDesVorgaengersLaesstDieVerlaengerungRuhen(): void
    {
        $this->connection->update('tl_consho_subscription', ['renewedBy' => 3, 'state' => 'ended'], ['id' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => 96, 'orderNumber' => 'WEB-00096', 'grandTotal' => '12.0000']);
        $this->connection->insert('tl_consho_subscription', ['id' => 3, 'subscriptionNumber' => 'WEB-00096-1', 'firstOrder' => 96, 'graceDays' => 14, 'state' => 'active', 'predecessor' => 1]);
        $this->connection->update('tl_consho_entitlement', ['subscription' => 3, 'pid' => 96], ['id' => 30]);
        $this->outstanding[96] = '0';

        $change = $this->delinquency()->reassess(3, $this->at('2026-03-29 00:30'));

        self::assertTrue($change?->suspended);
        self::assertSame([91], $change->overdueOrders);
        self::assertNotNull($this->suspendedAt(30));
    }

    /**
     * Review S3: Ist die Lizenz übergegangen, schweigt das alte Abo — sonst
     * kämen zwei Mails für eine Rechnung. Solange sie noch bei ihm liegt
     * (Verlängerung nicht freigegeben), ruht es wie bisher.
     */
    public function testDasVerlaengerteAboSchweigtNurOhneEigeneLizenz(): void
    {
        $now = $this->at('2026-03-29 00:30');
        $this->connection->update('tl_consho_subscription', ['renewedBy' => 3], ['id' => 1]);

        self::assertTrue($this->delinquency()->reassess(1, $now)?->suspended, 'Lizenz noch hier');

        $this->connection->update('tl_consho_subscription', ['state' => 'active'], ['id' => 1]);
        $this->connection->update('tl_consho_entitlement', ['subscription' => 3, 'suspendedAt' => null], ['id' => 30]);

        self::assertNull($this->delinquency()->reassess(1, $now), 'Lizenz übergegangen');
        self::assertSame('active', $this->state(1));
    }

    /**
     * Zweites Review, S-2: Vor der Freigabe hält das alte Abo die Lizenz und
     * antwortet — die Verlängerung schweigt, sonst kämen zwei Mails.
     */
    public function testVorDerFreigabeSchweigtDieVerlaengerung(): void
    {
        $now = $this->at('2026-03-29 00:30');
        $this->connection->update('tl_consho_subscription', ['renewedBy' => 3], ['id' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => 96, 'orderNumber' => 'WEB-00096', 'grandTotal' => '12.0000']);
        $this->connection->insert('tl_consho_subscription', ['id' => 3, 'subscriptionNumber' => 'WEB-00096-1', 'firstOrder' => 96, 'graceDays' => 14, 'state' => 'active', 'predecessor' => 1]);
        $this->outstanding[96] = '0';

        self::assertNull($this->delinquency()->reassess(3, $now));
        self::assertSame('active', $this->state(3));
        self::assertTrue($this->delinquency()->reassess(1, $now)?->suspended, 'das alte antwortet');
    }

    /**
     * Zweites Review, L-1: Ruhte das alte Abo, als die Lizenz überging, bleibt
     * es nicht für immer „ruht" — ohne Mail zurück auf aktiv.
     */
    public function testEinVerlaengertesAboOhneLizenzRuhtNichtWeiter(): void
    {
        $this->connection->update('tl_consho_subscription', ['renewedBy' => 3, 'state' => 'suspended'], ['id' => 1]);
        $this->connection->update('tl_consho_entitlement', ['subscription' => 3], ['id' => 30]);

        self::assertNull($this->delinquency()->reassess(1, $this->at('2026-03-29 00:30')));
        self::assertSame('active', $this->state(1));
    }

    public function testOhneVerlaengerungZaehltDieRechnungEinesAnderenAbosNicht(): void
    {
        // Gegenprobe zum Test davor: dasselbe Abo 3, aber kein Nachfolger von 1.
        $this->connection->insert('tl_consho_order', ['id' => 96, 'orderNumber' => 'WEB-00096', 'grandTotal' => '12.0000']);
        $this->connection->insert('tl_consho_subscription', ['id' => 3, 'subscriptionNumber' => 'WEB-00096-1', 'firstOrder' => 96, 'graceDays' => 14, 'state' => 'active']);
        $this->outstanding[96] = '0';

        self::assertNull($this->delinquency()->reassess(3, $this->at('2026-03-29 00:30')));
    }

    public function testDieZahlungDesAltenVertragsFragtAuchDieVerlaengerung(): void
    {
        $this->connection->update('tl_consho_subscription', ['renewedBy' => 3], ['id' => 1]);
        $this->connection->insert('tl_consho_subscription', ['id' => 3, 'subscriptionNumber' => 'WEB-00096-1', 'firstOrder' => 96, 'state' => 'suspended', 'predecessor' => 1]);

        self::assertSame([1, 3], $this->delinquency()->subscriptionsOfOrder(91));
        self::assertSame([1, 3], $this->delinquency()->subscriptionsOfOrder(90), 'auch der Kauf');
        self::assertSame([2], $this->delinquency()->subscriptionsOfOrder(95), 'ein fremdes Abo ohne Nachfolger');
    }

    public function testInDerNachfristRuhtNichts(): void
    {
        // Ziel 14.03. + 14 Tage = 28.03. 23:59:59.
        self::assertNull($this->delinquency()->reassess(1, $this->at('2026-03-28 23:00')));
        self::assertSame('active', $this->state(1));
        self::assertNull($this->suspendedAt(30));
    }

    /**
     * Review Abo-5, L1: die Sekunde des Ablaufs. `>=` gegen `>` mutiert blieb
     * vorher grün.
     */
    public function testDieGrenzeDerNachfrist(): void
    {
        $graceEnds = $this->at('2026-03-28 23:59:59');

        self::assertNull($this->delinquency()->reassess(1, $graceEnds), 'in der letzten Sekunde noch nicht');
        self::assertTrue($this->delinquency()->reassess(1, $graceEnds + 1)?->suspended);
    }

    public function testNachDerNachfristRuhtDasAbo(): void
    {
        $now = $this->at('2026-03-29 00:30');
        $change = $this->delinquency()->reassess(1, $now);

        self::assertNotNull($change);
        self::assertTrue($change->suspended);
        self::assertSame([91], $change->overdueOrders);
        self::assertSame('WEB-00090-1', $change->subscriptionNumber);
        self::assertSame('suspended', $this->state(1));
        self::assertSame($now, $this->suspendedAt(30));

        // Nur dieses Abo.
        self::assertSame('active', $this->state(2));
        self::assertNull($this->suspendedAt(31));
    }

    public function testEinZweiterLaufAendertNichts(): void
    {
        $first = $this->at('2026-03-29 00:30');
        $this->delinquency()->reassess(1, $first);

        self::assertNull($this->delinquency()->reassess(1, $this->at('2026-03-29 01:30')));
        self::assertSame($first, $this->suspendedAt(30), 'ruht seit dem ersten Mal');
    }

    public function testDieZahlungWecktEsWieder(): void
    {
        $this->delinquency()->reassess(1, $this->at('2026-03-29 00:30'));
        $this->outstanding[91] = '0';

        $change = $this->delinquency()->reassess(1, $this->at('2026-04-02 09:00'));

        self::assertNotNull($change);
        self::assertFalse($change->suspended);
        self::assertSame([], $change->overdueOrders);
        self::assertSame('active', $this->state(1));
        self::assertNull($this->suspendedAt(30));
    }

    public function testEineTeilzahlungReichtNicht(): void
    {
        $this->outstanding[91] = '0.0100';

        self::assertTrue($this->delinquency()->reassess(1, $this->at('2026-03-29 00:30'))?->suspended);
    }

    public function testEineStornierteBestellungZaehltNicht(): void
    {
        $this->delinquency()->reassess(1, $this->at('2026-03-29 00:30'));
        $this->connection->update('tl_consho_order', ['cancelledAt' => $this->at('2026-03-30 10:00')], ['id' => 91]);

        self::assertFalse($this->delinquency()->reassess(1, $this->at('2026-03-30 11:00'))?->suspended);
    }

    /**
     * P34: Auch der Kauf selbst — wer die erste Rechnung nie zahlt, bekäme
     * sonst die ganze Mindestlaufzeit geschenkt.
     */
    public function testAuchDieErsteBestellungLoestVerzugAus(): void
    {
        $this->connection->update('tl_consho_order', ['paymentDueAt' => $this->at('2026-02-14 23:59:59')], ['id' => 90]);
        $this->outstanding = [90 => '12.0000', 91 => '0'];

        self::assertSame([90], $this->delinquency()->reassess(1, $this->at('2026-03-01 00:30'))?->overdueOrders);
    }

    public function testEineUeberzahlteBestellungIstKeinVerzug(): void
    {
        $this->outstanding[91] = '-1.0000';

        self::assertNull($this->delinquency()->reassess(1, $this->at('2026-03-29 00:30')));
    }

    /**
     * Eine Berechtigung, die erst freigegeben wird, während das Abo schon
     * ruht (Schlüsselvorrat spät aufgefüllt), ruht beim nächsten Lauf mit.
     */
    public function testEineSpaetFreigegebeneBerechtigungRuhtMit(): void
    {
        $this->delinquency()->reassess(1, $this->at('2026-03-29 00:30'));
        $this->connection->insert('tl_consho_entitlement', ['id' => 32, 'pid' => 90, 'subscription' => 1]);

        $later = $this->at('2026-03-29 01:30');
        self::assertNull($this->delinquency()->reassess(1, $later), 'der Zustand des Abos ändert sich nicht');
        self::assertSame($later, $this->suspendedAt(32));
    }

    public function testEinBeendetesAboFaengtNichtMehrZuRuhenAn(): void
    {
        $this->connection->update('tl_consho_subscription', ['state' => 'ended'], ['id' => 1]);

        self::assertNull($this->delinquency()->reassess(1, $this->at('2026-03-29 00:30')));
        self::assertNull($this->suspendedAt(30));
        self::assertSame('ended', $this->state(1));
    }

    /**
     * P64 (Review Abo-6, L4): Endet ein ruhendes Abo, bleibt die Berechtigung
     * ruhend stehen — die Zahlung gibt sie trotzdem frei, ohne Mail.
     */
    public function testEinBeendetesAboMitRuhenderBerechtigungWirdNachDerZahlungFrei(): void
    {
        $rest = $this->at('2026-03-29 00:30');
        $this->delinquency()->reassess(1, $rest);
        $this->connection->update('tl_consho_subscription', ['state' => 'ended', 'endsAt' => $this->at('2026-04-01 10:00')], ['id' => 1]);

        self::assertNull($this->delinquency()->reassess(1, $this->at('2026-04-02 09:00')));
        self::assertSame($rest, $this->suspendedAt(30), 'noch offen: ruht weiter');

        $this->outstanding[91] = '0';

        self::assertNull($this->delinquency()->reassess(1, $this->at('2026-04-03 09:00')), 'keine Änderung des Abos, also keine Mail');
        self::assertNull($this->suspendedAt(30));
        self::assertSame('ended', $this->state(1));
    }

    /**
     * Abo-7, P56: Gekündigt heißt `endsAt`, der Zustand bleibt — bis zum Ende
     * wird der Verzug weiter beurteilt, und die Zahlung weckt es wieder.
     */
    public function testEinGekuendigtesAboWirdBisZumEndeWeiterBeurteilt(): void
    {
        $this->connection->update('tl_consho_subscription', ['endsAt' => $this->at('2026-06-30 10:00')], ['id' => 1]);

        self::assertTrue($this->delinquency()->reassess(1, $this->at('2026-03-29 00:30'))?->suspended);

        $this->outstanding[91] = '0';

        self::assertFalse($this->delinquency()->reassess(1, $this->at('2026-04-02 09:00'))?->suspended);
        self::assertSame('active', $this->state(1));
    }

    /**
     * Die Nachfrist in Kalendertagen: über die Umstellung auf Sommerzeit
     * (29.03.2026) hat ein Tag 23 Stunden.
     */
    public function testDieNachfristZaehltKalendertage(): void
    {
        $this->connection->update('tl_consho_subscription', ['graceDays' => 1], ['id' => 1]);
        $this->connection->update('tl_consho_order', ['paymentDueAt' => $this->at('2026-03-28 23:59:59')], ['id' => 91]);

        // 28.03. 23:59:59 + 1 Tag = 29.03. 23:59:59 Wiener Zeit — 23 Stunden später.
        self::assertNull($this->delinquency()->reassess(1, $this->at('2026-03-29 23:30')));
        self::assertTrue($this->delinquency()->reassess(1, $this->at('2026-03-30 00:00:30'))?->suspended);
    }

    public function testDieAbosEinerBestellung(): void
    {
        self::assertSame([1], $this->delinquency()->subscriptionsOfOrder(91), 'Folgebestellung');
        self::assertSame([1], $this->delinquency()->subscriptionsOfOrder(90), 'Kauf');
        self::assertSame([], $this->delinquency()->subscriptionsOfOrder(77));
    }

    private function delinquency(): SubscriptionDelinquency
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

        $balances = $this->createStub(OrderBalanceReaderInterface::class);
        $balances->method('of')->willReturnCallback(fn (int $orderId): OrderBalance => new OrderBalance(
            $this->outstanding[$orderId] ?? '0',
            '0',
            PaymentState::Open,
            DeliveryState::Complete,
            [],
            true,
        ));

        return new SubscriptionDelinquency($this->connection, $lock, $balances, new SubscriptionCalendar(self::ZONE));
    }

    private function state(int $subscriptionId): string
    {
        return (string) $this->connection->fetchOne('SELECT state FROM tl_consho_subscription WHERE id = ?', [$subscriptionId]);
    }

    private function suspendedAt(int $entitlementId): int|null
    {
        $value = $this->connection->fetchOne('SELECT suspendedAt FROM tl_consho_entitlement WHERE id = ?', [$entitlementId]);

        return null === $value ? null : (int) $value;
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Subscription\LifecycleChange;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLifecycle;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-6 (A3, A8, P42–P49): Erinnerung vor dem Ende der Mindestlaufzeit und
 * was danach kommt.
 */
final class SubscriptionLifecycleTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_subscription');
        DcaSchema::create($this->connection, 'tl_consho_subscription_rule');
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 1, 'title' => 'Monatsabo']);

        // Jahresabo, eine Zahlung, danach unbefristet; Kündigungsfrist 14, Erinnerung 30 Tage.
        $this->connection->insert('tl_consho_subscription', [
            'id' => 1, 'subscriptionNumber' => 'WEB-00090-1', 'product' => 4, 'member' => 7, 'shop' => 1, 'quantity' => '2.0000',
            'rule' => 2, 'ruleTitle' => 'Jahresabo', 'termLength' => 1, 'termUnit' => 'year', 'billingInterval' => 0, 'renewal' => 'indefinite',
            'noticeDays' => 14, 'reminderDays' => 30, 'unitNet' => '10.0000', 'taxClass' => 3, 'currency' => 'EUR',
            'startedAt' => $this->at('2026-01-31 10:15'), 'minimumEndsAt' => $this->at('2027-01-31 10:15'),
            'periodFrom' => $this->at('2026-01-31 10:15'), 'periodUntil' => $this->at('2027-01-31 10:15'), 'nextBillingAt' => $this->at('2027-01-31 10:15'),
        ]);
    }

    public function testDieErinnerungKommtErstMitDemVorlauf(): void
    {
        // 31.01.2027 − 30 Tage = 01.01.2027 10:15.
        self::assertNull($this->lifecycle()->remind(1, $this->at('2027-01-01 10:14')));

        $due = $this->lifecycle()->remind(1, $this->at('2027-01-01 10:16'));

        self::assertNotNull($due);
        self::assertSame(RenewalKind::Indefinite, $due->renewal);
        self::assertSame('2027-01-31 10:15', $this->local($due->termEndsAt));
        self::assertSame('2027-01-17 10:15', $this->local($due->cancelUntil), 'Ende − 14 Tage Kündigungsfrist');
        self::assertSame($this->at('2027-01-01 10:16'), (int) $this->row()['reminderSentAt']);
    }

    public function testNurEinmalJeLaufzeit(): void
    {
        $this->lifecycle()->remind(1, $this->at('2027-01-02 00:00'));

        self::assertNull($this->lifecycle()->remind(1, $this->at('2027-01-03 00:00')));
    }

    /**
     * Variante A (2026-10-08): Ein „jederzeit kündbar" ohne monatliche Zahlung
     * gibt es nur noch als Altregel — die Erinnerung sagt, dass es endet; ein
     * Monatsabo läuft weiter.
     */
    public function testDieErinnerungEinerAltregelSagtDasEnde(): void
    {
        $due = $this->lifecycle()->remind(1, $this->at('2027-01-02 00:00'));
        self::assertNotNull($due);
        self::assertTrue($due->endsForLackOfPrice);

        $this->connection->update('tl_consho_subscription', ['reminderSentAt' => 0, 'billingInterval' => 1], ['id' => 1]);
        $monthly = $this->lifecycle()->remind(1, $this->at('2027-01-02 00:00'));
        self::assertNotNull($monthly);
        self::assertFalse($monthly->endsForLackOfPrice);
    }

    public function testKeineErinnerungNachEinerKuendigungOderInUnbefristet(): void
    {
        $this->connection->update('tl_consho_subscription', ['endsAt' => $this->at('2027-01-31 10:15')], ['id' => 1]);
        self::assertNull($this->lifecycle()->remind(1, $this->at('2027-01-02 00:00')), 'gekündigt: nichts zu erinnern');

        $this->connection->update('tl_consho_subscription', ['endsAt' => null, 'phase' => 'indefinite'], ['id' => 1]);
        self::assertNull($this->lifecycle()->remind(1, $this->at('2027-01-02 00:00')));
    }

    public function testKeineErinnerungAnEinVerlaengertesAbo(): void
    {
        // Abo-7b, Review S4: früh verlängert — „endet, jetzt verlängern" wäre falsch.
        $this->connection->update('tl_consho_subscription', ['renewedBy' => 9], ['id' => 1]);
        self::assertNull($this->lifecycle()->remind(1, $this->at('2027-01-02 00:00')));

        $this->connection->update('tl_consho_subscription', ['renewedBy' => 0], ['id' => 1]);
        self::assertNotNull($this->lifecycle()->remind(1, $this->at('2027-01-02 00:00')), 'Gegenprobe');
    }

    public function testVorDemEndeDerMindestlaufzeitKeinUebergang(): void
    {
        self::assertNull($this->lifecycle()->transition(1, $this->at('2027-01-31 10:14')));
    }

    /**
     * P44: Endet — vorbei, mit dem Ende der Mindestlaufzeit.
     */
    public function testEndetIstVorbei(): void
    {
        $this->connection->update('tl_consho_subscription', ['renewal' => 'ends', 'mandate' => 'cus_1|pm_2', 'mandateMethod' => 8], ['id' => 1]);

        self::assertSame(LifecycleChange::Ended, $this->lifecycle()->transition(1, $this->at('2027-01-31 11:00')));
        self::assertSame(['ended', '2027-01-31 10:15'], [$this->row()['state'], $this->local($this->row()['endsAt'])]);
        // Michael, 2026-10-03: „ja, beim Ende leeren" (Abo-4b, P98).
        self::assertSame(['', 0], [$this->row()['mandate'], (int) $this->row()['mandateMethod']]);
        self::assertNull($this->lifecycle()->transition(1, $this->at('2027-02-01 11:00')), 'einmal');
    }

    /**
     * P45: Gleiche Laufzeit — die Mindestlaufzeit rückt vom Beginn aus weiter,
     * die Erinnerung ist für die neue Runde wieder fällig.
     */
    public function testGleicheLaufzeitVerlaengert(): void
    {
        $this->connection->update('tl_consho_subscription', ['renewal' => 'sameTerm', 'reminderSentAt' => 123], ['id' => 1]);

        self::assertSame(LifecycleChange::Renewed, $this->lifecycle()->transition(1, $this->at('2027-01-31 11:00')));
        self::assertSame('2028-01-31 10:15', $this->local($this->row()['minimumEndsAt']));
        self::assertSame(0, (int) $this->row()['reminderSentAt']);
        self::assertSame(['active', 'minimum', '10.0000'], [$this->row()['state'], $this->row()['phase'], $this->number($this->row()['unitNet'])]);
    }

    /**
     * P17 für die Verlängerung: vom Beginn aus, nicht vom alten Ende. Mit einem
     * Jahr ab 31.01. liefern beide Wege dasselbe (Prüfungsnotiz Punkt 37) — mit
     * einem Monat nicht: 31.01. + 2 Monate = 31.03., 28.02. + 1 Monat = 28.03.
     */
    public function testDieVerlaengerungRechnetVomBeginn(): void
    {
        $this->connection->update('tl_consho_subscription', [
            'renewal' => 'sameTerm', 'termUnit' => 'month',
            'minimumEndsAt' => $this->at('2026-02-28 10:15'), 'periodUntil' => $this->at('2026-02-28 10:15'),
        ], ['id' => 1]);

        $this->lifecycle()->transition(1, $this->at('2026-02-28 11:00'));

        self::assertSame('2026-03-31 10:15', $this->local($this->row()['minimumEndsAt']));
    }

    /**
     * P46: Ein Monatsabo geht in Unbefristet über und behält seinen Preis.
     */
    public function testEinMonatsaboWirdUnbefristet(): void
    {
        $this->connection->update('tl_consho_subscription', ['billingInterval' => 1, 'periodFrom' => $this->at('2026-12-31 10:15')], ['id' => 1]);

        self::assertSame(LifecycleChange::OpenEnded, $this->lifecycle()->transition(1, $this->at('2027-01-31 11:00')));
        self::assertSame(['indefinite', 1, '10.0000'], [$this->row()['phase'], (int) $this->row()['billingInterval'], $this->number($this->row()['unitNet'])]);
    }

    /**
     * Variante A (2026-10-08): keine Umwandlung mehr in den Monatspreis. Eine
     * Altregel „jederzeit kündbar" mit einer Zahlung je Laufzeit endet — auch
     * wenn es ein Monatsabo desselben Produkts gäbe; Preis, Regel und Titel
     * bleiben, wie sie waren.
     */
    public function testEineAltregelOhneMonatlicheZahlungEndet(): void
    {
        self::assertSame(LifecycleChange::Ended, $this->lifecycle()->transition(1, $this->at('2027-01-31 11:00')));

        $row = $this->row();
        self::assertSame(['ended', 'minimum', 0, 2, 'Jahresabo', '10.0000'], [$row['state'], $row['phase'], (int) $row['billingInterval'], (int) $row['rule'], $row['ruleTitle'], $this->number($row['unitNet'])]);
        self::assertSame('2027-01-31 10:15', $this->local($row['endsAt']));
    }

    /**
     * P49: Ein bekanntes Ende beendet — auch ein ruhendes Abo, auch in
     * Unbefristet.
     */
    public function testEinBekanntesEndeBeendet(): void
    {
        $this->connection->update('tl_consho_subscription', ['phase' => 'indefinite', 'state' => 'suspended', 'endsAt' => $this->at('2027-03-17 10:15')], ['id' => 1]);

        self::assertNull($this->lifecycle()->transition(1, $this->at('2027-03-17 10:14')));
        self::assertSame(LifecycleChange::Ended, $this->lifecycle()->transition(1, $this->at('2027-03-17 10:16')));
        self::assertSame('ended', $this->row()['state']);
    }

    /**
     * Ist eine Periode der Mindestlaufzeit noch nicht abgerechnet, wartet der
     * Übergang — sonst bekäme sie den neuen Preis.
     */
    public function testDerUebergangWartetAufDieAbrechnung(): void
    {
        $this->connection->update('tl_consho_subscription', ['billingInterval' => 1, 'periodUntil' => $this->at('2026-12-31 10:15')], ['id' => 1]);

        self::assertNull($this->lifecycle()->transition(1, $this->at('2027-02-05 11:00')));
        self::assertSame('minimum', $this->row()['phase']);
    }

    private function lifecycle(): SubscriptionLifecycle
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

        return new SubscriptionLifecycle($this->connection, $lock, new SubscriptionCalendar(self::ZONE));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = 1') ?: [];
    }

    private function number(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }

    private function local(mixed $timestamp): string
    {
        return (new \DateTimeImmutable('@'.(int) $timestamp))->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d H:i');
    }
}

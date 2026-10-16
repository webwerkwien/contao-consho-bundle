<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Withdrawal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WindowState;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalWindowReader;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Das Fenster einer gespeicherten Bestellung, mit dem echten Saldo.
 */
final class WithdrawalWindowReaderTest extends TestCase
{
    private const ORDER = 7;

    private Connection $connection;

    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        // From the DCA (acceptance WG-7, Prüfungsnotiz Punkt 56), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        $this->connection->insert('tl_consho_shop', ['id' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => self::ORDER, 'shop' => 1, 'grandTotal' => '10.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'quantity' => '2.0000']);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    public function testDieSpaeterGemeldeteLieferungSchiebtDasFensterHinaus(): void
    {
        // Versendet am 01.10., erst am 03.10. gemeldet: es zählt der 03.10.
        $this->deliver(1, '2026-10-01 10:00', '2026-10-03 09:00', '2');

        self::assertSame($this->at('2026-10-24 23:59:59'), $this->reader()->of(self::ORDER)?->closesAt);
    }

    public function testEineNichtGemeldeteLieferungZaehltNicht(): void
    {
        $this->deliver(1, '2026-10-01 10:00', '2026-10-01 11:00', '1');
        $this->deliver(2, '2026-10-05 10:00', null, '1');

        $window = $this->reader()->of(self::ORDER);

        self::assertNotNull($window);
        self::assertNull($window->closesAt, 'ein Stück ist noch nicht versandt');
    }

    /**
     * F, Abnahme 2026-09-29: Ist jede Warenzeile durch freigegebene digitale
     * Inhalte aufgebraucht, gibt es nichts mehr zu widerrufen — die Funktion
     * ist aus. Eine gemischte Bestellung behält ihr Fenster für das Paket.
     */
    public function testFreigegebeneDigitaleInhalteSchliessenDieFunktion(): void
    {
        $this->deliver(1, '2026-10-01 10:00', '2026-10-01 10:00', '2');
        $this->connection->insert('tl_consho_entitlement', ['pid' => self::ORDER, 'orderItem' => 1, 'quantity' => '2.0000']);

        $released = $this->reader()->of(self::ORDER);
        self::assertNotNull($released);
        self::assertSame(WindowState::Off, $released->state);

        // Dazu ein Buch, noch nicht versandt: das Fenster bleibt offen.
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'quantity' => '1.0000']);
        $mixed = $this->reader()->of(self::ORDER);
        self::assertNotNull($mixed);
        self::assertSame(WindowState::Open, $mixed->state);
    }

    /**
     * Review Abo-4, S1 (A12): Eine Folgebestellung öffnet kein Widerrufsfenster.
     * Sie hat keine eigene Berechtigung (P27), also griff die Prüfung darüber
     * nicht — die gemeldete Lieferung öffnete 14 neue Tage. Gegenprobe: dieselbe
     * Lieferung an einem Kauf öffnet das Fenster.
     */
    public function testEineFolgebestellungOeffnetKeinFenster(): void
    {
        $this->deliver(1, '2026-10-01 10:00', '2026-10-01 10:00', '2');

        $purchase = $this->reader()->of(self::ORDER);
        self::assertNotNull($purchase);
        self::assertSame(WindowState::Open, $purchase->state);

        $this->connection->update('tl_consho_order', ['subscription' => 5], ['id' => self::ORDER]);

        $followUp = $this->reader()->of(self::ORDER);
        self::assertNotNull($followUp);
        self::assertSame(WindowState::Off, $followUp->state);
    }

    /**
     * Abo-8, P76: Bei einer Dienstleistung läuft die Frist ab Vertragsschluss
     * (§ 355 Abs. 2 BGB, § 11 Abs. 2 Z 1 FAGG), nicht ab einer Lieferung. Die
     * Freigabe (eine gemeldete Lieferung, F-4) schiebt sie nicht hinaus und
     * schließt sie nicht; vor der Freigabe ist sie nicht offen ohne Ende.
     */
    public function testDieDienstleistungZaehltAbKauf(): void
    {
        $this->connection->update('tl_consho_order', ['createdAt' => $this->at('2026-10-01 10:00')], ['id' => self::ORDER]);
        $this->connection->update('tl_consho_order_item', ['kind' => 'service'], ['id' => 1]);

        self::assertSame($this->at('2026-10-22 23:59:59'), $this->reader()->of(self::ORDER)?->closesAt, 'vor der Freigabe');

        $this->deliver(1, '2026-10-05 10:00', '2026-10-05 10:00', '2');
        $this->connection->insert('tl_consho_entitlement', ['pid' => self::ORDER, 'orderItem' => 1, 'quantity' => '2.0000']);

        $released = $this->reader()->of(self::ORDER);
        self::assertNotNull($released);
        self::assertSame(WindowState::Open, $released->state, 'die Freigabe schließt nicht');
        self::assertSame($this->at('2026-10-22 23:59:59'), $released->closesAt, 'und schiebt nicht hinaus');

        // Dazu ein Buch, noch nicht versandt: das Paket hält das Fenster offen.
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'quantity' => '1.0000']);
        self::assertNull($this->reader()->of(self::ORDER)?->closesAt);

        // Versandt am 10.10.: es gilt das spätere Ende, das des Pakets.
        $this->connection->insert('tl_consho_delivery', ['id' => 3, 'pid' => self::ORDER, 'shippedAt' => $this->at('2026-10-10 10:00'), 'reportedAt' => $this->at('2026-10-10 10:00')]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 3, 'orderItem' => 2, 'quantity' => '1']);
        self::assertSame($this->at('2026-10-31 23:59:59'), $this->reader()->of(self::ORDER)?->closesAt);
    }

    /**
     * DL3: „Leistung erbracht" mit Zustimmung schließt das Fenster, wenn
     * sonst nichts mehr widerrufbar ist; ohne Zustimmung bleibt es offen.
     * In einer gemischten Bestellung hält das Buch es offen.
     */
    public function testDieErbrachteLeistungSchliesstMitZustimmung(): void
    {
        $this->connection->update('tl_consho_order', ['createdAt' => $this->at('2026-10-01 10:00')], ['id' => self::ORDER]);
        $this->connection->update('tl_consho_order_item', ['kind' => 'service', 'performedAt' => $this->at('2026-10-06 10:00')], ['id' => 1]);
        $this->deliver(1, '2026-10-05 10:00', '2026-10-05 10:00', '2');
        $this->connection->insert('tl_consho_entitlement', ['pid' => self::ORDER, 'orderItem' => 1, 'quantity' => '2.0000']);

        self::assertSame(WindowState::Open, $this->stateOf(), 'ohne Zustimmung');

        $this->connection->update('tl_consho_order', ['digitalConsentAt' => $this->at('2026-10-01 10:00')], ['id' => self::ORDER]);
        self::assertSame(WindowState::Off, $this->stateOf(), 'mit Zustimmung');

        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'quantity' => '1.0000']);
        self::assertNotSame(WindowState::Off, $this->stateOf(), 'das Buch hält es offen');
    }

    public function testDieFristDesShops(): void
    {
        $this->connection->update('tl_consho_shop', ['withdrawalDays' => 30, 'withdrawalGraceDays' => 0], ['id' => 1]);
        $this->deliver(1, '2026-10-01 10:00', '2026-10-01 10:00', '2');

        self::assertSame($this->at('2026-10-31 23:59:59'), $this->reader()->of(self::ORDER)?->closesAt);
    }

    public function testStorniertOhneVersand(): void
    {
        $this->connection->update('tl_consho_order', ['cancelledAt' => 1], ['id' => self::ORDER]);

        self::assertSame(WindowState::Cancelled, $this->reader()->of(self::ORDER)?->state);
    }

    public function testUnbekannteBestellung(): void
    {
        self::assertNull($this->reader()->of(99));
    }

    private function deliver(int $id, string $shipped, string|null $reported, string $quantity): void
    {
        $this->connection->insert('tl_consho_delivery', ['id' => $id, 'pid' => self::ORDER, 'shippedAt' => $this->at($shipped), 'reportedAt' => null === $reported ? null : $this->at($reported)]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => $id, 'orderItem' => 1, 'quantity' => $quantity]);
    }

    private function stateOf(): WindowState
    {
        $window = $this->reader()->of(self::ORDER);
        self::assertNotNull($window);

        return $window->state;
    }

    private function reader(): WithdrawalWindowReader
    {
        return new WithdrawalWindowReader($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/Vienna')))->getTimestamp();
    }
}

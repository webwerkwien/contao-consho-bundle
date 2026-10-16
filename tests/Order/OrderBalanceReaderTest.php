<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalance;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Die zwei Salden (Spec D 5.1, Entscheidung A1). Die Fälle sind die Tabelle
 * aus der Spezifikation, Abschnitt 11.
 */
final class OrderBalanceReaderTest extends TestCase
{
    private const ORDER = 7;

    private const CANCELLED = 3;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        // From the DCA (acceptance WG-7, Prüfungsnotiz Punkt 56), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        $this->connection->insert('tl_consho_order_status', ['id' => self::CANCELLED, 'isCancelled' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => self::ORDER, 'grandTotal' => '100.0000']);

        // Zwei Waren und eine Versandzeile, die nie in ein Paket kommt.
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'quantity' => '2.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'quantity' => '1.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 3, 'pid' => self::ORDER, 'quantity' => '1.0000', 'lineType' => 'shipping']);
    }

    public function testBezahltUndGeliefertIstErledigt(): void
    {
        $this->pay('100.0000');
        $this->ship([1 => '2', 2 => '1']);

        $balance = $this->balance();

        self::assertSame(PaymentState::Paid, $balance->paymentState);
        self::assertSame(DeliveryState::Complete, $balance->deliveryState);
        self::assertTrue($balance->settled());
    }

    /**
     * A4 (Michael, 2026-10-07: „wie empfohlen"): „Leistung erbracht" gilt als
     * geliefert — sonst blieb sie in Liste und Dashboard für immer offen.
     * Kein Paket: anyShipped bleibt falsch.
     */
    public function testEineErbrachteLeistungGiltAlsGeliefert(): void
    {
        $this->connection->update('tl_consho_order_item', ['performedAt' => 500], ['id' => 1]);
        self::assertSame(DeliveryState::Partial, $this->balance()->deliveryState, 'Zeile 2 ist noch offen');

        $this->connection->update('tl_consho_order_item', ['performedAt' => 500], ['id' => 2]);
        $balance = $this->balance();

        self::assertSame(DeliveryState::Complete, $balance->deliveryState);
        self::assertSame([], $balance->openGoods);
        self::assertFalse($balance->anyShipped);
    }

    public function testDieVersandzeileWirdNieGeliefertUndHaeltNichtsOffen(): void
    {
        $this->ship([1 => '2', 2 => '1']);

        self::assertTrue($this->balance()->deliverySettled());
    }

    /**
     * Michael, 2026-09-27: „sie könnte aber abgeschlossen sein, wenn kein
     * versand aber stornierte zahlung vorliegt".
     */
    public function testStorniertNieVersandtGeldZurueckIstErledigt(): void
    {
        $this->pay('100.0000');
        $this->pay('-100.0000');
        $this->cancel();

        $balance = $this->balance();

        self::assertSame(PaymentState::Refunded, $balance->paymentState);
        self::assertTrue($balance->settled());
    }

    public function testStorniertNieBezahltSchuldetNichts(): void
    {
        $this->cancel();

        $balance = $this->balance();

        self::assertSame(PaymentState::NothingDue, $balance->paymentState);
        self::assertSame('0', $balance->claim);
        self::assertTrue($balance->settled());
    }

    public function testStorniertAberNochNichtErstattetIstNichtErledigt(): void
    {
        $this->pay('100.0000');
        $this->cancel();

        $balance = $this->balance();

        self::assertSame(PaymentState::Overpaid, $balance->paymentState);
        self::assertAmount('-100', $balance->outstanding());
        self::assertFalse($balance->paymentSettled());
    }

    public function testUeberzahltIstNichtErledigt(): void
    {
        $this->pay('120.0000');

        self::assertFalse($this->balance()->paymentSettled());
    }

    public function testTeilzahlungNenntDenRest(): void
    {
        $this->pay('20.0000');

        $balance = $this->balance();

        self::assertSame(PaymentState::Partial, $balance->paymentState);
        self::assertAmount('80', $balance->outstanding());
    }

    public function testTeillieferungNenntDieOffenenMengen(): void
    {
        $this->ship([1 => '1']);

        $balance = $this->balance();

        self::assertSame(DeliveryState::Partial, $balance->deliveryState);
        self::assertSame([1, 2], array_keys($balance->openGoods));
        self::assertAmount('1', $balance->openGoods[1]);
        self::assertAmount('1', $balance->openGoods[2]);
        self::assertTrue($balance->anyShipped);
    }

    public function testNurGemeldeteLieferungenZaehlen(): void
    {
        $this->ship([1 => '2', 2 => '1'], reported: false);

        $balance = $this->balance();

        self::assertSame(DeliveryState::Open, $balance->deliveryState);
        self::assertFalse($balance->anyShipped);
    }

    public function testEineUnbekannteBestellungHatKeinenSaldo(): void
    {
        $reader = new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator());

        self::assertNull($reader->of(999));
    }

    /**
     * Numerisch, nicht als Zeichenkette: MySQL liefert decimal(12,4) als
     * „80.0000", SQLite als „80" (Prüfungsnotiz, Fall 30).
     */
    private static function assertAmount(string $expected, string $actual): void
    {
        self::assertTrue(BigDecimal::of($expected)->isEqualTo($actual), \sprintf('%s erwartet, %s erhalten.', $expected, $actual));
    }

    private function balance(): OrderBalance
    {
        $reader = new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator());

        return $reader->of(self::ORDER) ?? self::fail('Die Bestellung fehlt.');
    }

    // ── Widerrufe (D-3, F7) ───────────────────────────────────────────────

    /**
     * Angenommen, noch nicht erstattet: Die Forderung sinkt um den Anteil am
     * Zeilenbrutto (lineTotal / Menge × angenommen), und die widerrufene,
     * nicht versandte Ware ist nicht mehr offen.
     */
    public function testEinAngenommenerWiderrufSenktForderungUndOffeneWare(): void
    {
        $this->prices();
        $this->withdraw('accepted', [1 => '1']);

        $balance = $this->balance();

        // 100 − 60/2 = 70
        self::assertTrue(BigDecimal::of('70')->isEqualTo($balance->claim));
        self::assertSame([1 => '1', 2 => '1'], array_map(static fn (string $q): string => (string) BigDecimal::of($q)->strippedOfTrailingZeros(), $balance->openGoods));
    }

    public function testNachDerErstattungZaehltDerGebuchteBetrag(): void
    {
        $this->prices();
        $this->withdraw('accepted', [1 => '1'], '35.0000');

        // 100 − 35 (30 Ware + 5 Versandanteil)
        self::assertTrue(BigDecimal::of('65')->isEqualTo($this->balance()->claim));
    }

    public function testEingegangenOderAbgelehntAendertNichts(): void
    {
        $this->prices();
        $this->withdraw('received', [1 => '2']);
        $this->withdraw('rejected', [1 => '2']);

        self::assertTrue(BigDecimal::of('100')->isEqualTo($this->balance()->claim));
        self::assertCount(2, $this->balance()->openGoods);
    }

    /**
     * Voll widerrufen vor dem Versand und erstattet: nichts geschuldet, nichts
     * offen — erledigt, ohne dass etwas versandt wurde.
     */
    public function testVollWiderrufenUndErstattetIstErledigt(): void
    {
        $this->prices();
        $this->pay('100.0000');
        $this->withdraw('accepted', [1 => '2', 2 => '1'], '100.0000');
        $this->pay('-100.0000');

        $balance = $this->balance();

        self::assertTrue($balance->settled());
        self::assertSame(DeliveryState::Complete, $balance->deliveryState);
    }

    /**
     * Zeilenbrutto: 2 Wanderstöcke zu 60, 1 Rucksack zu 35, Versand 5.
     */
    private function prices(): void
    {
        $this->connection->update('tl_consho_order_item', ['lineTotal' => '60.0000'], ['id' => 1]);
        $this->connection->update('tl_consho_order_item', ['lineTotal' => '35.0000'], ['id' => 2]);
        $this->connection->update('tl_consho_order_item', ['lineTotal' => '5.0000'], ['id' => 3]);
    }

    /**
     * @param array<int, string> $accepted order item id => accepted quantity
     */
    private function withdraw(string $state, array $accepted, string|null $refunded = null): void
    {
        $this->connection->insert('tl_consho_withdrawal', [
            'pid' => self::ORDER,
            'number' => 'W'.random_int(1, 1_000_000),
            'state' => $state,
            'refundAmount' => $refunded,
            'refundedAt' => null === $refunded ? null : 1_790_000_000,
        ]);
        $id = (int) $this->connection->lastInsertId();

        foreach ($accepted as $item => $quantity) {
            $this->connection->insert('tl_consho_withdrawal_item', ['pid' => $id, 'orderItem' => $item, 'acceptedQuantity' => $quantity]);
        }
    }

    private function pay(string $amount): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => $amount]);
    }

    private function cancel(): void
    {
        $this->connection->update('tl_consho_order', ['orderStatus' => self::CANCELLED], ['id' => self::ORDER]);
    }

    /**
     * @param array<int, string> $quantities
     */
    private function ship(array $quantities, bool $reported = true): void
    {
        $this->connection->insert('tl_consho_delivery', ['pid' => self::ORDER, 'reportedAt' => $reported ? 1_790_000_000 : null]);
        $delivery = (int) $this->connection->lastInsertId();

        foreach ($quantities as $item => $quantity) {
            $this->connection->insert('tl_consho_delivery_item', ['pid' => $delivery, 'orderItem' => $item, 'quantity' => $quantity]);
        }
    }
}

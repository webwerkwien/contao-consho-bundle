<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Voucher;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherBookingType;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherLedger;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherReturn;

/**
 * WG-6 (6.6, WG7, WG11, D1, D2): Geld zurück auf Gutscheine, Gutscheine
 * zurückgenommen.
 */
class VoucherReturnTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    private VoucherLedger $ledger;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_voucher', 'tl_consho_voucher_booking', 'tl_consho_payment', 'tl_consho_order'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->ledger = new VoucherLedger($this->connection);
        $this->connection->insert('tl_consho_order', ['id' => 77, 'language' => 'de']);

        // Zwei Gutscheine haben Bestellung 77 bezahlt: A 30, B 10.
        $this->voucher(1, 'AAAABBBBCCCCAAAA', '50');
        $this->voucher(2, 'AAAABBBBCCCCBBBB', '10');
        $this->ledger->book(1, '-30', VoucherBookingType::Redeem, self::NOW - 100, 77, 501);
        $this->ledger->book(2, '-10', VoucherBookingType::Redeem, self::NOW - 100, 77, 502);
    }

    /**
     * Aus den Bewegungen, nicht aus dem Verweis an der Zahlung — den leert
     * die Anonymisierung.
     */
    public function testWasEinGutscheinBezahltHatStehtInDenBewegungen(): void
    {
        self::assertSame([1 => '30.0000', 2 => '10.0000'], array_map('strval', $this->return()->paidBy(77)));
        self::assertTrue($this->return()->paidTotal(77)->isEqualTo(40));
    }

    /**
     * WG11: zurück in der Reihenfolge, in der sie zahlten, jeder bis zu
     * seinem Bezahlten — Zahlung an der Bestellung und Bewegung mit Verweis.
     */
    public function testErstattungGehtInReihenfolgeAufDieGutscheine(): void
    {
        $payments = $this->return()->refund(77, '35', self::NOW, 'WEB-00077-W1', 'de');

        self::assertCount(2, $payments);
        self::assertEquals([50, 5], $this->connection->fetchFirstColumn('SELECT balance FROM tl_consho_voucher ORDER BY id'));

        $rows = $this->connection->fetchAllAssociative('SELECT id, amount, reference, lockedAt FROM tl_consho_payment ORDER BY id');
        self::assertEquals([-30, -5], array_column($rows, 'amount'));
        self::assertSame(['voucher:1', 'voucher:2'], array_column($rows, 'reference'));

        $booking = $this->connection->fetchAssociative("SELECT type, amount, paymentId, orderId, note FROM tl_consho_voucher_booking WHERE type = 'refund' ORDER BY id");
        self::assertEquals(30, $booking['amount']);
        self::assertSame((int) $rows[0]['id'], (int) $booking['paymentId']);
        self::assertSame('WEB-00077-W1', $booking['note']);

        // Und was zurück ist, zählt nicht noch einmal.
        self::assertTrue($this->return()->paidTotal(77)->isEqualTo(5));
    }

    public function testNieMehrAlsDieGutscheineBezahltHaben(): void
    {
        $this->return()->refund(77, '100', self::NOW);

        self::assertTrue($this->return()->paidTotal(77)->isZero());
        self::assertEquals(-40, $this->connection->fetchOne('SELECT SUM(amount) FROM tl_consho_payment'));
    }

    /**
     * D1: ein abgelaufener Gutschein wird unbefristet, ein gültiger behält
     * sein Datum.
     */
    public function testAbgelaufenWirdUnbefristetGueltigBleibtGueltig(): void
    {
        $this->connection->update('tl_consho_voucher', ['validUntil' => self::NOW - 10 * 86_400], ['id' => 1]);
        $this->connection->update('tl_consho_voucher', ['validUntil' => self::NOW + 10 * 86_400], ['id' => 2]);

        $this->return()->refund(77, '40', self::NOW);

        self::assertNull($this->connection->fetchOne('SELECT validUntil FROM tl_consho_voucher WHERE id = 1'));
        self::assertSame(self::NOW + 10 * 86_400, (int) $this->connection->fetchOne('SELECT validUntil FROM tl_consho_voucher WHERE id = 2'));
    }

    /**
     * 6.6, D2: Storno — was die Gutscheine zahlten, geht zurück; was die
     * Bestellung kaufte, wird gesperrt, auch teilweise eingelöst.
     */
    public function testStornoZahltZurueckUndSperrtGekaufte(): void
    {
        $this->voucher(3, 'AAAABBBBCCCCCCCC', '20', orderId: 77);
        $this->voucher(4, 'AAAABBBBCCCCDDDD', '50', orderId: 77);
        $this->voucher(5, 'AAAABBBBCCCCEEEE', '50', orderId: 78);

        $this->return()->afterCancellation(77, self::NOW);

        self::assertTrue($this->return()->paidTotal(77)->isZero());
        self::assertSame(['0', '0', '1', '1', '0'], array_map('strval', $this->connection->fetchFirstColumn('SELECT blocked FROM tl_consho_voucher ORDER BY id')));
        self::assertSame('Gutschein …AAAA', $this->connection->fetchOne('SELECT method FROM tl_consho_payment ORDER BY id'));
    }

    /**
     * Review WG-7, P4: Storno nach einem Teilwiderruf bucht nur zurück, was
     * noch nicht zurück ist — 40 bezahlt, 15 schon erstattet, 25 beim Storno.
     */
    /**
     * Review WG-7, L2: der Storno liest unter der Sperre der Bestellung.
     */
    public function testStornoSperrtDieBestellung(): void
    {
        SqliteWithoutRowLocks::$locked = [];

        $this->return()->afterCancellation(77, self::NOW);

        self::assertStringContainsString('FROM tl_consho_order WHERE id = ? FOR UPDATE', $this->lockedStatements()[0] ?? '');
    }

    public function testStornoNachTeilwiderrufBuchtNurDenRest(): void
    {
        $this->return()->refund(77, '15', self::NOW - 50, 'WEB-00077-W1');

        $this->return()->afterCancellation(77, self::NOW);

        self::assertTrue($this->return()->paidTotal(77)->isZero());
        self::assertEquals([50, 10], $this->connection->fetchFirstColumn('SELECT balance FROM tl_consho_voucher ORDER BY id'));
        self::assertEquals(-40, $this->connection->fetchOne('SELECT SUM(amount) FROM tl_consho_payment'));
    }

    /**
     * WG7, Review S1 (Michael, 2026-10-06): zurückgenommen wird je Stück ein
     * NICHT eingelöster Gutschein; ein zweiter Widerruf nimmt den nächsten.
     */
    public function testWiderrufNimmtNurNichtEingeloesteZurueck(): void
    {
        $this->orderItem(7, 90, '3');
        $this->voucher(3, 'AAAABBBBCCCCCCCC', '20', orderId: 90, orderItem: 7);
        $this->voucher(4, 'AAAABBBBCCCCDDDD', '50', orderId: 90, orderItem: 7);
        $this->voucher(5, 'AAAABBBBCCCCEEEE', '50', orderId: 90, orderItem: 7);

        self::assertSame([], $this->return()->takeBack(90, 12, [7 => '1.0000'], self::NOW));
        self::assertSame([4], array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_voucher WHERE withdrawal = 12 AND blocked = 1')));

        self::assertSame([], $this->return()->takeBack(90, 13, [7 => '1'], self::NOW));
        self::assertSame(13, (int) $this->connection->fetchOne('SELECT withdrawal FROM tl_consho_voucher WHERE id = 5'));
    }

    /**
     * Ein eingelöster Gutschein geht nicht zurück: die Antwort nennt ihn und
     * die Bestellung, in der er bezahlt hat — und nichts ist gesperrt, auch
     * nicht der freie daneben.
     */
    public function testEinEingeloesterGutscheinGehtNichtZurueck(): void
    {
        $this->orderItem(7, 90, '2');
        $this->connection->insert('tl_consho_order', ['id' => 91, 'orderNumber' => 'WEB-00091']);
        $this->voucher(3, 'AAAABBBBCCCCCCCC', '50', orderId: 90, orderItem: 7);
        $this->voucher(4, 'AAAABBBBCCCCDDDD', '50', orderId: 90, orderItem: 7);
        $this->ledger->book(4, '-30', VoucherBookingType::Redeem, self::NOW - 10, 91);

        $refused = $this->return()->takeBack(90, 12, [7 => '2'], self::NOW);

        self::assertSame([['code' => '…DDDD', 'reason' => 'redeemed', 'orders' => 'WEB-00091']], $refused);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_voucher WHERE blocked = 1'));

        // Die Bestellung zurück — der Gutschein ist wieder voll und geht.
        $this->return()->refund(91, '30', self::NOW);
        self::assertSame([], $this->return()->takeBack(90, 12, [7 => '2'], self::NOW));
    }

    /**
     * Zweites Review, L-B und L-C: von Hand gesperrt oder von Hand geändert —
     * beides entscheidet der Betreiber, je mit eigenem Grund. Eine ganz
     * zurückgebuchte Einlösung steht nicht mehr im Weg.
     */
    public function testGesperrtUndVonHandGeaendertStehenImWeg(): void
    {
        $this->voucher(3, 'AAAABBBBCCCCCCCC', '50', orderId: 90, orderItem: 7);
        $this->voucher(4, 'AAAABBBBCCCCDDDD', '50', orderId: 90, orderItem: 8);
        $this->voucher(5, 'AAAABBBBCCCCEEEE', '50', orderId: 90, orderItem: 9);
        $this->connection->update('tl_consho_voucher', ['blocked' => 1], ['id' => 3]);
        $this->ledger->book(4, '-5', VoucherBookingType::Manual, self::NOW - 10);
        $this->connection->insert('tl_consho_order', ['id' => 92, 'orderNumber' => 'WEB-00092']);
        $this->ledger->book(5, '-20', VoucherBookingType::Redeem, self::NOW - 10, 92);
        $this->ledger->book(5, '20', VoucherBookingType::Refund, self::NOW - 5, 92);

        $refused = $this->return()->takeBack(90, 12, [7 => '1', 8 => '1', 9 => '1'], self::NOW);

        self::assertSame([['code' => '…CCCC', 'reason' => 'blocked', 'orders' => ''], ['code' => '…DDDD', 'reason' => 'changed', 'orders' => '']], $refused);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_voucher WHERE withdrawal > 0'));
    }

    /**
     * Zweites Review, L-A: die Gutscheine der Zeile unter Sperre, in
     * Id-Reihenfolge wie der Kaufklick.
     */
    public function testLiestDieGutscheineUnterSperre(): void
    {
        $this->voucher(3, 'AAAABBBBCCCCCCCC', '50', orderId: 90, orderItem: 7);
        SqliteWithoutRowLocks::$locked = [];

        $this->return()->takeBack(90, 12, [7 => '1'], self::NOW);

        self::assertNotEmpty(array_filter($this->lockedStatements(), static fn (string $sql): bool => str_contains($sql, 'FROM tl_consho_voucher') && str_contains($sql, 'ORDER BY id')));
    }

    /**
     * Read through a method: right after the reset PHPStan takes the static
     * for the empty array it was set to.
     *
     * @return list<string>
     */
    private function lockedStatements(): array
    {
        return SqliteWithoutRowLocks::$locked;
    }

    /**
     * Unbezahlt: noch kein Gutschein ausgegeben, nichts im Weg — die Freigabe
     * gibt ein widerrufenes Stück nie aus.
     */
    public function testNochNichtAusgegebeneStueckeBrauchenKeinenGutschein(): void
    {
        $this->orderItem(7, 90, '2');

        self::assertSame([], $this->return()->takeBack(90, 12, [7 => '2'], self::NOW));
    }

    /**
     * Review S1: auf einen gesperrten Gutschein geht nichts zurück — weder
     * beim Widerruf noch beim Storno. Sein Anteil bleibt der Zahlungsart.
     */
    public function testEinGesperrterGutscheinBekommtNichtsZurueck(): void
    {
        $this->connection->update('tl_consho_voucher', ['blocked' => 1], ['id' => 1]);

        self::assertTrue($this->return()->paidTotal(77)->isEqualTo(10));

        $this->return()->afterCancellation(77, self::NOW);

        self::assertEquals([20, 10], $this->connection->fetchFirstColumn('SELECT balance FROM tl_consho_voucher ORDER BY id'));
        self::assertEquals(-10, $this->connection->fetchOne('SELECT SUM(amount) FROM tl_consho_payment'));
    }

    private function orderItem(int $id, int $orderId, string $quantity): void
    {
        if (!$this->connection->createSchemaManager()->tablesExist(['tl_consho_order_item'])) {
            DcaSchema::create($this->connection, 'tl_consho_order_item');
        }

        $this->connection->insert('tl_consho_order_item', ['id' => $id, 'pid' => $orderId, 'kind' => 'voucher', 'quantity' => $quantity]);
    }

    private function voucher(int $id, string $code, string $balance, int $orderId = 0, int $orderItem = 0, string $paid = '0'): void
    {
        $this->connection->insert('tl_consho_voucher', [
            'id' => $id, 'code' => $code, 'shop' => 1, 'currency' => 'EUR', 'faceValue' => '50', 'paidAmount' => $paid,
            'balance' => $balance, 'source' => $orderId > 0 ? 'purchase' : 'manual', 'orderId' => $orderId, 'orderItem' => $orderItem,
        ]);
    }

    private function return(): VoucherReturn
    {
        return new VoucherReturn($this->connection, $this->ledger, new class implements \Symfony\Contracts\Translation\TranslatorInterface {
            public function trans(string $id, array $parameters = [], string|null $domain = null, string|null $locale = null): string
            {
                return 'de' === $locale && 'CONSHO.cart.voucher.applied' === $id ? 'Gutschein '.$parameters[0] : $id;
            }

            public function getLocale(): string
            {
                return 'de';
            }
        });
    }
}

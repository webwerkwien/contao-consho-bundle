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
 * WG-6 (6.6, WG7, WG11, D1, D2): Money back onto vouchers, vouchers
 * taken back.
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
     * From the movements, not from the reference on the payment — the
     * anonymisation clears that one.
     */
    public function testWasEinGutscheinBezahltHatStehtInDenBewegungen(): void
    {
        self::assertSame([1 => '30.0000', 2 => '10.0000'], array_map('strval', $this->return()->paidBy(77)));
        self::assertTrue($this->return()->paidTotal(77)->isEqualTo(40));
    }

    /**
     * WG11: back in the order in which they paid, each up to what it
     * paid — a payment on the order and a movement with a reference.
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

        // And what is back does not count a second time.
        self::assertTrue($this->return()->paidTotal(77)->isEqualTo(5));
    }

    /**
     * EZ-4: money back onto a single-purpose voucher takes its tax along —
     * its share of what still stands on the order, and on the last refund
     * all that is left, so no rounding cent remains. The correction is
     * issued before the refund is booked and reads the same plan.
     */
    public function testDieSteuerGehtAnteiligMitAufDenEinzweckGutscheinZurueck(): void
    {
        $this->connection->update('tl_consho_order', ['currency' => 'EUR'], ['id' => 77]);
        $this->connection->insert('tl_consho_payment', ['pid' => 77, 'amount' => '30.0000', 'method' => 'Gutschein …AAAA', 'reference' => 'voucher:1', 'prepaidTaxRate' => '20.0000', 'prepaidTaxCountry' => 'AT', 'prepaidTax' => '5.0000']);
        $this->connection->insert('tl_consho_payment', ['pid' => 77, 'amount' => '10.0000', 'method' => 'Gutschein …BBBB', 'reference' => 'voucher:2']);

        self::assertSame(
            [['label' => 'Gutschein …AAAA', 'country' => 'AT', 'rate' => '20.0000', 'net' => '-8.3300', 'tax' => '-1.6700', 'gross' => '-10.0000']],
            $this->return()->prepaidFor(77, '10', 'de'),
        );

        // 5 × 10 / 30 = 1.666… → 1.67
        $this->return()->refund(77, '10', self::NOW, 'WEB-00077-W1', 'de');
        // The rest of A, then B, which carries no tax.
        $this->return()->refund(77, '25', self::NOW, 'WEB-00077-W2', 'de');

        $rows = $this->connection->fetchAllAssociative('SELECT amount, prepaidTaxRate, prepaidTaxCountry, prepaidTax FROM tl_consho_payment WHERE amount < 0 ORDER BY id');
        self::assertEquals([[-10, 20, 'AT', -1.67], [-20, 20, 'AT', -3.33], [-5, 0, '', 0]], array_map('array_values', $rows));
        self::assertEquals(0, $this->connection->fetchOne("SELECT SUM(prepaidTax) FROM tl_consho_payment WHERE reference = 'voucher:1'"));
    }

    /**
     * On a half cent the share's net is rounded and its tax is the rest, as
     * the credited line is split: 11.25 of 22.50 at 20 % gives 9.38 net and
     * 1.87 tax, not 1.88 — else the credit note's rate keeps a cent of tax on
     * no basis and its e-invoice has no XML.
     */
    public function testDerSteueranteilRundetDasNettoWieDieZeile(): void
    {
        $this->connection->update('tl_consho_order', ['currency' => 'EUR'], ['id' => 77]);
        $this->connection->insert('tl_consho_payment', ['pid' => 77, 'amount' => '22.5000', 'method' => 'Gutschein …AAAA', 'reference' => 'voucher:1', 'prepaidTaxRate' => '20.0000', 'prepaidTaxCountry' => 'AT', 'prepaidTax' => '3.7500']);

        self::assertSame(
            [['label' => 'Gutschein …AAAA', 'country' => 'AT', 'rate' => '20.0000', 'net' => '-9.3800', 'tax' => '-1.8700', 'gross' => '-11.2500']],
            $this->return()->prepaidFor(77, '11.25', 'de'),
        );

        $this->return()->refund(77, '11.25', self::NOW, 'WEB-00077-W1', 'de');
        $this->return()->refund(77, '11.25', self::NOW, 'WEB-00077-W2', 'de');

        self::assertEquals([-1.87, -1.88], array_map('floatval', $this->connection->fetchFirstColumn('SELECT prepaidTax FROM tl_consho_payment WHERE amount < 0 ORDER BY id')));
    }

    /**
     * EZ-4 (F2): with the lines of a withdrawal each single-purpose voucher
     * takes back at most what its pot credits. A returned discount at the
     * same country and rate is shared among the pots by what they credit,
     * not taken whole from each: 30 − 8 × 30/40 = 24 and 10 − 8 × 10/40 = 8.
     */
    public function testJederEinzweckGutscheinNurAusSeinemTopf(): void
    {
        $this->connection->update('tl_consho_voucher', ['taxClass' => 3, 'taxRate' => '20.0000', 'taxCountry' => 'AT'], ['id' => 1]);
        $this->connection->update('tl_consho_voucher', ['taxClass' => 4, 'taxRate' => '20.0000', 'taxCountry' => 'AT'], ['id' => 2]);
        $line = static fn (string $total, string $type, string $class): array => ['lineTotal' => $total, 'lineType' => $type, 'taxClass' => $class, 'taxCountry' => 'AT', 'taxRate' => '20.0000', 'kind' => 'physical'];

        self::assertSame('32.0000', (string) $this->return()->onVouchers(77, '40', [$line('-30.0000', 'product', '3'), $line('-10.0000', 'product', '4'), $line('8.0000', 'discount', '0')]));
        // Only class 4 withdrawn: voucher 1 takes nothing, voucher 2 its 10.
        self::assertSame('10.0000', (string) $this->return()->onVouchers(77, '40', [$line('-30.0000', 'product', '4')]));
        // Without lines (a cancellation) all of it.
        self::assertSame('40.0000', (string) $this->return()->onVouchers(77, '40'));
    }

    public function testNieMehrAlsDieGutscheineBezahltHaben(): void
    {
        $this->return()->refund(77, '100', self::NOW);

        self::assertTrue($this->return()->paidTotal(77)->isZero());
        self::assertEquals(-40, $this->connection->fetchOne('SELECT SUM(amount) FROM tl_consho_payment'));
    }

    /**
     * D1: an expired voucher becomes unlimited, a valid one keeps
     * its date.
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
     * 6.6, D2: Cancellation — what the vouchers paid goes back; what the
     * order bought is locked, even if partly redeemed.
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
     * WG-7: A cancellation after a partial withdrawal only books back what
     * is not back yet — 40 paid, 15 already refunded, 25 at the cancellation.
     */
    /**
     * WG-7: the cancellation reads under the order's lock.
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
     * WG7: per item, one voucher that has NOT been redeemed is
     * taken back; a second withdrawal takes the next one.
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
     * A redeemed voucher does not go back: the answer names it and the
     * order it paid in — and nothing is locked, not even the free one
     * next to it.
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

        // The order taken back — the voucher is full again and goes.
        $this->return()->refund(91, '30', self::NOW);
        self::assertSame([], $this->return()->takeBack(90, 12, [7 => '2'], self::NOW));
    }

    /**
     * Locked by hand or changed by hand — the operator decides both, each
     * with its own reason. A redemption booked back in full no longer stands
     * in the way.
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
     * The row's vouchers under a lock, in id order like the purchase click.
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
     * Unpaid: no voucher issued yet, nothing in the way — the release never
     * issues a withdrawn item.
     */
    public function testNochNichtAusgegebeneStueckeBrauchenKeinenGutschein(): void
    {
        $this->orderItem(7, 90, '2');

        self::assertSame([], $this->return()->takeBack(90, 12, [7 => '2'], self::NOW));
    }

    /**
     * Nothing goes back onto a locked voucher — neither at a withdrawal nor
     * at a cancellation. Its share stays with the payment method.
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

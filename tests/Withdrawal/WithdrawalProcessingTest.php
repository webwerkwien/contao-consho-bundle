<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Withdrawal;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeNotConfigured;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OrderEvent;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Stock\StockReturn;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquency;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPurchaseCancellation;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Withdrawal\ValueCompensation;
use Webwerkwien\ContaoConshoBundle\Digital\EntitlementRevocations;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalProcessing;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherBookingType;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherLedger;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherReturn;

/**
 * Processing a withdrawal (D-3, F1–F10). Real balances on SQLite;
 * documents, mail and status are spies — what a document contains is
 * checked by DocumentIssuerTest.
 *
 * Order: 2 × hiking pole (`Wanderstock`, 60.00), 1 × backpack (`Rucksack`,
 * 35.00), shipping 5.00 — together 100.00, paid.
 */
final class WithdrawalProcessingTest extends TestCase
{
    private const ORDER = 7;
    private const WITHDRAWAL = 1;
    private const NOW = 1_790_000_000;

    private Connection $connection;

    /** @var list<array{0: string, 1: mixed, 2?: mixed}> */
    private array $documentCalls = [];

    /** @var list<array{string, array<int, mixed>}> */
    private array $mails = [];

    /** @var list<OrderEvent> */
    private array $events = [];

    private int|null $invoice = 14;

    private bool $rangeMissing = false;

    /** @var list<array{int, list<int>, int}> */
    private array $ended = [];

    protected function setUp(): void
    {
        // Without row locks: VoucherLedger locks the voucher with FOR UPDATE (WG-6).
        $this->connection = \Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks::connection();
        WithdrawalSchema::create($this->connection);
        // The real DCA schema: the subscription's delinquency check reads more of the order (Abo-8).
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        // From the DCA (WG-7), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        $this->connection->insert('tl_consho_order', ['id' => self::ORDER, 'shop' => 1, 'orderNumber' => 'WEB-00077', 'currency' => 'EUR', 'language' => 'de', 'grandTotal' => '100.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'productName' => 'Wanderstock', 'quantity' => '2.0000', 'unitPrice' => '25.0000', 'taxRate' => '20.0000', 'taxAmount' => '10.0000', 'lineTotal' => '60.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'productName' => 'Rucksack', 'quantity' => '1.0000', 'unitPrice' => '29.1667', 'taxRate' => '20.0000', 'taxAmount' => '5.8333', 'lineTotal' => '35.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 3, 'pid' => self::ORDER, 'productName' => 'Paket', 'quantity' => '1.0000', 'unitPrice' => '4.1667', 'taxRate' => '20.0000', 'taxAmount' => '0.8333', 'lineTotal' => '5.0000', 'lineType' => 'shipping']);
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '100.0000']);

        // Declared: 1 hiking pole.
        $this->connection->insert('tl_consho_withdrawal', ['id' => self::WITHDRAWAL, 'pid' => self::ORDER, 'number' => 'WEB-00077-W1', 'scope' => 'items']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => self::WITHDRAWAL, 'orderItem' => 1, 'quantity' => '1']);
    }

    // ── Accept ────────────────────────────────────────────────────────────

    public function testAnnehmenMitDenErklaertenMengen(): void
    {
        $result = $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        self::assertTrue($result->done);
        self::assertSame('accepted', $this->withdrawalRow()['state']);
        self::assertSame(self::NOW, (int) $this->withdrawalRow()['decidedAt']);

        // The declaration stays; next to it stands what has been accepted.
        $item = $this->connection->fetchAssociative('SELECT quantity, acceptedQuantity, declared FROM tl_consho_withdrawal_item');
        self::assertIsArray($item);
        self::assertTrue(BigDecimal::of('1')->isEqualTo((string) $item['quantity']));
        self::assertTrue(BigDecimal::of('1')->isEqualTo((string) $item['acceptedQuantity']));

        // F1: partial withdrawal → no shipping share proposed.
        self::assertTrue(BigDecimal::zero()->isEqualTo((string) $this->withdrawalRow()['refundShipping']));
        // F7: The amount due drops by 30.
        self::assertTrue(BigDecimal::of('70')->isEqualTo($this->balance()->claim));
    }

    public function testDieGanzeBestellungSchlaegtDenVersandVor(): void
    {
        $this->connection->delete('tl_consho_withdrawal_item', ['pid' => self::WITHDRAWAL]);
        $this->connection->update('tl_consho_withdrawal', ['scope' => 'all'], ['id' => self::WITHDRAWAL]);

        self::assertTrue($this->processing()->accept(self::WITHDRAWAL, [1 => '2', 2 => '1'], self::NOW)->done);

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal_item WHERE declared = 0'));
        self::assertTrue(BigDecimal::of('5')->isEqualTo((string) $this->withdrawalRow()['refundShipping']));
    }

    /**
     * @return iterable<string, array{array<int, string>, string}>
     */
    public static function falscheMengen(): iterable
    {
        yield 'mehr als bestellt' => [[1 => '3'], 'tooMuch'];
        yield 'Versandzeile' => [[3 => '1'], 'invalidQuantity'];
        yield 'negativ' => [[1 => '-1'], 'invalidQuantity'];
        yield 'keine Zahl' => [[1 => 'x'], 'invalidQuantity'];
        yield 'nichts' => [[1 => '0'], 'nothingAccepted'];
    }

    /**
     * @param array<int, string> $quantities
     *
     * @dataProvider falscheMengen
     */
    public function testFalscheMengenAendernNichts(array $quantities, string $key): void
    {
        $result = $this->processing()->accept(self::WITHDRAWAL, $quantities, self::NOW);

        self::assertFalse($result->done);
        self::assertSame($key, $result->key);
        self::assertSame('received', $this->withdrawalRow()['state']);
    }

    /**
     * Abo-8, P77: A subscription line is only accepted as a whole — a part
     * would be a quantity change on the running subscription, and there is no
     * such thing (A11).
     */
    public function testEineAboZeileNurGanz(): void
    {
        $this->connection->update('tl_consho_order_item', ['subscriptionRule' => 3], ['id' => 1]);

        $part = $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);
        self::assertSame('wholeOnly', $part->key);
        self::assertSame('received', $this->withdrawalRow()['state']);

        self::assertSame([], $this->ended, 'abgelehnt: kein Abo beendet');

        self::assertTrue($this->processing()->accept(self::WITHDRAWAL, [1 => '2'], self::NOW)->done);
        // P78: the accepted lines go to ending the subscriptions.
        self::assertSame([[self::ORDER, [1], self::NOW]], $this->ended);
    }

    public function testEinAndererWiderrufZaehltMit(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['id' => 2, 'pid' => self::ORDER, 'number' => 'WEB-00077-W2', 'state' => 'accepted']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 2, 'orderItem' => 1, 'acceptedQuantity' => '2']);

        self::assertSame('tooMuch', $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW)->key);
    }

    // ── Reject (F6) ───────────────────────────────────────────────────────

    public function testAblehnenBrauchtEineBegruendungUndSchicktSieWoertlich(): void
    {
        self::assertSame('reasonMissing', $this->processing()->reject(self::WITHDRAWAL, '  ', self::NOW)->key);
        self::assertSame('received', $this->withdrawalRow()['state']);

        self::assertTrue($this->processing()->reject(self::WITHDRAWAL, 'Frist abgelaufen.', self::NOW)->done);
        self::assertSame('rejected', $this->withdrawalRow()['state']);
        self::assertSame('Frist abgelaufen.', $this->withdrawalRow()['reason']);
        self::assertSame(['withdrawalRejected', [self::ORDER, 'WEB-00077-W1', 'Frist abgelaufen.', 'https://shop.test/x']], $this->mails[0]);
    }

    public function testEinEntschiedenerWiderrufWirdNichtNochmalEntschieden(): void
    {
        $this->processing()->reject(self::WITHDRAWAL, 'Nein.', self::NOW);

        self::assertSame('alreadyDecided', $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW)->key);
    }

    // ── Return shipment (F8) ──────────────────────────────────────────────

    public function testRuecksendungHoechstensDasAngenommene(): void
    {
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);
        $row = (int) $this->connection->fetchOne('SELECT id FROM tl_consho_withdrawal_item');

        self::assertSame('invalidQuantity', $this->processing()->recordReturn(self::WITHDRAWAL, self::NOW, [$row => '2'], self::NOW)->key);
        self::assertTrue($this->processing()->recordReturn(self::WITHDRAWAL, self::NOW, [$row => '1'], self::NOW)->done);
        self::assertSame(self::NOW, (int) $this->withdrawalRow()['returnReceivedAt']);

        // An empty date does not delete the one recorded.
        self::assertTrue($this->processing()->recordReturn(self::WITHDRAWAL, 0, [$row => '1'], self::NOW)->done);
        self::assertSame(self::NOW, (int) $this->withdrawalRow()['returnReceivedAt']);
    }

    /**
     * Block G (G9): every item of the return goes to the restocking — with the
     * tick from the form, otherwise with the last choice.
     */
    public function testDieRuecksendungGehtMitHaekchenAnDenBestand(): void
    {
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);
        $row = (int) $this->connection->fetchOne('SELECT id FROM tl_consho_withdrawal_item');

        $calls = [];
        $stock = $this->createMock(StockReturn::class);
        $stock->expects(self::exactly(2))->method('forWithdrawalItem')->willReturnCallback(static function (int $id, bool $restock, int $now, int $user) use (&$calls): string {
            $calls[] = [$id, $restock, $user];

            return '0.0000';
        });

        self::assertTrue($this->processing(stock: $stock)->recordReturn(self::WITHDRAWAL, self::NOW, [$row => '1'], self::NOW, [$row => false], 5)->done);
        self::assertTrue($this->processing(stock: $stock)->recordReturn(self::WITHDRAWAL, self::NOW, [$row => '1'], self::NOW)->done);

        // The second one without a value takes the stored choice — here the
        // default, because the double stores nothing.
        self::assertSame([[$row, false, 5], [$row, true, 0]], $calls);
    }

    /**
     * G: An accepted withdrawal before delivery releases keys — the product
     * page has to learn about it.
     */
    public function testAnnehmenMeldetFreigewordeneSchluessel(): void
    {
        $stock = $this->createMock(StockReturn::class);
        $stock->expects(self::once())->method('keysFreed')->with(self::ORDER);

        self::assertTrue($this->processing(stock: $stock)->accept(self::WITHDRAWAL, [1 => '1'], self::NOW)->done);
    }

    // ── Refund (F3, F4) ───────────────────────────────────────────────────

    /**
     * DL4: The correction carries the tax country of its lines and so groups
     * by country and rate like the invoice it corrects — checked on the end
     * state, the summary of the real builder.
     */
    public function testDieKorrekturTraegtDasSteuerlandIhrerZeilen(): void
    {
        $this->connection->update('tl_consho_order_item', ['taxCountry' => 'FR'], ['id' => 1]);
        $this->connection->update('tl_consho_order_item', ['taxCountry' => 'AT'], ['id' => 2]);

        $this->processing()->accept(self::WITHDRAWAL, [1 => '1', 2 => '1'], self::NOW);
        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '0', self::NOW)->done);

        self::assertSame('correct', $this->documentCalls[0][0]);
        $lines = $this->documentCalls[0][1];
        self::assertSame(['FR', 'AT'], array_column($lines, 'taxCountry'));
        self::assertSame(['AT:20.0000', 'FR:20.0000'], array_keys((new DocumentPayloadBuilder())->summariseTax($lines, 'EUR')));
    }

    // ── Vouchers (WG-6) ───────────────────────────────────────────────────

    /**
     * WG11: Paid with 40 voucher and 60 card — the refund of 30 goes entirely
     * to the voucher (the same means of payment first).
     */
    public function testErstattungGehtZuerstAufDenGutschein(): void
    {
        $return = $this->gutscheinBezahlt();
        $this->processing(vouchers: $return)->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        self::assertTrue($this->processing(vouchers: $return)->refund(self::WITHDRAWAL, '0', self::NOW)->done);

        $back = $this->connection->fetchAllAssociative('SELECT amount, reference FROM tl_consho_payment WHERE amount < 0 ORDER BY id');
        self::assertCount(1, $back);
        self::assertEquals(-30, $back[0]['amount']);
        self::assertSame('voucher:1', $back[0]['reference']);
        self::assertEquals(40, $this->connection->fetchOne('SELECT balance FROM tl_consho_voucher WHERE id = 1'));

        // The refund points to the voucher payment; the mail says what went back.
        self::assertSame((int) $this->connection->fetchOne('SELECT id FROM tl_consho_payment WHERE amount < 0'), (int) $this->withdrawalRow()['refundPayment']);
        self::assertSame('30,00 €', str_replace("\u{a0}", ' ', (string) $this->mails[0][1][9]));
        self::assertTrue($this->balance()->paymentSettled());
    }

    /**
     * EZ-4: back onto a single-purpose voucher, its tax goes along — on the
     * payment, and on the correction as the deduction it takes back, issued
     * before the payment is booked and stating the same figures.
     */
    public function testAufDenEinzweckGutscheinGehtDieSteuerMitZurueck(): void
    {
        $return = $this->einzweckGutscheinBezahlt();
        $this->processing(vouchers: $return)->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        self::assertTrue($this->processing(vouchers: $return)->refund(self::WITHDRAWAL, '0', self::NOW)->done);

        // 6.67 × 30 / 40 = 5.0025 → 5.00
        self::assertEquals([-30, 20, 'AT', -5], array_values((array) $this->connection->fetchAssociative('SELECT amount, prepaidTaxRate, prepaidTaxCountry, prepaidTax FROM tl_consho_payment WHERE amount < 0')));

        $corrections = array_values(array_filter($this->documentCalls, static fn (array $call): bool => 'correct' === $call[0]));
        self::assertSame(
            [['label' => 'Gift voucher …AAAA', 'country' => 'AT', 'rate' => '20.0000', 'net' => '-25.0000', 'tax' => '-5.0000', 'gross' => '-30.0000']],
            $corrections[0][2],
        );
    }

    /**
     * EZ-4 (F2): a single-purpose voucher takes back only what the withdrawn
     * articles of its pot give back. Withdrawn is the rucksack of another tax
     * class: everything goes to the payment method, the voucher keeps what it
     * paid, and the correction takes back no deduction.
     */
    public function testEinFremderArtikelGehtNichtAufDenEinzweckGutschein(): void
    {
        $return = $this->einzweckGutscheinBezahlt();
        $this->connection->update('tl_consho_order_item', ['taxClass' => 4], ['id' => 2]);
        $this->processing(vouchers: $return)->accept(self::WITHDRAWAL, [2 => '1'], self::NOW);

        self::assertTrue($this->processing(vouchers: $return)->refund(self::WITHDRAWAL, '0', self::NOW)->done);

        $back = $this->connection->fetchAllAssociative('SELECT amount, reference FROM tl_consho_payment WHERE amount < 0 ORDER BY id');
        self::assertEquals([[-35, 'WEB-00077-W1']], array_map('array_values', $back));
        self::assertEquals(10, $this->connection->fetchOne('SELECT balance FROM tl_consho_voucher WHERE id = 1'));

        $corrections = array_values(array_filter($this->documentCalls, static fn (array $call): bool => 'correct' === $call[0]));
        self::assertSame([], $corrections[0][2]);
    }

    /**
     * EZ-4: the page proposes onto the vouchers only what they can take back,
     * and a share typed above it is refused naming that most — not cut down
     * without a word.
     */
    public function testMehrAlsDerTopfHergibtWirdAbgelehnt(): void
    {
        $return = $this->einzweckGutscheinBezahlt();
        $this->connection->update('tl_consho_order_item', ['taxClass' => 4], ['id' => 2]);
        $this->processing(vouchers: $return)->accept(self::WITHDRAWAL, [1 => '1', 2 => '1'], self::NOW);

        // The hiking pole (30) is in the voucher's pot, the rucksack is not.
        self::assertSame('30.0000', $this->processing(vouchers: $return)->proposal(self::WITHDRAWAL)['vouchers']);

        $result = $this->processing(vouchers: $return)->refund(self::WITHDRAWAL, '0', self::NOW, null, '40');
        self::assertFalse($result->done);
        self::assertSame(['invalidVoucherShare', ['30.00']], [$result->key, $result->params]);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_payment WHERE amount < 0'));
    }

    /**
     * Voucher 1 as a single-purpose voucher of class 3, AT, 20 % — the class
     * of the hiking poles; 40 × 20 / 120 = 6.67 already taxed.
     */
    private function einzweckGutscheinBezahlt(): VoucherReturn
    {
        $return = $this->gutscheinBezahlt();
        $this->connection->update('tl_consho_voucher', ['taxClass' => 3, 'taxRate' => '20.0000', 'taxCountry' => 'AT'], ['id' => 1]);
        $this->connection->update('tl_consho_payment', ['prepaidTaxRate' => '20.0000', 'prepaidTaxCountry' => 'AT', 'prepaidTax' => '6.6700'], ['reference' => 'voucher:1']);
        $this->connection->update('tl_consho_order_item', ['taxClass' => 3, 'taxCountry' => 'AT'], ['pid' => self::ORDER]);

        return $return;
    }

    /**
     * WG11: the operator changes the split — 10 to the voucher, 20 to the
     * payment method.
     */
    public function testDieAufteilungLaesstSichAendern(): void
    {
        $return = $this->gutscheinBezahlt();
        $this->processing(vouchers: $return)->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        self::assertTrue($this->processing(vouchers: $return)->refund(self::WITHDRAWAL, '0', self::NOW, null, '10')->done);

        self::assertEquals([-10, -20], $this->connection->fetchFirstColumn('SELECT amount FROM tl_consho_payment WHERE amount < 0 ORDER BY id'));
        self::assertSame(['voucher:1', 'WEB-00077-W1'], $this->connection->fetchFirstColumn('SELECT reference FROM tl_consho_payment WHERE amount < 0 ORDER BY id'));
    }

    /**
     * More than the voucher paid cannot go to it — and rejected means:
     * nothing booked.
     */
    public function testMehrAlsBezahltGehtNichtAufDenGutschein(): void
    {
        $return = $this->gutscheinBezahlt();
        $this->processing(vouchers: $return)->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        $result = $this->processing(vouchers: $return)->refund(self::WITHDRAWAL, '0', self::NOW, null, '41');

        self::assertFalse($result->done);
        self::assertSame('invalidVoucherShare', $result->key);
        self::assertSame(['40.00'], $result->params);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_payment WHERE amount < 0'));
    }

    /**
     * WG7: a redeemed voucher — here one of two — holds up the acceptance;
     * nothing is booked, the message names it.
     */
    public function testWiderrufEinesEingeloestenGutscheinsWirdAbgelehnt(): void
    {
        $return = $this->gutscheinBezahlt();
        $this->connection->insert('tl_consho_order_item', ['id' => 4, 'pid' => self::ORDER, 'productName' => 'Gutschein 50', 'kind' => 'voucher', 'quantity' => '2.0000', 'unitPrice' => '50.0000', 'taxRate' => '0.0000', 'taxAmount' => '0.0000', 'lineTotal' => '100.0000']);
        $this->connection->update('tl_consho_order', ['grandTotal' => '200.0000'], ['id' => self::ORDER]);
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '100.0000']);
        foreach ([[2, 'GGGGHHHHJJJJKKKK', '20'], [3, 'GGGGHHHHJJJJMMMM', '50']] as [$id, $code, $balance]) {
            $this->connection->insert('tl_consho_voucher', ['id' => $id, 'code' => $code, 'shop' => 1, 'currency' => 'EUR', 'faceValue' => '50', 'paidAmount' => '50', 'balance' => $balance, 'source' => 'purchase', 'orderId' => self::ORDER, 'orderItem' => 4]);
        }

        // Voucher 2 has paid 30 in another order.
        $this->connection->insert('tl_consho_order', ['id' => 78, 'orderNumber' => 'WEB-00078']);
        $this->connection->update('tl_consho_voucher', ['balance' => '50'], ['id' => 2]);
        (new VoucherLedger($this->connection))->book(2, '-30', VoucherBookingType::Redeem, self::NOW - 50, 78);

        $result = $this->processing(vouchers: $return)->accept(self::WITHDRAWAL, [4 => '2'], self::NOW);

        self::assertFalse($result->done);
        self::assertSame('voucherRedeemed', $result->key);
        self::assertSame(['…KKKK (WEB-00078)'], $result->params);
        self::assertSame(['0', '0'], array_map('strval', $this->connection->fetchFirstColumn('SELECT blocked FROM tl_consho_voucher WHERE id IN (2, 3) ORDER BY id')));
        self::assertSame('received', $this->withdrawalRow()['state']);

        // Only the unredeemed piece: accepted, blocked, fully refunded.
        self::assertTrue($this->processing(vouchers: $return)->accept(self::WITHDRAWAL, [4 => '1'], self::NOW)->done);
        self::assertSame(['0', '1'], array_map('strval', $this->connection->fetchFirstColumn('SELECT blocked FROM tl_consho_voucher WHERE id IN (2, 3) ORDER BY id')));
        self::assertTrue(BigDecimal::zero()->isEqualTo((string) $this->withdrawalRow()['valueCompensation']));

        self::assertTrue($this->processing(vouchers: $return)->refund(self::WITHDRAWAL, '0', self::NOW, null, '0')->done);
        self::assertTrue(BigDecimal::of('50')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
    }

    /**
     * A voucher blocked by hand holds up the acceptance with a message of its
     * own — the operator has to decide.
     */
    public function testEinVonHandGesperrterGutscheinHatEigeneMeldung(): void
    {
        $return = $this->gutscheinBezahlt();
        $this->connection->insert('tl_consho_order_item', ['id' => 4, 'pid' => self::ORDER, 'productName' => 'Gutschein 50', 'kind' => 'voucher', 'quantity' => '1.0000', 'unitPrice' => '50.0000', 'taxRate' => '0.0000', 'taxAmount' => '0.0000', 'lineTotal' => '50.0000']);
        $this->connection->insert('tl_consho_voucher', ['id' => 2, 'code' => 'GGGGHHHHJJJJKKKK', 'shop' => 1, 'currency' => 'EUR', 'faceValue' => '50', 'paidAmount' => '50', 'balance' => '50', 'blocked' => 1, 'source' => 'purchase', 'orderId' => self::ORDER, 'orderItem' => 4]);

        $result = $this->processing(vouchers: $return)->accept(self::WITHDRAWAL, [4 => '1'], self::NOW);

        self::assertFalse($result->done);
        self::assertSame('voucherBlocked', $result->key);
        self::assertSame(['…KKKK'], $result->params);
    }

    /**
     * Voucher 1 has paid 40 of the order, the card 60.
     */
    private function gutscheinBezahlt(): VoucherReturn
    {
        DcaSchema::create($this->connection, 'tl_consho_voucher');
        DcaSchema::create($this->connection, 'tl_consho_voucher_booking');

        $this->connection->update('tl_consho_payment', ['amount' => '60.0000'], ['pid' => self::ORDER]);
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '40.0000', 'method' => 'Gutschein …AAAA', 'reference' => 'voucher:1']);
        $this->connection->insert('tl_consho_voucher', ['id' => 1, 'code' => 'AAAABBBBCCCCAAAA', 'shop' => 1, 'currency' => 'EUR', 'faceValue' => '50', 'balance' => '50']);

        $ledger = new VoucherLedger($this->connection);
        $ledger->book(1, '-40', VoucherBookingType::Redeem, self::NOW - 100, self::ORDER, 2);

        return new VoucherReturn($this->connection, $ledger);
    }

    public function testTeilwiderrufWirdKorrigiertUndErstattet(): void
    {
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        $result = $this->processing()->refund(self::WITHDRAWAL, '0', self::NOW);

        self::assertTrue($result->done);
        self::assertSame('correct', $this->documentCalls[0][0]);
        self::assertSame('-30.0000', $this->documentCalls[0][1][0]['lineTotal']);
        // Unit price net as on the invoice, quantity positive (the cancellation's rule).
        self::assertSame('-25.0000', $this->documentCalls[0][1][0]['unitPrice']);
        self::assertSame('1', $this->documentCalls[0][1][0]['quantity']);
        self::assertSame('-5.0000', $this->documentCalls[0][1][0]['taxAmount']);

        $payment = $this->connection->fetchAssociative('SELECT amount, method, reference, lockedAt FROM tl_consho_payment WHERE amount < 0');
        self::assertIsArray($payment);
        self::assertTrue(BigDecimal::of('-30')->isEqualTo((string) $payment['amount']));
        self::assertSame('WEB-00077-W1', $payment['reference']);
        self::assertNotNull($payment['lockedAt'], 'eine Erstattung ist sofort gesperrt');

        self::assertTrue(BigDecimal::of('30')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
        self::assertSame(55, (int) $this->withdrawalRow()['refundDocument']);

        // Amount due 70, paid 70.
        $balance = $this->balance();
        self::assertTrue(BigDecimal::of('70')->isEqualTo($balance->claim));
        self::assertTrue($balance->paymentSettled());

        self::assertSame([OrderEvent::WithdrawalRefunded], $this->events);
        self::assertSame('refundBooked', $this->mails[0][0]);
        self::assertSame('30,00 €', str_replace("\u{a0}", ' ', (string) $this->mails[0][1][2]));
        self::assertSame([55], $this->mails[0][1][4]);
    }

    /**
     * Abo-8, P79: The backpack here is a service subscription (€ 35.00, period
     * 35 days), released with consent, 7 days begun and used — proposed
     * € 7.00 compensation for value. Until the refund they stay open, then
     * the invoice is corrected instead of cancelled, even if everything is
     * withdrawn.
     */
    public function testWertersatzWirdVorgeschlagenUndEinbehalten(): void
    {
        $this->serviceSubscription();
        $this->processing()->accept(self::WITHDRAWAL, [1 => '2', 2 => '1'], self::NOW);

        self::assertTrue(BigDecimal::of('7')->isEqualTo((string) $this->withdrawalRow()['valueCompensation']));
        // Amount due 100 − 60 − (35 − 7) = 12: the compensation for value stays owed.
        self::assertTrue(BigDecimal::of('12')->isEqualTo($this->balance()->claim));

        // The acceptance ended it (P78; a stub here) — the mail reads the state.
        $this->connection->update('tl_consho_subscription', ['state' => 'ended', 'endsAt' => self::NOW], ['orderItem' => 2]);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '5', self::NOW)->done);

        // P81: the mail names the compensation for value and the ended subscription.
        self::assertSame('7,00 €', str_replace("\u{a0}", ' ', (string) $this->mails[0][1][7]));
        self::assertSame(['WEB-00077-1'], $this->mails[0][1][8]);

        self::assertSame('correct', $this->documentCalls[0][0], 'kein Storno: 7,00 bleiben');
        self::assertSame('-28.0000', $this->documentCalls[0][1][1]['lineTotal']);
        self::assertSame('-4.6667', $this->documentCalls[0][1][1]['taxAmount']);
        self::assertTrue(BigDecimal::of('93')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
        self::assertTrue(BigDecimal::of('-93')->isEqualTo((string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE amount < 0')));
        self::assertTrue($this->balance()->settled(), 'Forderung 7, bezahlt 7');
    }

    /**
     * Abo-8: Acceptance and refund with the real building block instead of
     * the stub — a doubled block can have side effects that differ in
     * combination. The proposal comes about before the ending, the
     * subscription ends at the time of acceptance, the entitlement is blocked,
     * and the mail names exactly this subscription.
     */
    public function testAnnahmeUndErstattungMitDemEchtenAboBaustein(): void
    {
        $this->serviceSubscription();

        self::assertTrue($this->processing(true)->accept(self::WITHDRAWAL, [2 => '1'], self::NOW)->done);

        self::assertTrue(BigDecimal::of('7')->isEqualTo((string) $this->withdrawalRow()['valueCompensation']));
        $subscription = $this->connection->fetchAssociative("SELECT state, endsAt FROM tl_consho_subscription WHERE orderItem = 2");
        self::assertSame(['ended', self::NOW], [(string) ($subscription['state'] ?? ''), (int) ($subscription['endsAt'] ?? 0)]);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT revoked FROM tl_consho_entitlement WHERE orderItem = 2'));

        self::assertTrue($this->processing(true)->refund(self::WITHDRAWAL, '0', self::NOW + 60)->done);
        self::assertSame(['WEB-00077-1'], $this->mails[0][1][8]);
    }

    /**
     * DL9: A one-off service, withdrawn entirely, is blocked — without a
     * subscription it could end. Consho proposes no compensation for value:
     * without a term there are no days to count (reason noPeriod). Withdrawn
     * in part, the entitlement stays; goods have none.
     */
    public function testEineEinmaligeDienstleistungWirdGesperrt(): void
    {
        $this->serviceSubscription();
        $this->connection->update('tl_consho_order_item', ['subscriptionRule' => 0], ['id' => 2]);
        $this->connection->delete('tl_consho_subscription', ['orderItem' => 2]);
        $this->connection->update('tl_consho_entitlement', ['subscription' => 0], ['orderItem' => 2]);
        $this->connection->update('tl_consho_order_item', ['kind' => 'service'], ['id' => 1]);
        $this->connection->insert('tl_consho_entitlement', ['pid' => self::ORDER, 'orderItem' => 1, 'quantity' => '2.0000']);

        // E6: the delivery module learns of exactly this block.
        $revoked = (int) $this->connection->fetchOne('SELECT id FROM tl_consho_entitlement WHERE orderItem = 2');
        $revocations = $this->createMock(EntitlementRevocations::class);
        $revocations->expects(self::once())->method('revoked')->with([$revoked]);

        self::assertTrue($this->processing(true, revocations: $revocations)->accept(self::WITHDRAWAL, [1 => '1', 2 => '1'], self::NOW)->done);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT revoked FROM tl_consho_entitlement WHERE orderItem = 2'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT revoked FROM tl_consho_entitlement WHERE orderItem = 1'), 'eine von zwei: bleibt');
        self::assertTrue(BigDecimal::zero()->isEqualTo((string) $this->withdrawalRow()['valueCompensation']));
        self::assertSame('noPeriod', array_column((new ValueCompensation($this->connection))->proposal(self::WITHDRAWAL)['lines'], 'reason', 'item')[2] ?? null);
    }

    /**
     * Abo-8: the same for a renewal, with the real building block. The
     * licence goes back to the old subscription (unblocked), and the form
     * computes the same afterwards as at the acceptance.
     */
    public function testEineWiderrufeneVerlaengerungGibtDieLizenzZurueckUndDieRechnungBleibt(): void
    {
        $this->serviceSubscription();
        $renewal = (int) $this->connection->fetchOne('SELECT id FROM tl_consho_subscription WHERE orderItem = 2');
        $this->connection->insert('tl_consho_subscription', ['id' => 90, 'subscriptionNumber' => 'WEB-00070-1', 'firstOrder' => 3, 'orderItem' => 70, 'state' => 'active', 'periodUntil' => self::NOW + 3 * 86_400, 'renewedBy' => $renewal]);
        $this->connection->update('tl_consho_subscription', ['predecessor' => 90], ['id' => $renewal]);

        self::assertTrue($this->processing(true)->accept(self::WITHDRAWAL, [2 => '1'], self::NOW)->done);

        $entitlement = $this->connection->fetchAssociative('SELECT pid, orderItem, subscription, revoked FROM tl_consho_entitlement');
        self::assertSame([3, 70, 90, 0], array_map('intval', array_values($entitlement ?: [])));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT renewedBy FROM tl_consho_subscription WHERE id = 90'));

        self::assertSame('7.00', (new ValueCompensation($this->connection))->proposal(self::WITHDRAWAL)['amount']);
        self::assertTrue(BigDecimal::of('7')->isEqualTo((string) $this->withdrawalRow()['valueCompensation']));
    }

    /**
     * Abo-8: A cleared field retains nothing — as with the shipping share.
     * Only a field not shown (null) keeps the proposal.
     */
    public function testEinLeeresFeldBehaeltNichtsEin(): void
    {
        $this->serviceSubscription();
        $this->processing()->accept(self::WITHDRAWAL, [2 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '0', self::NOW, '')->done);
        self::assertTrue(BigDecimal::zero()->isEqualTo((string) $this->withdrawalRow()['valueCompensation']));
        self::assertTrue(BigDecimal::of('35')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
    }

    public function testDerBetreiberSetztDenWertersatz(): void
    {
        $this->serviceSubscription();
        $this->processing()->accept(self::WITHDRAWAL, [2 => '1'], self::NOW);

        self::assertSame('invalidCompensation', $this->processing()->refund(self::WITHDRAWAL, '0', self::NOW, '35,01')->key);
        self::assertSame(['35.00'], $this->processing()->refund(self::WITHDRAWAL, '0', self::NOW, '-1')->params);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '0', self::NOW, '3,50')->done);
        self::assertTrue(BigDecimal::of('3.5')->isEqualTo((string) $this->withdrawalRow()['valueCompensation']));
        self::assertTrue(BigDecimal::of('31.5')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
    }

    /**
     * Control case: set to 0, everything is back — the invoice is cancelled
     * as before.
     */
    public function testOhneWertersatzBleibtEsBeimStorno(): void
    {
        $this->serviceSubscription();
        $this->processing()->accept(self::WITHDRAWAL, [1 => '2', 2 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '5', self::NOW, '0')->done);

        self::assertSame([['cancel', 14]], $this->documentCalls);
        self::assertTrue($this->balance()->settled());
    }

    public function testMitVersandanteil(): void
    {
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '2,50', self::NOW)->done);

        $lines = $this->documentCalls[0][1];
        self::assertCount(2, $lines);
        self::assertSame('shipping', $lines[1]['lineType']);
        self::assertSame('-2.5000', $lines[1]['lineTotal']);
        self::assertTrue(BigDecimal::of('32.5')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
    }

    public function testMehrVersandAlsDaIstWirdAbgelehnt(): void
    {
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        self::assertSame('invalidShipping', $this->processing()->refund(self::WITHDRAWAL, '6', self::NOW)->key);
        self::assertSame([], $this->documentCalls);
    }

    /**
     * F3: Everything back in one step — the invoice is cancelled, and the
     * whole sum is refunded.
     */
    public function testAllesErstattetStorniertDieRechnung(): void
    {
        $this->processing()->accept(self::WITHDRAWAL, [1 => '2', 2 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '5', self::NOW)->done);

        self::assertSame([['cancel', 14]], $this->documentCalls);
        self::assertTrue(BigDecimal::of('-100')->isEqualTo((string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE amount < 0')));
        self::assertTrue($this->balance()->settled());
    }

    /**
     * F3a: Without an invoice and not everything withdrawn — the invoice is
     * created now, then the correction, both in the mail. Otherwise "invoice
     * on shipping" would issue the full invoice on shipping.
     */
    public function testOhneRechnungEntstehenRechnungUndKorrektur(): void
    {
        $this->invoice = null;
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '0', self::NOW)->done);
        self::assertSame(['issueOnce', 'correct'], array_column($this->documentCalls, 0));
        self::assertSame(55, (int) $this->withdrawalRow()['refundDocument']);
        self::assertSame([60, 55], $this->mails[0][1][4], 'Rechnung zuerst, dann die Korrektur');

        // F3b: without a giro code; everything is paid, so no remaining amount.
        self::assertSame(['giroCode' => false], $this->documentCalls[0][1]);
        self::assertSame('', $this->mails[0][1][6]);
    }

    /**
     * F3b: Unpaid, invoice issued along with it — the mail names what is
     * still to be paid after the correction (100 − 30 = 70), because no
     * document names that amount.
     */
    public function testUnbezahltNenntDieMailDenRestbetrag(): void
    {
        $this->invoice = null;
        $this->connection->delete('tl_consho_payment', ['pid' => self::ORDER]);
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '0', self::NOW)->done);
        self::assertSame(['giroCode' => false], $this->documentCalls[0][1]);
        self::assertSame('70,00 €', str_replace("\u{a0}", ' ', (string) $this->mails[0][1][6]));
    }

    /** Everything withdrawn, nothing is shipped: no invoice, no document. */
    public function testOhneRechnungUndAllesWiderrufenKeinBeleg(): void
    {
        $this->invoice = null;
        $this->processing()->accept(self::WITHDRAWAL, [1 => '2', 2 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '5', self::NOW)->done);
        self::assertSame([], $this->documentCalls);
        self::assertSame(0, (int) $this->withdrawalRow()['refundDocument']);
        self::assertSame([], $this->mails[0][1][4]);
    }

    /**
     * Never money back that never came: unpaid, only the amount due drops.
     */
    public function testUnbezahltSinktNurDieForderung(): void
    {
        $this->connection->delete('tl_consho_payment', ['pid' => self::ORDER]);
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '0', self::NOW)->done);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_payment'));
        self::assertTrue(BigDecimal::of('70')->isEqualTo($this->balance()->claim));
        self::assertSame('', $this->mails[0][1][2], 'keine Auszahlung in der Mail');
    }

    public function testZweimalErstattenGehtNicht(): void
    {
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);
        $this->processing()->refund(self::WITHDRAWAL, '0', self::NOW);

        self::assertSame('alreadyRefunded', $this->processing()->refund(self::WITHDRAWAL, '0', self::NOW)->key);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_payment WHERE amount < 0'));
    }

    /**
     * F9: If the number range is missing, the page says so — and nothing is
     * booked.
     */
    public function testOhneNummernkreisWirdNichtsGebucht(): void
    {
        $this->rangeMissing = true;
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        $result = $this->processing()->refund(self::WITHDRAWAL, '0', self::NOW);

        self::assertSame('numberRangeMissing', $result->key);
        self::assertSame(['creditNote'], $result->params);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_payment WHERE amount < 0'));
        self::assertNull($this->withdrawalRow()['refundedAt']);
    }

    /**
     * Block H (H-5, R1): With a prepayment discount of 9.50 (10 % of 95.00,
     * one tax class) the order cost 90.50. One hiking pole goes back: what it
     * cost is refunded — 30.00 minus its share of the discount (net 25.00 of
     * 79.1667 → 3.00), so 27.00. The correction shows the share as a line of
     * its own, positive.
     */
    public function testTeilwiderrufNimmtDenNachlassAnteiligMit(): void
    {
        $this->paymentDiscount();
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        // F7: The amount due only drops by what the pole cost.
        self::assertTrue(BigDecimal::of('63.5')->isEqualTo($this->balance()->claim), $this->balance()->claim);
        self::assertSame('27.0000', (string) BigDecimal::of($this->processing()->proposal(self::WITHDRAWAL)['goods'])->toScale(4));

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '0', self::NOW)->done);

        $lines = $this->documentCalls[0][1];
        self::assertSame(['product', 'paymentDiscount'], array_column($lines, 'lineType'));
        self::assertSame('-30.0000', $lines[0]['lineTotal']);
        self::assertSame('Vorkasse', $lines[1]['productName']);
        self::assertSame('3.0000', $lines[1]['lineTotal']);
        self::assertSame('2.5000', $lines[1]['unitPrice']);
        self::assertSame('0.5000', $lines[1]['taxAmount']);

        self::assertTrue(BigDecimal::of('27')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
        self::assertTrue(BigDecimal::of('-27')->isEqualTo((string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE amount < 0')));
        self::assertTrue(BigDecimal::of('63.5')->isEqualTo($this->balance()->claim));
        self::assertTrue($this->balance()->paymentSettled());
    }

    /**
     * H-5 (R1): A discount code applied only to the backpack (−3.50, recorded
     * on its line at purchase). The withdrawn hiking pole gives none of it
     * back; the backpack all of it.
     */
    public function testDerRabattGehtNurMitDerZeileZurueckDieErBekam(): void
    {
        $this->connection->executeStatement('UPDATE tl_consho_order_item SET taxClass = 1');
        $this->connection->update('tl_consho_order_item', ['discountNet' => '2.9167'], ['id' => 2]);
        $this->connection->insert('tl_consho_order_item', ['id' => 4, 'pid' => self::ORDER, 'productName' => 'Herbstrabatt (Herbst)', 'quantity' => '1.0000', 'unitPrice' => '-2.9167', 'taxRate' => '20.0000', 'taxAmount' => '-0.5833', 'lineTotal' => '-3.5000', 'lineType' => 'discount', 'taxClass' => 1, 'taxCountry' => 'AT']);
        $this->connection->update('tl_consho_order', ['grandTotal' => '96.5000'], ['id' => self::ORDER]);
        $this->connection->update('tl_consho_payment', ['amount' => '96.5000'], ['pid' => self::ORDER]);

        $this->processing()->accept(self::WITHDRAWAL, [1 => '1', 2 => '1'], self::NOW);
        self::assertTrue(BigDecimal::of('35')->isEqualTo($this->balance()->claim), 'Forderung 96,50 − 30 − 31,50');

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '0', self::NOW)->done);

        $lines = $this->documentCalls[0][1];
        self::assertSame(['product', 'product', 'discount'], array_column($lines, 'lineType'));
        self::assertSame(['Herbstrabatt (Herbst)', '1', '2.9167', '0.5833', '3.5000', 'AT'], [$lines[2]['productName'], $lines[2]['quantity'], $lines[2]['unitPrice'], $lines[2]['taxAmount'], $lines[2]['lineTotal'], $lines[2]['taxCountry']]);
        self::assertTrue(BigDecimal::of('61.5')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
    }

    /**
     * H-5 with compensation for value (P79): The backpack is a service
     * subscription and cost 31.50 after the prepayment discount; 7 of 35
     * days → 6.30. It is retained from the backpack, the discount goes back
     * entirely.
     */
    public function testWertersatzAufDasWasDieZeileGekostetHat(): void
    {
        $this->paymentDiscount();
        $this->serviceSubscription();
        $this->processing()->accept(self::WITHDRAWAL, [1 => '2', 2 => '1'], self::NOW);

        self::assertTrue(BigDecimal::of('6.3')->isEqualTo((string) $this->withdrawalRow()['valueCompensation']));
        // 90.50 − (60 − 6) − (31.50 − 6.30).
        self::assertTrue(BigDecimal::of('11.3')->isEqualTo($this->balance()->claim), $this->balance()->claim);

        self::assertSame('invalidCompensation', $this->processing()->refund(self::WITHDRAWAL, '5', self::NOW, '31.51')->key, 'höchstens, was der Rucksack gekostet hat');
        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '5', self::NOW)->done);

        $lines = $this->documentCalls[0][1];
        self::assertSame(['-60.0000', '-28.7000', '9.5000', '-5.0000'], array_column($lines, 'lineTotal'));
        self::assertTrue(BigDecimal::of('84.2')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
        self::assertTrue($this->balance()->settled(), 'Forderung 6,30, bezahlt 6,30');
    }

    /**
     * Two services, 60.00 retained: the first cost 54.00 after the discount
     * and is retained entirely — with its share; the remaining 6.00 come off
     * the backpack (31.50 → 25.50).
     */
    public function testDerWertersatzReichtVonZeileZuZeileNachDemWasSieGekostetHaben(): void
    {
        $this->paymentDiscount();
        $this->serviceSubscription();
        $this->connection->update('tl_consho_order_item', ['kind' => 'service'], ['id' => 1]);
        $this->processing()->accept(self::WITHDRAWAL, [1 => '2', 2 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '0', self::NOW, '60')->done);

        self::assertSame(['-29.0000', '3.5000'], array_column($this->documentCalls[0][1], 'lineTotal'));
        self::assertTrue(BigDecimal::of('25.5')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
    }

    public function testDerGanzeRucksackEinbehaltenNimmtAuchSeinenNachlassNichtMit(): void
    {
        $this->paymentDiscount();
        $this->serviceSubscription();
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1', 2 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '0', self::NOW, '31.50')->done);

        $lines = $this->documentCalls[0][1];
        // Hiking pole 1 of 2 with half its share (3.00); nothing from the backpack.
        self::assertSame(['-30.0000', '3.0000'], array_column($lines, 'lineTotal'));
        self::assertTrue(BigDecimal::of('27')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
    }

    public function testNurAngenommeneWerdenErstattet(): void
    {
        self::assertSame('notAccepted', $this->processing()->refund(self::WITHDRAWAL, '0', self::NOW)->key);
    }

    /**
     * Prepayment 10 % on the goods (95.00): one line per tax class, negative,
     * as PurchaseHandler writes them. All lines in class 1.
     */
    private function paymentDiscount(): void
    {
        $this->connection->executeStatement('UPDATE tl_consho_order_item SET taxClass = 1');
        $this->connection->insert('tl_consho_order_item', ['id' => 4, 'pid' => self::ORDER, 'productName' => 'Vorkasse', 'quantity' => '1.0000', 'unitPrice' => '-7.9167', 'taxRate' => '20.0000', 'taxAmount' => '-1.5833', 'lineTotal' => '-9.5000', 'lineType' => 'paymentDiscount', 'taxClass' => 1]);
        $this->connection->update('tl_consho_order', ['grandTotal' => '90.5000'], ['id' => self::ORDER]);
        $this->connection->update('tl_consho_payment', ['amount' => '90.5000'], ['pid' => self::ORDER]);
    }

    private function serviceSubscription(): void
    {
        $day = 86_400;
        $this->connection->update('tl_consho_order', ['digitalConsentAt' => self::NOW - 10 * $day], ['id' => self::ORDER]);
        $this->connection->update('tl_consho_order_item', ['kind' => 'service', 'subscriptionRule' => 3], ['id' => 2]);
        $this->connection->update('tl_consho_withdrawal', ['receivedAt' => self::NOW], ['id' => self::WITHDRAWAL]);
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00077-1', 'firstOrder' => self::ORDER, 'orderItem' => 2, 'startedAt' => self::NOW - 7 * $day, 'periodFrom' => self::NOW - 7 * $day, 'periodUntil' => self::NOW + 28 * $day]);
        // The release: the entitlement and the reported delivery it comes with.
        $this->connection->insert('tl_consho_entitlement', ['pid' => self::ORDER, 'orderItem' => 2, 'subscription' => (int) $this->connection->lastInsertId(), 'validFrom' => self::NOW - 6 * $day - 3600]);
        $this->connection->insert('tl_consho_delivery', ['id' => 30, 'pid' => self::ORDER, 'shippedAt' => self::NOW - 6 * $day - 3600, 'reportedAt' => self::NOW - 6 * $day - 3600]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 30, 'orderItem' => 2, 'quantity' => '1']);
    }

    /**
     * @return array<string, mixed>
     */
    private function withdrawalRow(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_withdrawal WHERE id = ?', [self::WITHDRAWAL]);
        self::assertIsArray($row);

        return $row;
    }

    private function balance(): \Webwerkwien\ContaoConshoBundle\Order\OrderBalance
    {
        $balance = $this->reader()->of(self::ORDER);
        self::assertNotNull($balance);

        return $balance;
    }

    private function reader(): OrderBalanceReader
    {
        return new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator());
    }

    private function processing(bool $realSubscriptions = false, StockReturn|null $stock = null, VoucherReturn|null $vouchers = null, EntitlementRevocations|null $revocations = null): WithdrawalProcessing
    {
        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $row = function (int $id): array|null {
            $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$id]);

            return false === $row ? null : array_map(static fn ($v): string => (string) $v, $row);
        };
        $orders->method('lockOrder')->willReturnCallback($row);
        $orders->method('order')->willReturnCallback($row);

        $documentRows = $this->createStub(DocumentRepositoryInterface::class);
        $documentRows->method('findIdByOrderAndType')->willReturnCallback(fn (): int|null => $this->invoice);

        $documents = $this->createStub(DocumentIssuerInterface::class);
        $documents->method('correct')->willReturnCallback(function (int $invoiceId, array $lines, string $withdrawalNumber = '', array $prepaid = []): int {
            if ($this->rangeMissing) {
                throw NumberRangeNotConfigured::for(1, 'creditNote');
            }

            $this->documentCalls[] = ['correct', $lines, $prepaid];

            return 55;
        });
        $documents->method('issueOnce')->willReturnCallback(function (int $orderId, int $shopId, DocumentType $type, int|null $related = null, bool $giroCode = true): int {
            $this->documentCalls[] = ['issueOnce', ['giroCode' => $giroCode]];

            return 60;
        });
        $documents->method('cancel')->willReturnCallback(function (int $invoiceId): int {
            $this->documentCalls[] = ['cancel', $invoiceId];

            return 56;
        });

        $statuses = $this->createStub(OrderStatusAdvancer::class);
        $statuses->method('advance')->willReturnCallback(function (int $orderId, OrderEvent $event): null {
            $this->events[] = $event;

            return null;
        });

        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/x');

        $mail = $this->createStub(OrderEventMail::class);
        $mail->method('withdrawalRejected')->willReturnCallback(function (mixed ...$args): bool {
            $this->mails[] = ['withdrawalRejected', $args];

            return true;
        });
        $mail->method('refundBooked')->willReturnCallback(function (mixed ...$args): bool {
            $this->mails[] = ['refundBooked', $args];

            return true;
        });

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

        $subscriptions = $realSubscriptions
            ? new SubscriptionPurchaseCancellation($this->connection, new SubscriptionDelinquency($this->connection, $lock, $this->reader(), new SubscriptionCalendar('Europe/Vienna')))
            : $this->createStub(SubscriptionPurchaseCancellation::class);
        $subscriptions instanceof \PHPUnit\Framework\MockObject\Stub && $subscriptions->method('afterWithdrawal')->willReturnCallback(function (int $orderId, array $items, int $now): void {
            $this->ended[] = [$orderId, $items, $now];
        });

        return new WithdrawalProcessing(
            $this->connection,
            $orders,
            $documentRows,
            $documents,
            $this->createStub(DocumentArchiverInterface::class),
            new DocumentPayloadBuilder(),
            $this->reader(),
            $statuses,
            $links,
            $mail,
            new MoneyFormatter(),
            $subscriptions,
            null,
            $stock,
            $vouchers,
            $revocations,
        );
    }
}

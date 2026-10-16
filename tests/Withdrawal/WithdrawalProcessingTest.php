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
 * Widerruf bearbeiten (D-3, Entscheidungen F1–F10). Echte Salden auf SQLite;
 * Belege, Mail und Status sind Spione — was ein Beleg enthält, prüft
 * DocumentIssuerTest.
 *
 * Bestellung: 2 × Wanderstock (60,00), 1 × Rucksack (35,00), Versand 5,00 —
 * zusammen 100,00, bezahlt.
 */
final class WithdrawalProcessingTest extends TestCase
{
    private const ORDER = 7;
    private const WITHDRAWAL = 1;
    private const NOW = 1_790_000_000;

    private Connection $connection;

    /** @var list<array{string, mixed}> */
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
        // The real DCA schema: the subscription's delinquency check reads more of the order (review Abo-8).
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        // From the DCA (review WG-7, P5), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        $this->connection->insert('tl_consho_order', ['id' => self::ORDER, 'shop' => 1, 'orderNumber' => 'WEB-00077', 'currency' => 'EUR', 'language' => 'de', 'grandTotal' => '100.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'productName' => 'Wanderstock', 'quantity' => '2.0000', 'unitPrice' => '25.0000', 'taxRate' => '20.0000', 'taxAmount' => '10.0000', 'lineTotal' => '60.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'productName' => 'Rucksack', 'quantity' => '1.0000', 'unitPrice' => '29.1667', 'taxRate' => '20.0000', 'taxAmount' => '5.8333', 'lineTotal' => '35.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 3, 'pid' => self::ORDER, 'productName' => 'Paket', 'quantity' => '1.0000', 'unitPrice' => '4.1667', 'taxRate' => '20.0000', 'taxAmount' => '0.8333', 'lineTotal' => '5.0000', 'lineType' => 'shipping']);
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '100.0000']);

        // Erklärt: 1 Wanderstock.
        $this->connection->insert('tl_consho_withdrawal', ['id' => self::WITHDRAWAL, 'pid' => self::ORDER, 'number' => 'WEB-00077-W1', 'scope' => 'items']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => self::WITHDRAWAL, 'orderItem' => 1, 'quantity' => '1']);
    }

    // ── Annehmen ──────────────────────────────────────────────────────────

    public function testAnnehmenMitDenErklaertenMengen(): void
    {
        $result = $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        self::assertTrue($result->done);
        self::assertSame('accepted', $this->withdrawalRow()['state']);
        self::assertSame(self::NOW, (int) $this->withdrawalRow()['decidedAt']);

        // Die Erklärung bleibt, daneben steht, was angenommen ist.
        $item = $this->connection->fetchAssociative('SELECT quantity, acceptedQuantity, declared FROM tl_consho_withdrawal_item');
        self::assertIsArray($item);
        self::assertTrue(BigDecimal::of('1')->isEqualTo((string) $item['quantity']));
        self::assertTrue(BigDecimal::of('1')->isEqualTo((string) $item['acceptedQuantity']));

        // F1: Teilwiderruf → kein Versandanteil vorgeschlagen.
        self::assertTrue(BigDecimal::zero()->isEqualTo((string) $this->withdrawalRow()['refundShipping']));
        // F7: Die Forderung sinkt um 30.
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
     * Abo-8, P77: Eine Abo-Zeile wird nur ganz angenommen — ein Teil wäre eine
     * Mengenänderung am laufenden Abo, und die gibt es nicht (A11).
     */
    public function testEineAboZeileNurGanz(): void
    {
        $this->connection->update('tl_consho_order_item', ['subscriptionRule' => 3], ['id' => 1]);

        $part = $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);
        self::assertSame('wholeOnly', $part->key);
        self::assertSame('received', $this->withdrawalRow()['state']);

        self::assertSame([], $this->ended, 'abgelehnt: kein Abo beendet');

        self::assertTrue($this->processing()->accept(self::WITHDRAWAL, [1 => '2'], self::NOW)->done);
        // P78: die angenommenen Zeilen gehen an das Beenden der Abos.
        self::assertSame([[self::ORDER, [1], self::NOW]], $this->ended);
    }

    public function testEinAndererWiderrufZaehltMit(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['id' => 2, 'pid' => self::ORDER, 'number' => 'WEB-00077-W2', 'state' => 'accepted']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 2, 'orderItem' => 1, 'acceptedQuantity' => '2']);

        self::assertSame('tooMuch', $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW)->key);
    }

    // ── Ablehnen (F6) ─────────────────────────────────────────────────────

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

    // ── Rücksendung (F8) ──────────────────────────────────────────────────

    public function testRuecksendungHoechstensDasAngenommene(): void
    {
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);
        $row = (int) $this->connection->fetchOne('SELECT id FROM tl_consho_withdrawal_item');

        self::assertSame('invalidQuantity', $this->processing()->recordReturn(self::WITHDRAWAL, self::NOW, [$row => '2'], self::NOW)->key);
        self::assertTrue($this->processing()->recordReturn(self::WITHDRAWAL, self::NOW, [$row => '1'], self::NOW)->done);
        self::assertSame(self::NOW, (int) $this->withdrawalRow()['returnReceivedAt']);

        // Review S2: ein leeres Datum löscht das erfasste nicht.
        self::assertTrue($this->processing()->recordReturn(self::WITHDRAWAL, 0, [$row => '1'], self::NOW)->done);
        self::assertSame(self::NOW, (int) $this->withdrawalRow()['returnReceivedAt']);
    }

    /**
     * Block G (G9): jede Position der Rücksendung geht an die Rückbuchung —
     * mit dem Häkchen aus dem Formular, sonst mit der letzten Wahl.
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

        // Die zweite ohne Angabe nimmt die gespeicherte Wahl — hier die
        // Vorgabe, weil das Doppel nichts speichert.
        self::assertSame([[$row, false, 5], [$row, true, 0]], $calls);
    }

    /**
     * Review G, L2: Ein angenommener Widerruf vor der Auslieferung gibt
     * Schlüssel frei — die Produktseite muss es erfahren.
     */
    public function testAnnehmenMeldetFreigewordeneSchluessel(): void
    {
        $stock = $this->createMock(StockReturn::class);
        $stock->expects(self::once())->method('keysFreed')->with(self::ORDER);

        self::assertTrue($this->processing(stock: $stock)->accept(self::WITHDRAWAL, [1 => '1'], self::NOW)->done);
    }

    // ── Erstattung (F3, F4) ───────────────────────────────────────────────

    /**
     * DL4, zweites Review S1: Die Korrektur trägt das Steuerland ihrer Zeilen
     * und fasst damit je Land und Satz zusammen wie die Rechnung, die sie
     * korrigiert — geprüft am Endzustand, der Übersicht des echten Builders.
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

    // ── Wertgutscheine (WG-6) ─────────────────────────────────────────────

    /**
     * WG11: Bezahlt mit 40 Gutschein und 60 Karte — die Erstattung von 30
     * geht ganz auf den Gutschein (dasselbe Zahlungsmittel zuerst).
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

        // Die Erstattung zeigt auf die Gutscheinzahlung; die Mail sagt, was zurückging.
        self::assertSame((int) $this->connection->fetchOne('SELECT id FROM tl_consho_payment WHERE amount < 0'), (int) $this->withdrawalRow()['refundPayment']);
        self::assertSame('30,00 €', str_replace("\u{a0}", ' ', (string) $this->mails[0][1][9]));
        self::assertTrue($this->balance()->paymentSettled());
    }

    /**
     * WG11: der Betreiber ändert die Aufteilung — 10 auf den Gutschein, 20
     * an die Zahlungsart.
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
     * Mehr, als der Gutschein bezahlt hat, geht nicht auf ihn — und
     * abgelehnt heißt: nichts gebucht.
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
     * WG7, Review S1 (Michael, 2026-10-06: „wurde er eingelöst, wurde damit
     * etwas erworben, was zurück gegeben werden muss"): ein eingelöster
     * Gutschein — hier einer von zweien — hält die Annahme auf; nichts ist
     * gebucht, die Meldung nennt ihn.
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

        // Gutschein 2 hat 30 in einer anderen Bestellung bezahlt.
        $this->connection->insert('tl_consho_order', ['id' => 78, 'orderNumber' => 'WEB-00078']);
        $this->connection->update('tl_consho_voucher', ['balance' => '50'], ['id' => 2]);
        (new VoucherLedger($this->connection))->book(2, '-30', VoucherBookingType::Redeem, self::NOW - 50, 78);

        $result = $this->processing(vouchers: $return)->accept(self::WITHDRAWAL, [4 => '2'], self::NOW);

        self::assertFalse($result->done);
        self::assertSame('voucherRedeemed', $result->key);
        self::assertSame(['…KKKK (WEB-00078)'], $result->params);
        self::assertSame(['0', '0'], array_map('strval', $this->connection->fetchFirstColumn('SELECT blocked FROM tl_consho_voucher WHERE id IN (2, 3) ORDER BY id')));
        self::assertSame('received', $this->withdrawalRow()['state']);

        // Nur das nicht eingelöste Stück: angenommen, gesperrt, voll erstattet.
        self::assertTrue($this->processing(vouchers: $return)->accept(self::WITHDRAWAL, [4 => '1'], self::NOW)->done);
        self::assertSame(['0', '1'], array_map('strval', $this->connection->fetchFirstColumn('SELECT blocked FROM tl_consho_voucher WHERE id IN (2, 3) ORDER BY id')));
        self::assertTrue(BigDecimal::zero()->isEqualTo((string) $this->withdrawalRow()['valueCompensation']));

        self::assertTrue($this->processing(vouchers: $return)->refund(self::WITHDRAWAL, '0', self::NOW, null, '0')->done);
        self::assertTrue(BigDecimal::of('50')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
    }

    /**
     * Zweites Review, L-B: ein von Hand gesperrter Gutschein hält die
     * Annahme mit eigener Meldung auf — entscheiden muss der Betreiber.
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
     * Gutschein 1 hat 40 von der Bestellung bezahlt, die Karte 60.
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
        // Stückpreis netto wie auf der Rechnung, Menge positiv (Regel des Stornos).
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

        // Forderung 70, bezahlt 70.
        $balance = $this->balance();
        self::assertTrue(BigDecimal::of('70')->isEqualTo($balance->claim));
        self::assertTrue($balance->paymentSettled());

        self::assertSame([OrderEvent::WithdrawalRefunded], $this->events);
        self::assertSame('refundBooked', $this->mails[0][0]);
        self::assertSame('30,00 €', str_replace("\u{a0}", ' ', (string) $this->mails[0][1][2]));
        self::assertSame([55], $this->mails[0][1][4]);
    }

    /**
     * Abo-8, P79: Der Rucksack ist hier ein Dienstleistungs-Abo (35,00 €,
     * Periode 35 Tage), mit Zustimmung freigegeben, 7 begonnene Tage genutzt —
     * vorgeschlagen 7,00 € Wertersatz. Bis zur Erstattung bleiben sie offen,
     * dann wird korrigiert statt storniert, auch wenn alles widerrufen ist.
     */
    public function testWertersatzWirdVorgeschlagenUndEinbehalten(): void
    {
        $this->serviceSubscription();
        $this->processing()->accept(self::WITHDRAWAL, [1 => '2', 2 => '1'], self::NOW);

        self::assertTrue(BigDecimal::of('7')->isEqualTo((string) $this->withdrawalRow()['valueCompensation']));
        // Forderung 100 − 60 − (35 − 7) = 12: der Wertersatz bleibt geschuldet.
        self::assertTrue(BigDecimal::of('12')->isEqualTo($this->balance()->claim));

        // Beendet hat es die Annahme (P78; hier ein Stub) — die Mail liest den Zustand.
        $this->connection->update('tl_consho_subscription', ['state' => 'ended', 'endsAt' => self::NOW], ['orderItem' => 2]);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '5', self::NOW)->done);

        // P81: die Mail nennt den Wertersatz und das beendete Abo.
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
     * Review Abo-8, L2 (Regel 38): Annahme und Erstattung mit dem echten
     * Baustein statt des Stubs. Der Vorschlag entsteht vor dem Beenden, das
     * Abo endet zum Zeitpunkt der Annahme, die Berechtigung ist gesperrt, und
     * die Mail nennt genau dieses Abo.
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
     * DL9: Eine einmalige Dienstleistung, ganz widerrufen, ist gesperrt — ohne
     * Abo, das es beenden könnte. Wertersatz schlägt Consho nicht vor: ohne
     * Laufzeit gibt es keine Tage zu zählen (Grund noPeriod). Teilweise
     * widerrufen bleibt die Berechtigung; Ware hat keine.
     */
    public function testEineEinmaligeDienstleistungWirdGesperrt(): void
    {
        $this->serviceSubscription();
        $this->connection->update('tl_consho_order_item', ['subscriptionRule' => 0], ['id' => 2]);
        $this->connection->delete('tl_consho_subscription', ['orderItem' => 2]);
        $this->connection->update('tl_consho_entitlement', ['subscription' => 0], ['orderItem' => 2]);
        $this->connection->update('tl_consho_order_item', ['kind' => 'service'], ['id' => 1]);
        $this->connection->insert('tl_consho_entitlement', ['pid' => self::ORDER, 'orderItem' => 1, 'quantity' => '2.0000']);

        // E6: das Auslieferungsmodul erfährt genau diese Sperre.
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
     * Zweites Review Abo-8, L1: dasselbe für eine Verlängerung, mit dem echten
     * Baustein. Die Lizenz geht ans alte Abo zurück (ungesperrt), und die
     * Maske rechnet danach dasselbe wie bei der Annahme (S2).
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
     * Review Abo-8, S3: Ein geleertes Feld behält nichts ein — wie beim
     * Versandanteil. Nur ein nicht gezeigtes Feld (null) behält den Vorschlag.
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
     * Gegenprobe: Auf 0 gesetzt, ist alles zurück — die Rechnung wird wie
     * bisher storniert.
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
     * F3: Alles zurück in einem Schritt — die Rechnung wird storniert, und
     * erstattet wird die ganze Summe.
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
     * F3a (Fable-Review B2): Ohne Rechnung und nicht alles widerrufen — die
     * Rechnung entsteht jetzt, dann die Korrektur, beide in der Mail. Beim
     * Versand stellte „Rechnung bei Versand" sonst die volle Rechnung aus.
     */
    public function testOhneRechnungEntstehenRechnungUndKorrektur(): void
    {
        $this->invoice = null;
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '0', self::NOW)->done);
        self::assertSame(['issueOnce', 'correct'], array_column($this->documentCalls, 0));
        self::assertSame(55, (int) $this->withdrawalRow()['refundDocument']);
        self::assertSame([60, 55], $this->mails[0][1][4], 'Rechnung zuerst, dann die Korrektur');

        // F3b: ohne Giro-Code; bezahlt ist alles, also kein Restbetrag.
        self::assertSame(['giroCode' => false], $this->documentCalls[0][1]);
        self::assertSame('', $this->mails[0][1][6]);
    }

    /**
     * F3b: Unbezahlt, Rechnung mit ausgestellt — die Mail nennt, was nach
     * der Korrektur noch zu zahlen ist (100 − 30 = 70), denn kein Beleg nennt
     * diesen Betrag.
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

    /** Alles widerrufen, nichts wird versandt: keine Rechnung, kein Beleg. */
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
     * Nie Geld zurück, das nie kam: unbezahlt sinkt nur die Forderung.
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
     * F9: Fehlt der Nummernkreis, sagt die Seite es — und nichts ist gebucht.
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
     * Block H (H-5, R1): Mit Vorkasse-Nachlass 9,50 (10 % von 95,00, eine
     * Steuerklasse) hat die Bestellung 90,50 gekostet. Ein Wanderstock geht
     * zurück: erstattet wird, was er gekostet hat — 30,00 abzüglich seines
     * Anteils am Nachlass (netto 25,00 von 79,1667 → 3,00), also 27,00. Die
     * Korrektur weist den Anteil als eigene Zeile aus, positiv.
     */
    public function testTeilwiderrufNimmtDenNachlassAnteiligMit(): void
    {
        $this->paymentDiscount();
        $this->processing()->accept(self::WITHDRAWAL, [1 => '1'], self::NOW);

        // F7: Die Forderung sinkt nur um das, was der Stock gekostet hat.
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
     * H-5 (R1): Ein Rabattcode galt nur für den Rucksack (−3,50, beim Kauf
     * an seiner Zeile festgehalten). Der widerrufene Wanderstock gibt nichts
     * davon zurück; der Rucksack alles.
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
     * H-5 mit Wertersatz (P79): Der Rucksack ist ein Dienstleistungs-Abo und
     * hat nach dem Vorkasse-Nachlass 31,50 gekostet; 7 von 35 Tagen → 6,30.
     * Einbehalten wird vom Rucksack, der Nachlass geht ganz zurück.
     */
    public function testWertersatzAufDasWasDieZeileGekostetHat(): void
    {
        $this->paymentDiscount();
        $this->serviceSubscription();
        $this->processing()->accept(self::WITHDRAWAL, [1 => '2', 2 => '1'], self::NOW);

        self::assertTrue(BigDecimal::of('6.3')->isEqualTo((string) $this->withdrawalRow()['valueCompensation']));
        // 90,50 − (60 − 6) − (31,50 − 6,30).
        self::assertTrue(BigDecimal::of('11.3')->isEqualTo($this->balance()->claim), $this->balance()->claim);

        self::assertSame('invalidCompensation', $this->processing()->refund(self::WITHDRAWAL, '5', self::NOW, '31.51')->key, 'höchstens, was der Rucksack gekostet hat');
        self::assertTrue($this->processing()->refund(self::WITHDRAWAL, '5', self::NOW)->done);

        $lines = $this->documentCalls[0][1];
        self::assertSame(['-60.0000', '-28.7000', '9.5000', '-5.0000'], array_column($lines, 'lineTotal'));
        self::assertTrue(BigDecimal::of('84.2')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
        self::assertTrue($this->balance()->settled(), 'Forderung 6,30, bezahlt 6,30');
    }

    /**
     * Zwei Dienstleistungen, 60,00 einbehalten: die erste hat nach dem
     * Nachlass 54,00 gekostet und wird ganz einbehalten — samt ihrem Anteil;
     * die übrigen 6,00 gehen vom Rucksack ab (31,50 → 25,50).
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
        // Wanderstock 1 von 2 mit seinem halben Anteil (3,00); vom Rucksack nichts.
        self::assertSame(['-30.0000', '3.0000'], array_column($lines, 'lineTotal'));
        self::assertTrue(BigDecimal::of('27')->isEqualTo((string) $this->withdrawalRow()['refundAmount']));
    }

    public function testNurAngenommeneWerdenErstattet(): void
    {
        self::assertSame('notAccepted', $this->processing()->refund(self::WITHDRAWAL, '0', self::NOW)->key);
    }

    /**
     * Vorkasse 10 % auf die Ware (95,00): eine Zeile je Steuerklasse, negativ,
     * wie PurchaseHandler sie schreibt. Alle Zeilen in Klasse 1.
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
        $documents->method('correct')->willReturnCallback(function (int $invoiceId, array $lines): int {
            if ($this->rangeMissing) {
                throw NumberRangeNotConfigured::for(1, 'creditNote');
            }

            $this->documentCalls[] = ['correct', $lines];

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

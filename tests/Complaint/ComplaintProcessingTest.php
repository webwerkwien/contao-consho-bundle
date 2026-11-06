<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Complaint;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Complaint\ComplaintProcessing;
use Webwerkwien\ContaoConshoBundle\Digital\EntitlementRevocations;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalance;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OrderEvent;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Stock\StockBooking;
use Webwerkwien\ContaoConshoBundle\Stock\StockReturn;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherBookingType;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherLedger;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherReturn;

/**
 * A complaint's rescission and price reduction (block Reklamation, RK-4,
 * L1–L9). Real balances on SQLite; documents and status are spies.
 *
 * Order: 2 × hiking pole (`Wanderstock`, 60.00), 1 × backpack (`Rucksack`,
 * 35.00), shipping 5.00 — together 100.00, paid. The complaint names both
 * hiking poles (row 10) and the backpack (row 11).
 */
final class ComplaintProcessingTest extends TestCase
{
    private const ORDER = 7;
    private const COMPLAINT = 5;
    private const NOW = 1_790_000_000;

    private Connection $connection;

    /** @var list<array{0: string, 1: mixed, 2?: string, 3?: string}> */
    private array $documentCalls = [];

    /** @var list<OrderEvent> */
    private array $events = [];

    /** @var list<list<mixed>> the arguments of each complaintRefundBooked() */
    private array $mails = [];

    private bool $mailSent = true;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_payment_attempt', 'tl_consho_provider_event', 'tl_consho_entitlement'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => self::ORDER, 'shop' => 1, 'orderNumber' => 'WEB-00077', 'currency' => 'EUR', 'language' => 'de', 'grandTotal' => '100.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'productName' => 'Wanderstock', 'quantity' => '2.0000', 'unitPrice' => '25.0000', 'taxRate' => '20.0000', 'taxAmount' => '10.0000', 'lineTotal' => '60.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'productName' => 'Rucksack', 'quantity' => '1.0000', 'unitPrice' => '29.1667', 'taxRate' => '20.0000', 'taxAmount' => '5.8333', 'lineTotal' => '35.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 3, 'pid' => self::ORDER, 'productName' => 'Paket', 'quantity' => '1.0000', 'unitPrice' => '4.1667', 'taxRate' => '20.0000', 'taxAmount' => '0.8333', 'lineTotal' => '5.0000', 'lineType' => 'shipping']);
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '100.0000']);

        $this->connection->insert('tl_consho_complaint', ['id' => self::COMPLAINT, 'pid' => self::ORDER, 'number' => 'WEB-00077-R1']);
        $this->connection->insert('tl_consho_complaint_item', ['id' => 10, 'pid' => self::COMPLAINT, 'orderItem' => 1, 'quantity' => '2']);
        $this->connection->insert('tl_consho_complaint_item', ['id' => 11, 'pid' => self::COMPLAINT, 'orderItem' => 2, 'quantity' => '1']);
    }

    // ── Decide (L1) ───────────────────────────────────────────────────────

    public function testRuecktrittFestlegenZaehltSofort(): void
    {
        $result = $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '1'], self::NOW);

        self::assertTrue($result->done, $result->key);
        self::assertSame(['inProgress', 'rescission', self::NOW], [$this->complaint()['state'], $this->complaint()['remedy'], (int) $this->complaint()['decidedAt']]);
        self::assertSame(['1', '0'], [$this->item(10)['rescindedQuantity'], $this->item(11)['rescindedQuantity']]);
        // Partial: no delivery costs proposed.
        self::assertTrue(BigDecimal::zero()->isEqualTo((string) $this->complaint()['refundShipping']));
        self::assertTrue(BigDecimal::of('70')->isEqualTo($this->balance()->claim));
    }

    public function testVollstaendigerRuecktrittSchlaegtDenVersandVor(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '2', 11 => '1'], self::NOW);

        self::assertTrue(BigDecimal::of('5')->isEqualTo((string) $this->complaint()['refundShipping']));
    }

    public function testRuecktrittHoechstensDieReklamierteUndNochOffeneMenge(): void
    {
        $this->connection->update('tl_consho_complaint_item', ['quantity' => '1'], ['id' => 10]);
        self::assertSame('tooMuch', $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '2'], self::NOW)->key);

        // L2: a withdrawn piece cannot be rescinded too.
        $this->connection->update('tl_consho_complaint_item', ['quantity' => '2'], ['id' => 10]);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => self::ORDER, 'number' => 'WEB-00077-W1', 'state' => 'accepted', 'decidedAt' => 1]);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1', 'acceptedQuantity' => '1']);

        self::assertSame('tooMuch', $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '2'], self::NOW)->key);
        self::assertTrue($this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '1'], self::NOW)->done);
    }

    public function testAboUndGutscheinZeilenNicht(): void
    {
        $this->connection->update('tl_consho_order_item', ['subscriptionRule' => 3], ['id' => 1]);
        self::assertSame('subscriptionLine', $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '2'], self::NOW)->key);

        $this->connection->update('tl_consho_order_item', ['subscriptionRule' => 0, 'kind' => 'voucher'], ['id' => 1]);
        self::assertSame('voucherLine', $this->processing()->decide(self::COMPLAINT, 'reduction', [10 => '5'], self::NOW)->key);
        self::assertNull($this->complaint()['decidedAt']);
    }

    // ── Replacement (RK-5) ────────────────────────────────────────────────

    /**
     * S1: no quantities of its own — the cap is the complained quantity.
     * Nothing is refunded and the claim stays.
     */
    public function testAustauschFestlegen(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '1'], self::NOW);

        $result = $this->processing()->decide(self::COMPLAINT, 'replacement', [], self::NOW + 1);

        self::assertTrue($result->done, $result->key);
        self::assertSame(['inProgress', 'replacement', self::NOW + 1], [$this->complaint()['state'], $this->complaint()['remedy'], (int) $this->complaint()['decidedAt']]);
        self::assertSame(['0', '0'], [(string) (float) $this->item(10)['rescindedQuantity'], (string) (float) $this->item(11)['rescindedQuantity']]);
        self::assertTrue(BigDecimal::of('100')->isEqualTo($this->balance()->claim));
        self::assertSame('notDecided', $this->processing()->refund(self::COMPLAINT, '0', self::NOW + 2)->key);
    }

    public function testAustauschNurFuerWare(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'digital'], ['id' => 2]);

        $result = $this->processing()->decide(self::COMPLAINT, 'replacement', [], self::NOW);

        self::assertSame(['notPhysical', ['Rucksack']], [$result->key, $result->params]);
        self::assertNull($this->complaint()['decidedAt']);
    }

    /**
     * U8: the replacement names what is sent again, at most the complained
     * quantity; a line at 0 is not replaced and need not be goods.
     */
    public function testErsatzMitMengenJePosition(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'digital'], ['id' => 2]);

        $result = $this->processing()->decide(self::COMPLAINT, 'replacement', [10 => '1', 11 => '0'], self::NOW);

        self::assertTrue($result->done, $result->key);
        self::assertSame(['1', '0'], [(string) (float) $this->item(10)['replacementQuantity'], (string) (float) $this->item(11)['replacementQuantity']]);

        self::assertSame('tooMuchReplacement', $this->processing()->decide(self::COMPLAINT, 'replacement', [10 => '3'], self::NOW + 1)->key);
        self::assertSame('nothingDecided', $this->processing()->decide(self::COMPLAINT, 'replacement', [10 => '0'], self::NOW + 1)->key);
        self::assertSame('invalidQuantity', $this->processing()->decide(self::COMPLAINT, 'replacement', [10 => '-1'], self::NOW + 1)->key);

        // Another remedy clears it.
        $this->processing()->decide(self::COMPLAINT, 'repair', [], self::NOW + 2);
        self::assertSame('0', (string) (float) $this->item(10)['replacementQuantity']);
    }

    /**
     * U9: withdrawn while nothing was carried out — the complaint is open
     * again, the lines are cleared and a replacement draft goes with it.
     */
    public function testEntscheidungZuruecknehmen(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'replacement', [10 => '2'], self::NOW);
        $this->connection->insert('tl_consho_delivery', ['id' => 40, 'pid' => self::ORDER, 'complaint' => self::COMPLAINT]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 40, 'orderItem' => 1, 'quantity' => '2']);

        self::assertTrue($this->processing()->withdrawable(self::COMPLAINT));
        $result = $this->processing()->withdraw(self::COMPLAINT, self::NOW + 1);

        self::assertTrue($result->done, $result->key);
        self::assertSame(['open', ''], [$this->complaint()['state'], $this->complaint()['remedy']]);
        self::assertNull($this->complaint()['decidedAt']);
        self::assertSame('0', (string) (float) $this->item(10)['replacementQuantity']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_delivery'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_delivery_item'));
        self::assertSame('nothingToWithdraw', $this->processing()->withdraw(self::COMPLAINT, self::NOW + 2)->key);

        // A rescission withdrawn: the claim is whole again.
        $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '1'], self::NOW + 3);
        self::assertTrue($this->processing()->withdraw(self::COMPLAINT, self::NOW + 4)->done);
        self::assertSame('0', (string) (float) $this->item(10)['rescindedQuantity']);
        self::assertTrue(BigDecimal::of('100')->isEqualTo($this->balance()->claim));
    }

    /**
     * U9: once carried out — a replacement reported, a return recorded, a
     * refund booked — the decision stays.
     */
    public function testZuruecknehmenNichtNachDerAusfuehrung(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'replacement', [10 => '1'], self::NOW);
        $this->connection->insert('tl_consho_delivery', ['id' => 40, 'pid' => self::ORDER, 'complaint' => self::COMPLAINT, 'reportedAt' => self::NOW]);
        self::assertFalse($this->processing()->withdrawable(self::COMPLAINT));
        self::assertSame('replacementShipped', $this->processing()->withdraw(self::COMPLAINT, self::NOW + 1)->key);
        $this->connection->delete('tl_consho_delivery', ['id' => 40]);

        $this->connection->update('tl_consho_complaint_item', ['returnedQuantity' => '1'], ['id' => 10]);
        self::assertSame('returnAlready', $this->processing()->withdraw(self::COMPLAINT, self::NOW + 1)->key);
        $this->connection->update('tl_consho_complaint_item', ['returnedQuantity' => '0'], ['id' => 10]);

        $this->connection->update('tl_consho_complaint', ['refundedAt' => self::NOW], ['id' => self::COMPLAINT]);
        self::assertSame('alreadyRefunded', $this->processing()->withdraw(self::COMPLAINT, self::NOW + 1)->key);
        self::assertSame('replacement', $this->complaint()['remedy']);
    }

    /**
     * S1: once a replacement delivery exists, draft or reported, the decision
     * stands — deleting the draft frees it again.
     */
    public function testMitErsatzlieferungNichtMehrAenderbar(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'replacement', [], self::NOW);
        $this->connection->insert('tl_consho_delivery', ['id' => 40, 'pid' => self::ORDER, 'complaint' => self::COMPLAINT]);

        self::assertSame('replacementExists', $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '1'], self::NOW + 1)->key);
        self::assertSame('replacementExists', $this->processing()->decide(self::COMPLAINT, 'replacement', [], self::NOW + 1)->key);
        self::assertSame('replacement', $this->complaint()['remedy']);

        $this->connection->delete('tl_consho_delivery', ['id' => 40]);
        self::assertTrue($this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '1'], self::NOW + 2)->done);
    }

    /**
     * @return iterable<string, array{string, array<int, string>, string}>
     */
    public static function falscheEntscheidungen(): iterable
    {
        yield 'unbekannte Abwicklung' => ['exchange', [10 => '1'], 'unknownRemedy'];
        yield 'nichts' => ['rescission', [10 => '0'], 'nothingDecided'];
        yield 'fremde Zeile' => ['rescission', [99 => '1'], 'invalidQuantity'];
        yield 'negativ' => ['rescission', [10 => '-1'], 'invalidQuantity'];
        yield 'keine Zahl' => ['reduction', [10 => 'x'], 'invalidReduction'];
        yield 'mehr als der Wert' => ['reduction', [10 => '60.01'], 'tooMuchReduction'];
    }

    /**
     * @param array<int, string> $values
     *
     * @dataProvider falscheEntscheidungen
     */
    public function testFalscheEntscheidungenAendernNichts(string $remedy, array $values, string $key): void
    {
        self::assertSame($key, $this->processing()->decide(self::COMPLAINT, $remedy, $values, self::NOW)->key);
        self::assertNull($this->complaint()['decidedAt']);
    }

    public function testMinderungFestlegenUndNeuFestlegen(): void
    {
        self::assertTrue($this->processing()->decide(self::COMPLAINT, 'reduction', [10 => '12,5'], self::NOW)->done);
        self::assertTrue(BigDecimal::of('12.5')->isEqualTo((string) $this->item(10)['reduction']));
        self::assertNull($this->item(11)['reduction']);
        self::assertTrue(BigDecimal::of('87.5')->isEqualTo($this->balance()->claim));

        // Decided anew before the refund: its own reduction does not count
        // against the worth, and a switch clears the old values.
        self::assertTrue($this->processing()->decide(self::COMPLAINT, 'reduction', [10 => '60'], self::NOW + 1)->done);
        self::assertTrue($this->processing()->decide(self::COMPLAINT, 'rescission', [11 => '1'], self::NOW + 2)->done);
        self::assertNull($this->item(10)['reduction']);
        self::assertTrue(BigDecimal::of('65')->isEqualTo($this->balance()->claim));
    }

    // ── Revocation (RK-E1, L9) ────────────────────────────────────────────

    public function testVollstaendigZurueckgetretenSperrtDienstleistungUndDigitales(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'digital'], ['id' => 1]);
        $this->connection->update('tl_consho_order_item', ['kind' => 'service'], ['id' => 2]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 40, 'pid' => self::ORDER, 'orderItem' => 1]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 41, 'pid' => self::ORDER, 'orderItem' => 2]);

        $revocations = $this->createMock(EntitlementRevocations::class);
        $revocations->expects(self::once())->method('revoked')->with([41]);

        // One of two downloads back: still usable. The service in full: no longer.
        self::assertTrue($this->processing(revocations: $revocations)->decide(self::COMPLAINT, 'rescission', [10 => '1', 11 => '1'], self::NOW)->done);

        self::assertSame([40 => 0, 41 => 1], array_map(intval(...), $this->connection->fetchAllKeyValue('SELECT id, revoked FROM tl_consho_entitlement ORDER BY id')));

        // What was locked stays locked: a new decision keeping the service is refused.
        self::assertSame('revokedLine', $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '1'], self::NOW + 1)->key);
        self::assertSame('revokedLine', $this->processing()->decide(self::COMPLAINT, 'reduction', [10 => '5'], self::NOW + 1)->key);
        self::assertTrue($this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '2', 11 => '1'], self::NOW + 1)->done);
        self::assertSame([40 => 1, 41 => 1], array_map(intval(...), $this->connection->fetchAllKeyValue('SELECT id, revoked FROM tl_consho_entitlement ORDER BY id')));
    }

    /**
     * U9: a line a rescission made unusable counts as carried out — the
     * decision can no longer be withdrawn (the lock is not lifted, RK-4).
     */
    public function testNachEinerSperreNichtZuruecknehmbar(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'service'], ['id' => 2]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 41, 'pid' => self::ORDER, 'orderItem' => 2]);
        self::assertTrue($this->processing()->decide(self::COMPLAINT, 'rescission', [11 => '1'], self::NOW)->done);

        self::assertFalse($this->processing()->withdrawable(self::COMPLAINT));
        self::assertSame('revokedLine', $this->processing()->withdraw(self::COMPLAINT, self::NOW + 1)->key);
        self::assertSame('rescission', $this->complaint()['remedy']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT revoked FROM tl_consho_entitlement WHERE id = 41'));
    }

    public function testKoerperlicheWareSperrtNichts(): void
    {
        $this->connection->insert('tl_consho_entitlement', ['id' => 40, 'pid' => self::ORDER, 'orderItem' => 1]);

        $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '2'], self::NOW);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT revoked FROM tl_consho_entitlement WHERE id = 40'));
    }

    // ── Return (3.4) ──────────────────────────────────────────────────────

    public function testRuecksendungVorgabeNichtInsLager(): void
    {
        $stock = $this->createMock(StockReturn::class);
        $stock->expects(self::exactly(2))->method('forComplaintItem')->willReturnCallback(function (int $rowId, bool $restock): string {
            self::assertSame(10 === $rowId, $restock, 'nur die angehakte Zeile ins Lager');

            return '0';
        });

        $result = $this->processing($stock)->recordReturn(self::COMPLAINT, self::NOW - 10, [10 => '1'], self::NOW, [10 => true]);

        self::assertTrue($result->done);
        self::assertSame('1', $this->item(10)['returnedQuantity']);
        self::assertSame(self::NOW - 10, (int) $this->complaint()['returnReceivedAt']);
        self::assertSame('invalidQuantity', $this->processing()->recordReturn(self::COMPLAINT, 0, [10 => '3'], self::NOW)->key);
    }

    // ── Repair (RK-6, A1) ─────────────────────────────────────────────────

    /**
     * No quantities, no money: the claim stays, nothing to refund, and an
     * earlier decision's values are cleared. Any line but a voucher.
     */
    public function testVerbesserungFestlegen(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'service'], ['id' => 2]);
        $this->processing()->decide(self::COMPLAINT, 'reduction', [10 => '5'], self::NOW);

        $result = $this->processing()->decide(self::COMPLAINT, 'repair', [], self::NOW + 1);

        self::assertTrue($result->done, $result->key);
        self::assertSame(['inProgress', 'repair', self::NOW + 1], [$this->complaint()['state'], $this->complaint()['remedy'], (int) $this->complaint()['decidedAt']]);
        self::assertNull($this->item(10)['reduction']);
        self::assertTrue(BigDecimal::of('100')->isEqualTo($this->balance()->claim));
        self::assertSame('notDecided', $this->processing()->refund(self::COMPLAINT, '0', self::NOW + 2)->key);

        // Changed until the complaint is closed.
        self::assertTrue($this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '1'], self::NOW + 3)->done);
    }

    public function testVerbesserungNichtFuerGutscheine(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'voucher'], ['id' => 2]);

        $result = $this->processing()->decide(self::COMPLAINT, 'repair', [], self::NOW);

        self::assertSame(['voucherLine', ['Rucksack']], [$result->key, $result->params]);
        self::assertNull($this->complaint()['decidedAt']);
    }

    /**
     * Goods in for repair go back to the customer: a tick for stock does not
     * count, and one set before the switch is undone.
     */
    public function testRuecksendungZurVerbesserungNieInsLager(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'repair', [], self::NOW);
        $this->connection->update('tl_consho_complaint_item', ['restock' => 1], ['id' => 11]);

        $stock = $this->createMock(StockReturn::class);
        $stock->expects(self::exactly(2))->method('forComplaintItem')->willReturnCallback(static function (int $rowId, bool $restock): string {
            self::assertFalse($restock);

            return '0';
        });

        self::assertTrue($this->processing($stock)->recordReturn(self::COMPLAINT, self::NOW, [10 => '1'], self::NOW, [10 => true])->done);
    }

    // ── Rejection (RK-6, A2/A3) ───────────────────────────────────────────

    /**
     * After a decision too: its values go, the claim is whole again, the
     * complaint is final, and the customer has the reason verbatim.
     */
    public function testAblehnenNachEntscheidung(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '1'], self::NOW);

        $result = $this->processing()->reject(self::COMPLAINT, "  Kein Mangel erkennbar.\n", self::NOW + 1);

        self::assertSame(['rejected', ['WEB-00077-R1']], [$result->key, $result->params]);
        $complaint = $this->complaint();
        self::assertSame(['rejected', '', 'Kein Mangel erkennbar.', self::NOW + 1, self::NOW + 1], [$complaint['state'], $complaint['remedy'], $complaint['rejectReason'], (int) $complaint['decidedAt'], (int) $complaint['resolvedAt']]);
        self::assertSame('0', (string) (float) $this->item(10)['rescindedQuantity']);
        self::assertTrue(BigDecimal::of('100')->isEqualTo($this->balance()->claim));
        self::assertSame([['complaintRejected', self::ORDER, 'WEB-00077-R1', 'Kein Mangel erkennbar.', 'https://shop.test/x']], $this->mails);

        // Final.
        self::assertSame('alreadyDecided', $this->processing()->decide(self::COMPLAINT, 'repair', [], self::NOW + 2)->key);
        self::assertSame('alreadyDecided', $this->processing()->reject(self::COMPLAINT, 'Noch einmal.', self::NOW + 2)->key);
    }

    /**
     * The whole way with the real stock: the purchase took two hiking poles,
     * the rescission takes both back, the return puts the ticked ones into
     * stock, a correction books only the difference, and the refund follows.
     */
    public function testRuecktrittRuecksendungLagerUndErstattung(): void
    {
        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_stock', 'tl_consho_stock_movement'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product', ['id' => 1, 'stockTracked' => 1]);
        (new StockRows($this->connection))->sync(1, 100);
        $this->connection->update('tl_consho_stock', ['quantity' => '8.0000'], ['article' => 1]);
        $this->connection->update('tl_consho_order_item', ['product' => 1, 'stockBooked' => '2.0000'], ['id' => 1]);
        $stock = new StockReturn($this->connection, new StockBooking($this->connection));
        $level = fn (): string => StockBooking::scaled($this->connection->fetchOne('SELECT quantity FROM tl_consho_stock WHERE article = 1'));

        self::assertTrue($this->processing($stock)->decide(self::COMPLAINT, 'rescission', [10 => '2'], self::NOW)->done);
        self::assertTrue($this->processing($stock)->recordReturn(self::COMPLAINT, self::NOW, [10 => '2'], self::NOW + 1, [10 => true], 5)->done);
        self::assertSame('10.0000', $level());

        // One of the two turns out damaged: corrected, one goes out again.
        self::assertTrue($this->processing($stock)->recordReturn(self::COMPLAINT, 0, [10 => '1'], self::NOW + 2, [10 => true], 5)->done);
        self::assertSame('9.0000', $level());
        self::assertSame('1', (string) (float) $this->item(10)['restockedQuantity']);

        $movements = $this->connection->fetchAllAssociative("SELECT delta, orderId, user FROM tl_consho_stock_movement WHERE reason = 'return' ORDER BY id");
        self::assertSame([['2', 7, 5], ['-1', 7, 5]], array_map(static fn (array $m): array => [(string) (float) $m['delta'], (int) $m['orderId'], (int) $m['user']], $movements));

        self::assertTrue($this->processing($stock)->refund(self::COMPLAINT, '0', self::NOW + 3)->done);
        self::assertTrue(BigDecimal::of('-60')->isEqualTo((string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE amount < 0')));
        self::assertSame('9.0000', $level(), 'die Erstattung bucht kein Lager');
    }

    /** A rejection lifts a replacement's quantities too: nothing is to be sent. */
    public function testAblehnenNachErsatzLeertDieErsatzmengen(): void
    {
        self::assertTrue($this->processing()->decide(self::COMPLAINT, 'replacement', [10 => '1'], self::NOW)->done);

        self::assertTrue($this->processing()->reject(self::COMPLAINT, 'Kein Mangel.', self::NOW + 1)->done);

        self::assertSame('0', (string) (float) $this->item(10)['replacementQuantity']);
    }

    public function testAblehnenOhneMail(): void
    {
        $this->mailSent = false;

        self::assertSame('rejectedNoMail', $this->processing()->reject(self::COMPLAINT, 'Frist abgelaufen.', self::NOW)->key);
        self::assertSame('rejected', $this->complaint()['state']);
    }

    /**
     * @return iterable<string, array{\Closure(Connection): mixed, string, string}>
     */
    public static function nichtAblehnbar(): iterable
    {
        yield 'ohne Begründung' => [static fn (Connection $c): null => null, " \n", 'reasonMissing'];
        yield 'erstattet' => [static fn (Connection $c): int|string => $c->update('tl_consho_complaint', ['refundedAt' => 1], ['id' => self::COMPLAINT]), 'x', 'alreadyRefunded'];
        yield 'erledigt' => [static fn (Connection $c): int|string => $c->update('tl_consho_complaint', ['state' => 'resolved'], ['id' => self::COMPLAINT]), 'x', 'alreadyDecided'];
        yield 'Ersatzlieferung' => [static fn (Connection $c): int|string => $c->insert('tl_consho_delivery', ['pid' => self::ORDER, 'complaint' => self::COMPLAINT]), 'x', 'replacementExists'];
    }

    /**
     * @param \Closure(Connection): mixed $given
     *
     * @dataProvider nichtAblehnbar
     */
    public function testNichtAblehnbar(\Closure $given, string $reason, string $key): void
    {
        $given($this->connection);

        self::assertSame($key, $this->processing()->reject(self::COMPLAINT, $reason, self::NOW)->key);
        self::assertNotSame('rejected', $this->complaint()['state']);
        self::assertSame([], $this->mails);
    }

    /** A2: what a rescission made unusable cannot be handed back. */
    public function testAblehnenNichtNachGesperrterNutzung(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'service'], ['id' => 2]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 41, 'pid' => self::ORDER, 'orderItem' => 2]);
        $this->processing(revocations: $this->createStub(EntitlementRevocations::class))->decide(self::COMPLAINT, 'rescission', [11 => '1'], self::NOW);

        self::assertSame('revokedLine', $this->processing()->reject(self::COMPLAINT, 'x', self::NOW + 1)->key);
        self::assertSame('rescission', $this->complaint()['remedy']);
    }

    /** Rejected goods are the customer's: what went into stock goes out again. */
    public function testAblehnenBuchtLagerZurueck(): void
    {
        $stock = $this->createMock(StockReturn::class);
        $stock->expects(self::exactly(2))->method('forComplaintItem')->willReturnCallback(static function (int $rowId, bool $restock): string {
            self::assertFalse($restock);

            return '0';
        });

        self::assertTrue($this->processing($stock)->reject(self::COMPLAINT, 'x', self::NOW)->done);
    }

    // ── Closing (RK-6, A4) ────────────────────────────────────────────────

    public function testVerbesserungAbschliessen(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'repair', [], self::NOW);

        $result = $this->processing()->close(self::COMPLAINT, self::NOW + 1);

        self::assertSame(['closed', ['WEB-00077-R1']], [$result->key, $result->params]);
        self::assertSame(['resolved', self::NOW + 1], [$this->complaint()['state'], (int) $this->complaint()['resolvedAt']]);
        self::assertSame([], $this->mails, 'keine Mail');

        // Final.
        self::assertSame('alreadyDecided', $this->processing()->close(self::COMPLAINT, self::NOW + 2)->key);
        self::assertSame('alreadyDecided', $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '1'], self::NOW + 2)->key);
    }

    public function testOhneAbwicklungAbschliessen(): void
    {
        self::assertTrue($this->processing()->close(self::COMPLAINT, self::NOW)->done);
        self::assertSame(['resolved', ''], [$this->complaint()['state'], $this->complaint()['remedy']]);
    }

    /**
     * U8: whatever was decided for replacement has to be sent, so a partial
     * replacement is never closed by hand. A replacement is resolved by the report of its last quantity, never by
     * hand, whether a delivery was reported or not.
     */
    public function testEinErsatzLaesstSichNichtVonHandAbschliessen(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'replacement', [], self::NOW);
        self::assertSame('closedByReplacement', $this->processing()->close(self::COMPLAINT, self::NOW + 1)->key);

        $this->connection->insert('tl_consho_delivery', ['id' => 40, 'pid' => self::ORDER, 'complaint' => self::COMPLAINT, 'reportedAt' => self::NOW]);
        self::assertSame('closedByReplacement', $this->processing()->close(self::COMPLAINT, self::NOW + 2)->key);
        self::assertSame('inProgress', $this->complaint()['state']);
    }

    public function testRuecktrittUndMinderungSchliesstDieErstattung(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'reduction', [10 => '5'], self::NOW);

        self::assertSame('closedByRefund', $this->processing()->close(self::COMPLAINT, self::NOW + 1)->key);
        self::assertSame('inProgress', $this->complaint()['state']);
    }

    // ── Refund ────────────────────────────────────────────────────────────

    /**
     * The customer's return postage is no part of the refund: the operator
     * settles it outside the shop.
     */
    /**
     * Paid with 40 voucher and 60 card: the refund of a rescission goes onto
     * the voucher first, as a withdrawal's does (the same means of payment).
     * Proposal, payment, voucher balance and mail agree.
     */
    public function testRuecktrittErstattetZuerstAufDenGutschein(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_voucher');
        DcaSchema::create($this->connection, 'tl_consho_voucher_booking');
        $this->connection->update('tl_consho_payment', ['amount' => '60.0000'], ['pid' => self::ORDER]);
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '40.0000', 'method' => 'Gutschein …AAAA', 'reference' => 'voucher:1']);
        $this->connection->insert('tl_consho_voucher', ['id' => 1, 'code' => 'AAAABBBBCCCCAAAA', 'shop' => 1, 'currency' => 'EUR', 'faceValue' => '50', 'balance' => '50']);
        $ledger = new VoucherLedger($this->connection);
        $ledger->book(1, '-40', VoucherBookingType::Redeem, self::NOW - 100, self::ORDER, 2);
        $vouchers = new VoucherReturn($this->connection, $ledger);

        $this->processing(vouchers: $vouchers)->decide(self::COMPLAINT, 'rescission', [10 => '1'], self::NOW);
        // The page shows "Mit Gutschein bezahlt": what the vouchers paid, the cap of what goes back onto them.
        self::assertTrue(BigDecimal::of('40')->isEqualTo($this->processing(vouchers: $vouchers)->proposal(self::COMPLAINT)['vouchers']));

        $result = $this->processing(vouchers: $vouchers)->refund(self::COMPLAINT, '0', self::NOW);

        self::assertTrue($result->done, $result->key);
        $back = $this->connection->fetchAllAssociative('SELECT amount, reference FROM tl_consho_payment WHERE amount < 0 ORDER BY id');
        self::assertCount(1, $back, 'nichts an die Karte');
        self::assertTrue(BigDecimal::of('-30')->isEqualTo((string) $back[0]['amount']));
        self::assertSame('voucher:1', $back[0]['reference']);
        self::assertTrue(BigDecimal::of('40')->isEqualTo((string) $this->connection->fetchOne('SELECT balance FROM tl_consho_voucher WHERE id = 1')));
        self::assertSame((int) $this->connection->fetchOne('SELECT id FROM tl_consho_payment WHERE amount < 0'), (int) $this->complaint()['refundPayment']);
        self::assertStringContainsString('30,00', (string) end($this->mails[0]), 'die Mail nennt, was auf den Gutschein ging');
        self::assertTrue($this->balance()->paymentSettled());
    }

    public function testRuecktrittErstatten(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '1'], self::NOW);

        self::assertSame('invalidShipping', $this->processing()->refund(self::COMPLAINT, '6', self::NOW)->key);

        $result = $this->processing()->refund(self::COMPLAINT, '0', self::NOW);

        self::assertTrue($result->done, $result->key);
        self::assertSame('correct', $this->documentCalls[0][0]);
        self::assertCount(1, $this->documentCalls[0][1]);
        self::assertSame([$this->complaint()['number'], 'complaint'], [$this->documentCalls[0][2], $this->documentCalls[0][3]], 'der Beleg nennt die Reklamation');
        self::assertTrue(BigDecimal::of('-30')->isEqualTo((string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE amount < 0')));

        $complaint = $this->complaint();
        self::assertSame('resolved', $complaint['state']);
        self::assertSame(self::NOW, (int) $complaint['resolvedAt']);
        self::assertTrue(BigDecimal::of('30')->isEqualTo((string) $complaint['refundAmount']));
        self::assertSame(55, (int) $complaint['refundDocument']);
        self::assertSame([OrderEvent::WithdrawalRefunded], $this->events);

        // The customer's mail: the complaint, the remedy, the payout.
        self::assertCount(1, $this->mails);
        [$orderId, $number, $remedy, $payout, , , $viewUrl] = $this->mails[0];
        self::assertSame([self::ORDER, $complaint['number'], 'rescission', 'https://shop.test/x'], [$orderId, $number, $remedy, $viewUrl]);
        self::assertStringContainsString('30,00', $payout);

        // 100 paid, 30 back, 70 owed: settled.
        self::assertTrue($this->balance()->paymentSettled());

        self::assertSame('alreadyRefunded', $this->processing()->refund(self::COMPLAINT, '0', self::NOW)->key);
        self::assertSame('alreadyDecided', $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '2'], self::NOW)->key);
    }

    public function testMinderungErstattenOhneVersand(): void
    {
        self::assertSame('notDecided', $this->processing()->refund(self::COMPLAINT, '0', self::NOW)->key);

        $this->processing()->decide(self::COMPLAINT, 'reduction', [10 => '10'], self::NOW);

        self::assertSame('invalidShipping', $this->processing()->refund(self::COMPLAINT, '1', self::NOW)->key);

        self::assertTrue($this->processing()->refund(self::COMPLAINT, '0', self::NOW)->done);

        self::assertSame('correct', $this->documentCalls[0][0]);
        self::assertSame(['Wanderstock', '1', '-10.0000'], [$this->documentCalls[0][1][0]['productName'], $this->documentCalls[0][1][0]['quantity'], $this->documentCalls[0][1][0]['lineTotal']]);
        self::assertTrue(BigDecimal::of('-10')->isEqualTo((string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE amount < 0')));
        self::assertTrue($this->balance()->paymentSettled());
        self::assertSame('reduction', $this->mails[0][2]);
    }

    public function testOhneMailIstDieErstattungTrotzdemGebucht(): void
    {
        $this->mailSent = false;
        $this->processing()->decide(self::COMPLAINT, 'reduction', [10 => '10'], self::NOW);

        $result = $this->processing()->refund(self::COMPLAINT, '0', self::NOW);

        self::assertSame([true, 'refundedNoMail'], [$result->done, $result->key]);
        self::assertSame('resolved', $this->complaint()['state']);
    }

    /**
     * L6: everything in one step cancels the invoice.
     */
    public function testAllesZurueckStorniertDieRechnung(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '2', 11 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::COMPLAINT, '5', self::NOW)->done);

        self::assertSame([['cancel', 14]], $this->documentCalls);
        self::assertTrue(BigDecimal::of('-100')->isEqualTo((string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE amount < 0')));
        self::assertTrue(BigDecimal::of('100')->isEqualTo((string) $this->complaint()['refundAmount']));
    }

    /**
     * L6: an accepted withdrawal not yet refunded is booked on its own — the
     * rescission does not cancel the invoice in its stead, even when both
     * together take everything back.
     */
    public function testEinOffenerWiderrufVerhindertDasStorno(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => self::ORDER, 'number' => 'WEB-00077-W1', 'state' => 'accepted', 'decidedAt' => 1]);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1', 'acceptedQuantity' => '1']);
        self::assertTrue($this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '1', 11 => '1'], self::NOW)->done);

        self::assertTrue($this->processing()->refund(self::COMPLAINT, '5', self::NOW)->done);

        self::assertSame('correct', $this->documentCalls[0][0]);
    }

    /**
     * L6: everything back but the delivery costs kept is no cancellation —
     * the shipping line stays on the invoice, so it is corrected.
     */
    public function testAllesZurueckOhneVersandKorrigiert(): void
    {
        $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '2', 11 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::COMPLAINT, '0', self::NOW)->done);

        self::assertSame('correct', $this->documentCalls[0][0]);
        self::assertTrue(BigDecimal::of('-95')->isEqualTo((string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE amount < 0')));
    }

    /**
     * Only money that came in goes back: of 100.00 owed, 95.00 were paid. A
     * reduction of 10.00 lowers the claim to 90.00, so 5.00 are paid out and
     * the rest only cancels what is still owed.
     */
    public function testMinderungBeiTeilzahlungZahltNurDasZuvielBezahlte(): void
    {
        $this->connection->update('tl_consho_payment', ['amount' => '95.0000'], ['pid' => self::ORDER]);
        $this->processing()->decide(self::COMPLAINT, 'reduction', [10 => '10'], self::NOW);

        self::assertTrue($this->processing()->refund(self::COMPLAINT, '0', self::NOW)->done);

        self::assertTrue(BigDecimal::of('-5')->isEqualTo((string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE amount < 0')));
        self::assertTrue($this->balance()->paymentSettled());
    }

    public function testEineOffeneMinderungVerhindertDasStorno(): void
    {
        $this->connection->insert('tl_consho_complaint', ['id' => 6, 'pid' => self::ORDER, 'number' => 'WEB-00077-R2', 'remedy' => 'reduction', 'decidedAt' => self::NOW - 100, 'state' => 'inProgress']);
        $this->connection->insert('tl_consho_complaint_item', ['pid' => 6, 'orderItem' => 2, 'quantity' => '1', 'reduction' => '5.0000']);
        $this->processing()->decide(self::COMPLAINT, 'rescission', [10 => '2', 11 => '1'], self::NOW);

        self::assertTrue($this->processing()->refund(self::COMPLAINT, '5', self::NOW)->done);

        self::assertSame('correct', $this->documentCalls[0][0]);
        // The backpack goes back less the 5.00 it was reduced by: 60 + 30 + 5.
        self::assertTrue(BigDecimal::of('-95')->isEqualTo((string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE amount < 0')));
    }

    // ── Proposal ──────────────────────────────────────────────────────────

    public function testVorschlagJeZeile(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => self::ORDER, 'number' => 'WEB-00077-W1', 'state' => 'accepted', 'decidedAt' => 1]);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1', 'acceptedQuantity' => '1']);
        $this->processing()->decide(self::COMPLAINT, 'reduction', [11 => '5'], self::NOW);

        $proposal = $this->processing()->proposal(self::COMPLAINT);

        self::assertTrue(BigDecimal::of('1')->isEqualTo($proposal['lines'][10]['quantity']));
        self::assertTrue(BigDecimal::of('30')->isEqualTo($proposal['lines'][10]['worth']));
        // Its own reduction does not count against the worth.
        self::assertTrue(BigDecimal::of('35')->isEqualTo($proposal['lines'][11]['worth']));
        self::assertTrue(BigDecimal::of('5')->isEqualTo($proposal['goods']));
        self::assertTrue(BigDecimal::of('5')->isEqualTo($proposal['shippingLeft']));
    }

    /**
     * @return array<string, mixed>
     */
    private function complaint(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_complaint WHERE id = ?', [self::COMPLAINT]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_complaint_item WHERE id = ?', [$id]);
        self::assertIsArray($row);

        foreach (['rescindedQuantity', 'returnedQuantity'] as $field) {
            $row[$field] = (string) BigDecimal::of((string) $row[$field])->strippedOfTrailingZeros();
        }

        return $row;
    }

    private function balance(): OrderBalance
    {
        $balance = $this->reader()->of(self::ORDER);
        self::assertNotNull($balance);

        return $balance;
    }

    private function reader(): OrderBalanceReader
    {
        return new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator());
    }

    private function processing(StockReturn|null $stock = null, EntitlementRevocations|null $revocations = null, VoucherReturn|null $vouchers = null): ComplaintProcessing
    {
        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $row = function (int $id): array|null {
            $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$id]);

            return false === $row ? null : array_map(static fn ($v): string => (string) $v, $row);
        };
        $orders->method('lockOrder')->willReturnCallback($row);
        $orders->method('order')->willReturnCallback($row);

        $documentRows = $this->createStub(DocumentRepositoryInterface::class);
        $documentRows->method('findIdByOrderAndType')->willReturn(14);

        $documents = $this->createStub(DocumentIssuerInterface::class);
        $documents->method('correct')->willReturnCallback(function (int $invoiceId, array $lines, string $reference, array $prepaid = [], string $about = 'withdrawal'): int {
            $this->documentCalls[] = ['correct', $lines, $reference, $about];

            return 55;
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

        $mail = $this->createStub(OrderEventMail::class);
        $mail->method('complaintRefundBooked')->willReturnCallback(function (mixed ...$args): bool {
            $this->mails[] = $args;

            return $this->mailSent;
        });
        $mail->method('complaintRejected')->willReturnCallback(function (mixed ...$args): bool {
            $this->mails[] = ['complaintRejected', ...$args];

            return $this->mailSent;
        });
        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/x');

        $payoutOrders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $payoutOrders->method('lockOrder')->willReturn(['id' => (string) self::ORDER]);
        $methods = new class implements PaymentMethodLookupInterface {
            public function byId(int $methodId): PaymentMethod|null
            {
                return null;
            }
        };

        return new ComplaintProcessing(
            $this->connection,
            $orders,
            $documentRows,
            $documents,
            $this->createStub(DocumentArchiverInterface::class),
            new DocumentPayloadBuilder(),
            $this->reader(),
            $statuses,
            new RefundPayout($this->connection, $payoutOrders, $methods, new PaymentHandlers(new ServiceLocator([]))),
            $stock,
            $vouchers,
            $revocations,
            $mail,
            new MoneyFormatter(),
            $links,
        );
    }
}

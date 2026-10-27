<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Checkout\ShippingTaxSplitter;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChange;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeBlock;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeOption;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeOptions;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRefused;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\WithdrawalCredit;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationResult;
use Webwerkwien\ContaoConshoBundle\Payment\OrderView;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustment;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustmentOrigin;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentContext;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStart;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;

/**
 * 7.5 "Change payment method" (C23, C-10) — against an in-memory repository
 * and a mocked connection: the ORDER inside the transaction is checked, and
 * SQLite has no FOR UPDATE (like OrderPlacerTest).
 */
class PaymentChangeTest extends TestCase
{
    private Connection&MockObject $connection;

    /** @var list<string> */
    private array $log = [];

    private bool $active = false;

    private InMemoryPaymentChangeRepository $orders;

    protected function setUp(): void
    {
        $this->log = [];
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('beginTransaction')->willReturnCallback(function (): void {
            $this->log[] = 'begin';
            $this->active = true;
        });
        $this->connection->method('commit')->willReturnCallback(function (): void {
            $this->log[] = 'commit';
            $this->active = false;
        });
        $this->connection->method('rollBack')->willReturnCallback(function (): void {
            $this->log[] = 'rollback';
            $this->active = false;
        });
        $this->connection->method('isTransactionActive')->willReturnCallback(fn (): bool => $this->active);

        $this->orders = new InMemoryPaymentChangeRepository();
        // Its writes land in the same log as the transaction and the
        // documents, so the ORDER of all of them is what the tests see.
        $this->orders->log = &$this->log;
    }

    public function testWechselStorniertDieRechnungUndStelltEineNeueAus(): void
    {
        $this->orders->attempts = [new PaymentAttempt(9, 1, 2, 'prepayment', AttemptState::Open, '12.0000', 'EUR', '', '', '')];

        $archived = [];
        $changed = $this->change($archived)->change(1, 3, 'de', 1_790_000_000);

        self::assertSame(55, $changed->cancellationId);
        self::assertSame(56, $changed->invoiceId);

        // Cancellation BEFORE the rewrite, new invoice AFTER the header — it reads
        // the payment method from the order row (DbalDocumentSource), and before
        // the header the old bank would still stand there (C-10).
        // All in one transaction; archiving only happens once nothing can
        // roll back any more.
        self::assertSame(['begin', 'cancel-invoice', 'replace-lines', 'update-order', 'issue-invoice', 'commit'], $this->log);
        self::assertSame([55, 56], $archived);

        self::assertSame([9], $this->orders->cancelledAttempts);
        self::assertSame(3, $this->orders->order['paymentMethod']);
        self::assertSame('Vorkasse Bank B', $this->orders->order['paymentMethodTitle']);
        self::assertSame('<p>Bank B</p>', $this->orders->order['paymentInstructions']);

        // Without an amount the totals stay as they are — but recounted, not
        // carried over.
        self::assertSame('10.00', $this->orders->order['netTotal']);
        self::assertSame('2.00', $this->orders->order['taxTotal']);
        self::assertSame('12.00', $this->orders->order['grandTotal']);
    }

    public function testOhneRechnungEntstehtKeinStorno(): void
    {
        $this->orders->standingInvoice = null;

        $changed = $this->change()->change(1, 3, 'de', 1_790_000_000);

        self::assertNull($changed->cancellationId);
        self::assertSame(['begin', 'replace-lines', 'update-order', 'issue-invoice', 'commit'], $this->log);
    }

    /**
     * E: the payment term of the cancelled invoice must not stay in place —
     * otherwise the "overdue" notice would report an order whose only invoice
     * is cancelled.
     */
    public function testDerWechselNimmtDasAlteZahlungszielMit(): void
    {
        $this->orders->order['paymentDueAt'] = 1_790_500_000;

        $this->change()->change(1, 3, 'de', 1_790_000_000);

        self::assertSame(0, $this->orders->order['paymentDueAt']);
    }

    public function testNachEinerZahlungWirdNichtsMehrGewechselt(): void
    {
        $this->orders->payments = ['5.0000'];

        self::assertSame(PaymentChangeBlock::NotOpen, $this->change()->options(1, 'de')->block);

        try {
            $this->change()->change(1, 3, 'de', 1_790_000_000);
            self::fail('Nach einer Zahlung darf nicht gewechselt werden.');
        } catch (PaymentChangeRefused $e) {
            self::assertSame(PaymentChangeBlock::NotOpen, $e->block);
        }

        self::assertSame(['begin', 'rollback'], $this->log);
        self::assertSame('2', $this->orders->order['paymentMethod']);
    }

    /**
     * C-10: "only once" per order — every switch writes a cancellation and an
     * invoice.
     */
    /**
     * D-3: after an accepted withdrawal a switch built a fee or discount on
     * goods that are no longer being bought.
     */
    public function testNachEinemWiderrufKeinWechsel(): void
    {
        $this->withdrawn = ['6.0000', [1 => '1']];

        self::assertSame(PaymentChangeBlock::AfterWithdrawal, $this->change()->options(1, 'de')->block);

        try {
            $this->change()->change(1, 3, 'de', 1_790_000_000);
            self::fail('Nach einem Widerruf darf kein Wechsel durchgehen.');
        } catch (PaymentChangeRefused $e) {
            self::assertSame(PaymentChangeBlock::AfterWithdrawal, $e->block);
        }

        self::assertSame(2, (int) $this->orders->order['paymentMethod']);
    }

    public function testNachEinemWechselIstKeinZweiterMoeglich(): void
    {
        $this->change()->change(1, 3, 'de', 1_790_000_000);

        self::assertSame(1_790_000_000, $this->orders->order['paymentChangedAt']);
        self::assertSame(PaymentChangeBlock::AlreadyChanged, $this->change()->options(1, 'de')->block);

        try {
            $this->change()->change(1, 2, 'de', 1_790_000_100);
            self::fail('Ein zweiter Wechsel darf nicht durchgehen.');
        } catch (PaymentChangeRefused $e) {
            self::assertSame(PaymentChangeBlock::AlreadyChanged, $e->block);
        }

        self::assertSame(3, $this->orders->order['paymentMethod']);
    }

    public function testEinHaltenderStatusSperrtDenWechsel(): void
    {
        $this->orders->order['orderStatus'] = '4';
        $this->orders->holdingStatuses = [4];

        self::assertSame(PaymentChangeBlock::StatusLocks, $this->change()->options(1, 'de')->block);
        self::assertFalse($this->change()->options(1, 'de')->offersChange());
    }

    /**
     * D-1: cancelled and then moved to a status without flags ("under
     * clarification") — the claim stays 0, nothing is switched.
     */
    public function testEineStornierteBestellungWechseltNichtsMehr(): void
    {
        $this->orders->order['cancelledAt'] = '1790000000';

        self::assertSame(PaymentChangeBlock::NotOpen, $this->change()->options(1, 'de')->block);
    }

    public function testDieAktuelleArtIstKeineWahl(): void
    {
        $options = $this->change()->options(1, 'de');

        self::assertTrue($this->option($options, 2)->current);
        self::assertFalse($this->option($options, 2)->available);
        self::assertTrue($this->option($options, 3)->available);

        $this->expectException(PaymentChangeRefused::class);

        $this->change()->change(1, 2, 'de', 1_790_000_000);
    }

    /**
     * A discount is distributed over the classes of the goods as at purchase,
     * at the rate the goods are frozen at — not the one that applies today.
     */
    public function testEinNachlassWirdUeberDieKlassenDerWareVerteilt(): void
    {
        $this->orders->items[] = ['lineType' => 'product', 'taxClass' => '2', 'taxRate' => '10.0000', 'quantity' => '1.0000', 'unitPrice' => '10.0000', 'taxAmount' => '1.0000', 'lineTotal' => '11.0000', 'priceSource' => 'list'];
        $this->orders->order['grandTotal'] = '23.0000';

        $options = $this->change(discount: '2.0000')->options(1, 'de');
        $lines = $this->option($options, 4)->lines;

        self::assertCount(2, $lines);
        self::assertSame('-1.0000', $lines[0]['unitPrice']);
        self::assertSame('20.0000', $lines[0]['taxRate']);
        self::assertSame('-0.2000', $lines[0]['taxAmount']);
        self::assertSame('10.0000', $lines[1]['taxRate']);
        self::assertSame('-1.1000', $lines[1]['lineTotal']);
        self::assertSame('paymentDiscount', $lines[0]['lineType']);
        self::assertSame(4, $lines[0]['source']);
        self::assertSame('-2.30', $this->option($options, 4)->gross);

        $this->change(discount: '2.0000')->change(1, 4, 'de', 1_790_000_000);

        // 10 + 10 net − 2 discount = 18; gross 12 + 11 − 1.20 − 1.10 = 20.70.
        self::assertSame('18.00', $this->orders->current()['netTotal']);
        self::assertSame('20.70', $this->orders->current()['grandTotal']);
        self::assertSame('2.70', $this->orders->current()['taxTotal']);
    }

    /**
     * WG8: on a switch too, a gift card carries no share of the discount — as
     * at purchase. The whole discount goes onto the goods.
     */
    public function testEinGutscheinTraegtKeinenAnteilAmNachlass(): void
    {
        $this->orders->items[] = ['lineType' => 'product', 'kind' => 'voucher', 'taxClass' => '9', 'taxRate' => '0.0000', 'quantity' => '1.0000', 'unitPrice' => '50.0000', 'taxAmount' => '0.0000', 'lineTotal' => '50.0000', 'priceSource' => 'notTaxable'];
        $this->orders->order['grandTotal'] = '62.0000';

        $lines = $this->option($this->change(discount: '2.0000')->options(1, 'de'), 4)->lines;

        self::assertCount(1, $lines);
        self::assertSame(['20.0000', '-2.0000'], [$lines[0]['taxRate'], $lines[0]['unitPrice']]);
    }

    /**
     * H (previously DL4, "in the delivery country"): the discount reduces each
     * supply at its own rate, as at purchase. In the same class there are a
     * consultation (AT 20 %) and an e-book (FR 5.5 %): one discount line each,
     * with the country and rate of its supply — the lines themselves deliver
     * the rate, no tax resolver is needed.
     */
    public function testDerNachlassTraegtDasLandDerLeistung(): void
    {
        $this->orders->order['shippingCountry'] = 'FR';
        $this->orders->items[0]['taxCountry'] = 'AT';
        $this->orders->items[] = ['lineType' => 'product', 'taxClass' => '1', 'taxRate' => '5.5000', 'quantity' => '1.0000', 'unitPrice' => '10.0000', 'taxAmount' => '0.5500', 'lineTotal' => '10.5500', 'priceSource' => 'list', 'taxCountry' => 'FR'];

        $lines = $this->option($this->change(discount: '2.0000')->options(1, 'de'), 4)->lines;

        self::assertSame(['AT:20.0000', 'FR:5.5000'], array_map(static fn (array $line): string => $line['taxCountry'].':'.$line['taxRate'], $lines));
        // Block R (R4): where the order went, on every new line.
        self::assertSame(['FR', 'FR'], array_column($lines, 'destinationCountry'));
    }

    public function testOhneSteuerklassenKeinWechselZuEinerArtMitBetrag(): void
    {
        // An order from before C-10.
        $this->orders->items[0]['taxClass'] = '0';

        $options = $this->change(discount: '2.0000')->options(1, 'de');

        self::assertFalse($this->option($options, 4)->available);
        self::assertTrue($this->option($options, 3)->available);
    }

    public function testEineGebuehrWirdNichtAngeboten(): void
    {
        self::assertFalse($this->option($this->change(fee: '2.0000')->options(1, 'de'), 4)->available);
    }

    /**
     * Abo-4: "Change payment method" on a follow-up order — allowed, but
     * without a fee. The price is frozen (A9); a discount would change it just
     * as a fee would.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function betraege(): iterable
    {
        yield 'Gebühr' => ['', '2.0000'];
        yield 'Nachlass' => ['2.0000', ''];
    }

    /**
     * @dataProvider betraege
     */
    public function testFolgebestellungWechseltOhneBetrag(string $discount, string $fee): void
    {
        $this->orders->order['subscription'] = '7';

        $option = $this->option($this->change(discount: $discount, fee: $fee)->options(1, 'de'), 4);

        self::assertTrue($option->available, 'angeboten, nur ohne Betrag');
        self::assertSame([], $option->lines);

        $this->change(discount: $discount, fee: $fee)->change(1, 4, 'de', 1_790_000_000);

        self::assertSame('12.00', $this->orders->order['grandTotal'], 'der Vertragspreis bleibt');
        self::assertSame(['product'], array_column($this->orders->items, 'lineType'));
    }

    /**
     * Control case: on an ordinary order E-1 still applies.
     */
    public function testOhneAboBleibtDieGebuehrAusgeschlossen(): void
    {
        $this->orders->order['subscription'] = '0';

        self::assertFalse($this->option($this->change(fee: '2.0000')->options(1, 'de'), 4)->available);
    }

    /**
     * Abo-4: whoever switched after delivery to a method that issues on
     * delivery got the cancellation and never a new invoice — the delivery
     * that triggers it had already been reported. A follow-up order is always
     * already delivered.
     */
    public function testNachDerLieferungStelltEineLieferartSofortAus(): void
    {
        $this->orders->deliveryReported = true;

        $changed = $this->change(trigger: DocumentTrigger::OnShipment)->change(1, 4, 'de', 1_790_000_000);

        self::assertSame(56, $changed->invoiceId);
        self::assertSame(['begin', 'cancel-invoice', 'replace-lines', 'update-order', 'issue-invoice', 'commit'], $this->log);
    }

    /**
     * Abo-5: after the payment term an order of a subscription no longer
     * switches — a switch to prepayment would otherwise lift the suspension
     * without a payment (P39).
     */
    public function testEineUeberfaelligeAboBestellungWechseltNicht(): void
    {
        $this->orders->ofSubscription = true;
        $this->orders->order['paymentDueAt'] = 1_790_000_000;

        self::assertNull($this->change()->options(1, 'de', 1_789_999_999)->block, 'vor dem Ziel: erlaubt');
        self::assertSame(PaymentChangeBlock::Overdue, $this->change()->options(1, 'de', 1_790_000_001)->block);

        try {
            $this->change()->change(1, 3, 'de', 1_790_000_001);
            self::fail('Nach dem Ziel darf nicht gewechselt werden.');
        } catch (PaymentChangeRefused $e) {
            self::assertSame(PaymentChangeBlock::Overdue, $e->block);
        }
    }

    public function testOhneAboSperrtDasZielNicht(): void
    {
        $this->orders->order['paymentDueAt'] = 1_790_000_000;

        self::assertNull($this->change()->options(1, 'de', 1_790_000_001)->block, 'E-2: überfällig heißt dort nur Hinweis');
    }

    /**
     * The second half: before the term a switch is allowed, but the term
     * stays — otherwise the order would never fall into arrears again.
     */
    public function testEineAboBestellungBehaeltIhrZahlungsziel(): void
    {
        $this->orders->ofSubscription = true;
        $this->orders->order['paymentDueAt'] = 1_790_500_000;

        $this->change()->change(1, 4, 'de', 1_790_000_000);

        self::assertSame(1_790_500_000, $this->orders->order['paymentDueAt']);
    }

    /**
     * Abo-5: the new prepayment invoice itself writes `paymentDueAt = 0`
     * (DocumentIssuer, an invoice without a term). The double now mirrors
     * that — before, it only answered with the id, and the test above stayed
     * green while the hole was open.
     */
    public function testAuchNachEinerNeuenRechnungOhneZielBleibtDasAlte(): void
    {
        $this->orders->ofSubscription = true;
        $this->orders->order['paymentDueAt'] = 1_790_500_000;
        $this->issuerClearsDue = true;

        $changed = $this->change()->change(1, 3, 'de', 1_790_000_000);

        self::assertSame(56, $changed->invoiceId, 'Vorkasse stellt aus');
        self::assertSame(1_790_500_000, $this->orders->current()['paymentDueAt']);
    }

    /**
     * Nachlese: a one-off purchase with digital goods is suspended after the
     * term and the grace period (PurchaseDelinquency). A switch to prepayment
     * set the term to 0, and the next run released the downloads again
     * without payment. Now as for subscriptions: no switch after the term,
     * before it the term stays.
     */
    public function testEinEinmalkaufMitBerechtigungenWieEinAbo(): void
    {
        $this->orders->oneTimeEntitlements = true;
        $this->orders->order['paymentDueAt'] = 1_790_500_000;
        $this->issuerClearsDue = true;

        self::assertSame(PaymentChangeBlock::Overdue, $this->change()->options(1, 'de', 1_790_500_001)->block);

        $this->change()->change(1, 3, 'de', 1_790_000_000);

        self::assertSame(1_790_500_000, $this->orders->current()['paymentDueAt'], 'die Vorkasse-Rechnung nimmt das Ziel nicht mit');
    }

    public function testOhneAboBleibtDieNeueRechnungOhneZiel(): void
    {
        $this->orders->order['paymentDueAt'] = 1_790_500_000;
        $this->issuerClearsDue = true;

        $this->change()->change(1, 3, 'de', 1_790_000_000);

        self::assertSame('0', $this->orders->order(1)['paymentDueAt']);
    }

    private bool $issuerClearsDue = false;

    public function testVorDerLieferungWartetEineLieferartAufSie(): void
    {
        $changed = $this->change(trigger: DocumentTrigger::OnShipment)->change(1, 4, 'de', 1_790_000_000);

        self::assertNull($changed->invoiceId, 'die Lieferung stellt aus (DeliveryReported)');
    }

    public function testEineZahlungsartStelltAuchNachDerLieferungErstBeiZahlungAus(): void
    {
        $this->orders->deliveryReported = true;

        self::assertNull($this->change()->change(1, 4, 'de', 1_790_000_000)->invoiceId);
    }

    private function option(PaymentChangeOptions $options, int $methodId): PaymentChangeOption
    {
        $option = $options->find($methodId);

        self::assertNotNull($option, \sprintf('Die Art %d fehlt in der Auswahl.', $methodId));

        return $option;
    }

    public function testEinFehlerRolltAllesZurueckUndArchiviertNichts(): void
    {
        $documents = $this->createMock(DocumentIssuerInterface::class);
        $documents->method('cancel')->willThrowException(new \RuntimeException('Nummernkreis fehlt'));

        $archived = [];

        try {
            $this->change($archived, documents: $documents)->change(1, 3, 'de', 1_790_000_000);
            self::fail('Der Fehler muss durchgereicht werden.');
        } catch (\RuntimeException) {
        }

        self::assertSame('rollback', end($this->log));
        self::assertSame([], $archived);
    }

    /**
     * @param list<int> $archived
     */
    private function change(array &$archived = [], string $discount = '', string $fee = '', DocumentIssuerInterface|null $documents = null, DocumentTrigger $trigger = DocumentTrigger::OnPayment): PaymentChange
    {
        $methods = $this->createStub(PaymentMethodRepositoryInterface::class);
        $methods->method('forShop')->willReturn([
            new PaymentMethod(2, 'prepayment', 'Vorkasse Bank A', ['instructions' => '<p>Bank A</p>']),
            new PaymentMethod(3, 'prepayment', 'Vorkasse Bank B', ['instructions' => '<p>Bank B</p>']),
            new PaymentMethod(4, 'adjusting', 'Mit Betrag', []),
        ]);

        $adjusting = new AdjustingHandler('' !== $fee ? new PaymentAdjustment($fee, PaymentAdjustmentOrigin::Fee) : ('' !== $discount ? new PaymentAdjustment($discount, PaymentAdjustmentOrigin::Discount) : null), $trigger);

        $handlers = new PaymentHandlers(new ServiceLocator([
            'prepayment' => static fn (): PaymentHandlerInterface => new PrepaymentHandler(),
            'adjusting' => static fn (): PaymentHandlerInterface => $adjusting,
        ]));

        $rows = $this->createStub(DocumentRepositoryInterface::class);
        $rows->method('findIdByOrderAndType')->willReturnCallback(fn (): int|null => $this->orders->standingInvoice);

        if (null === $documents) {
            $documents = $this->createStub(DocumentIssuerInterface::class);
            $documents->method('cancel')->willReturnCallback(function (): int {
                $this->log[] = 'cancel-invoice';

                return 55;
            });
            $documents->method('issueOnce')->willReturnCallback(function (int $o, int $s, DocumentType $t): int {
                $this->log[] = 'issue-invoice';

                // As DbalDocumentRepository::recordPaymentDue() does for an
                // invoice without a term.
                if ($this->issuerClearsDue) {
                    $this->orders->order['paymentDueAt'] = 0;
                }

                return 56;
            });
        }

        $archiver = $this->createStub(DocumentArchiverInterface::class);
        $archiver->method('archive')->willReturnCallback(static function (int $id) use (&$archived): void {
            $archived[] = $id;
        });

        $credits = $this->createStub(WithdrawalCredit::class);
        $credits->method('of')->willReturnCallback(fn (): array => $this->withdrawn);

        return new PaymentChange($this->connection, $this->orders, $methods, $handlers, new ShippingTaxSplitter(), new PaymentStateCalculator(), $rows, $documents, $archiver, $credits);
    }

    /** @var array{0: string, 1: array<int, string>} what WithdrawalCredit answers; nothing withdrawn by default */
    private array $withdrawn = ['0', []];
}

final class InMemoryPaymentChangeRepository implements PaymentChangeRepositoryInterface
{
    /** @var array<string, string|int> */
    public array $order = [
        'id' => '1', 'shop' => '1', 'currency' => 'EUR', 'language' => 'de', 'member' => '0', 'orderStatus' => '0',
        'billingCountry' => 'AT', 'shippingCountry' => '', 'paymentMethod' => '2',
        'netTotal' => '10.00', 'taxTotal' => '2.00', 'grandTotal' => '12.00',
    ];

    /**
     * The order as it stands now, read anew: the change writes it from
     * another object, and PHPStan keeps a property it read before such a call.
     *
     * @return array<string, string|int>
     */
    public function current(): array
    {
        return $this->order;
    }

    /** @var list<array<string, string|int>> */
    public array $items = [
        ['lineType' => 'product', 'taxClass' => '1', 'taxRate' => '20.0000', 'quantity' => '1.0000', 'unitPrice' => '10.0000', 'taxAmount' => '2.0000', 'lineTotal' => '12.0000', 'priceSource' => 'list'],
    ];

    /** @var list<string> */
    public array $payments = [];

    /** @var list<int> */
    public array $holdingStatuses = [];

    /** @var list<PaymentAttempt> */
    public array $attempts = [];

    /** @var list<int> */
    public array $cancelledAttempts = [];

    public int|null $standingInvoice = 50;

    public bool $deliveryReported = false;

    public bool $ofSubscription = false;

    public bool $oneTimeEntitlements = false;

    /** @var list<string> */
    public array $log = [];

    public function hasReportedDelivery(int $orderId): bool
    {
        return $this->deliveryReported;
    }

    public function belongsToSubscription(int $orderId): bool
    {
        return $this->ofSubscription;
    }

    public function hasOneTimeEntitlements(int $orderId): bool
    {
        return $this->oneTimeEntitlements;
    }

    public function order(int $orderId): array
    {
        return array_map('strval', $this->order);
    }

    public function lockOrder(int $orderId): array
    {
        return $this->order($orderId);
    }

    public function items(int $orderId): array
    {
        return array_map(static fn (array $item): array => array_map('strval', $item), $this->items);
    }

    public function paymentAmounts(int $orderId): array
    {
        return $this->payments;
    }

    public function statusHolds(int $statusId): bool
    {
        return \in_array($statusId, $this->holdingStatuses, true);
    }

    public function memberGroups(int $memberId): array
    {
        return [];
    }

    public function openAttempts(int $orderId): array
    {
        return $this->attempts;
    }

    public function cancelAttempt(int $attemptId, int $now): void
    {
        $this->cancelledAttempts[] = $attemptId;
    }

    public function replacePaymentLines(int $orderId, array $lines, int $now): void
    {
        $this->log[] = 'replace-lines';

        $this->items = array_values(array_filter($this->items, static fn (array $item): bool => !\in_array($item['lineType'], ['paymentFee', 'paymentDiscount'], true)));

        foreach ($lines as $line) {
            $this->items[] = $line;
        }
    }

    public function updateOrder(int $orderId, array $fields): void
    {
        $this->log[] = 'update-order';

        $this->order = $fields + $this->order;
    }
}

final class AdjustingHandler implements PaymentHandlerInterface
{
    public function __construct(
        private readonly PaymentAdjustment|null $adjustment,
        private readonly DocumentTrigger $trigger = DocumentTrigger::OnPayment,
    ) {
    }

    public static function getType(): string
    {
        return 'adjusting';
    }

    public function isAvailable(PaymentContext $context, array $config): bool
    {
        return true;
    }

    public function adjustment(PaymentContext $context, array $config): PaymentAdjustment|null
    {
        return $this->adjustment;
    }

    public function documentTrigger(array $config): DocumentTrigger
    {
        return $this->trigger;
    }

    public function start(PaymentAttempt $attempt, OrderView $order, array $config): PaymentStart
    {
        return PaymentStart::failed('not in this test');
    }

    public function handleReturn(Request $request, PaymentAttempt $attempt, array $config): AttemptState
    {
        return AttemptState::Pending;
    }

    public function handleNotification(Request $request, PaymentAttempt $attempt, array $config): NotificationResult
    {
        return NotificationResult::ignored();
    }

    public function cancel(PaymentAttempt $attempt, array $config): void
    {
    }
}

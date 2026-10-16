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
 * 7.5 „Zahlungsart ändern" (C23, C-10) — gegen ein Repository im Speicher und
 * eine gemockte Verbindung: Geprüft wird die REIHENFOLGE in der Transaktion,
 * und SQLite kennt kein FOR UPDATE (wie OrderPlacerTest).
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

        // Storno VOR dem Umschreiben, neue Rechnung NACH dem Kopf — sie liest
        // die Zahlungsart aus der Bestellzeile (DbalDocumentSource), und vor
        // dem Kopf stünde dort noch die alte Bank (Review C-10, Befund 2).
        // Alles in einer Transaktion; archiviert wird erst, wenn nichts mehr
        // zurückrollen kann.
        self::assertSame(['begin', 'cancel-invoice', 'replace-lines', 'update-order', 'issue-invoice', 'commit'], $this->log);
        self::assertSame([55, 56], $archived);

        self::assertSame([9], $this->orders->cancelledAttempts);
        self::assertSame(3, $this->orders->order['paymentMethod']);
        self::assertSame('Vorkasse Bank B', $this->orders->order['paymentMethodTitle']);
        self::assertSame('<p>Bank B</p>', $this->orders->order['paymentInstructions']);

        // Ohne Betrag bleiben die Summen, wie sie sind — aber neu gezählt, nicht
        // übernommen.
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
     * Fable-Review E, S1: Das Zahlungsziel der stornierten Rechnung darf nicht
     * stehen bleiben — sonst meldete der Hinweis „überfällig" eine Bestellung,
     * deren einzige Rechnung storniert ist.
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
     * Abnahme C-10 (Michael, 2026-09-25): „nur einmal" je Bestellung — jeder
     * Wechsel schreibt einen Storno und eine Rechnung.
     */
    /**
     * D-3, Fable-Review B1: Nach einem angenommenen Widerruf baute ein
     * Wechsel Gebühr oder Rabatt auf Ware, die nicht mehr gekauft wird.
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
     * D-1-Review, S4: storniert und dann auf einen Status ohne Flags
     * („In Klärung") — die Forderung bleibt 0, gewechselt wird nichts.
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
     * Ein Nachlass wird wie beim Kauf über die Klassen der Ware verteilt, zum
     * Satz, zu dem die Ware eingefroren ist — nicht zu dem, der heute gilt.
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

        // 10 + 10 netto − 2 Nachlass = 18; brutto 12 + 11 − 1,20 − 1,10 = 20,70.
        self::assertSame('18.00', $this->orders->order['netTotal']);
        self::assertSame('20.70', $this->orders->order['grandTotal']);
        self::assertSame('2.70', $this->orders->order['taxTotal']);
    }

    /**
     * WG8: Auch beim Wechsel trägt ein Gutschein keinen Anteil am Nachlass —
     * wie beim Kauf. Der ganze Nachlass geht auf die Ware.
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
     * Review H, S1 (vorher DL4, zweites Review S2: „im Lieferland"): Der
     * Nachlass mindert jede Leistung zu ihrem eigenen Satz, wie beim Kauf. In
     * derselben Klasse stehen eine Beratung (AT 20 %) und ein E-Book (FR
     * 5,5 %): je eine Nachlasszeile, mit Land und Satz ihrer Leistung — den
     * Satz liefern die Zeilen selbst, ein Steuerauflöser ist nicht nötig.
     */
    public function testDerNachlassTraegtDasLandDerLeistung(): void
    {
        $this->orders->order['shippingCountry'] = 'FR';
        $this->orders->items[0]['taxCountry'] = 'AT';
        $this->orders->items[] = ['lineType' => 'product', 'taxClass' => '1', 'taxRate' => '5.5000', 'quantity' => '1.0000', 'unitPrice' => '10.0000', 'taxAmount' => '0.5500', 'lineTotal' => '10.5500', 'priceSource' => 'list', 'taxCountry' => 'FR'];

        $lines = $this->option($this->change(discount: '2.0000')->options(1, 'de'), 4)->lines;

        self::assertSame(['AT:20.0000', 'FR:5.5000'], array_map(static fn (array $line): string => $line['taxCountry'].':'.$line['taxRate'], $lines));
        // Block R (R4): wohin die Bestellung ging, an jeder neuen Zeile.
        self::assertSame(['FR', 'FR'], array_column($lines, 'destinationCountry'));
    }

    public function testOhneSteuerklassenKeinWechselZuEinerArtMitBetrag(): void
    {
        // Eine Bestellung von vor C-10.
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
     * Abnahme Abo-4, Michael 2026-09-30: „Zahlungsart ändern" an einer
     * Folgebestellung — zulassen, aber ohne Gebühr. Der Preis ist
     * eingefroren (A9); ein Nachlass änderte ihn ebenso wie eine Gebühr.
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
     * Gegenprobe: an einer gewöhnlichen Bestellung bleibt es bei E-1.
     */
    public function testOhneAboBleibtDieGebuehrAusgeschlossen(): void
    {
        $this->orders->order['subscription'] = '0';

        self::assertFalse($this->option($this->change(fee: '2.0000')->options(1, 'de'), 4)->available);
    }

    /**
     * Gefunden beim Messen für Abo-4: Wer nach der Lieferung auf eine Art
     * wechselt, die bei Lieferung ausstellt, bekam den Storno und nie eine
     * neue Rechnung — die Lieferung, die sie auslöst, war schon gemeldet.
     * Eine Folgebestellung ist immer schon geliefert.
     */
    public function testNachDerLieferungStelltEineLieferartSofortAus(): void
    {
        $this->orders->deliveryReported = true;

        $changed = $this->change(trigger: DocumentTrigger::OnShipment)->change(1, 4, 'de', 1_790_000_000);

        self::assertSame(56, $changed->invoiceId);
        self::assertSame(['begin', 'cancel-invoice', 'replace-lines', 'update-order', 'issue-invoice', 'commit'], $this->log);
    }

    /**
     * Fable-Review Abo-5, S2: Nach dem Zahlungsziel wechselt eine Bestellung
     * eines Abos nicht mehr — ein Wechsel auf Vorkasse höbe das Ruhen sonst
     * ohne Zahlung auf (P39).
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
     * S2, zweite Hälfte: Vor dem Ziel darf gewechselt werden, aber das Ziel
     * bleibt — sonst geriete die Bestellung nie mehr in Verzug.
     */
    public function testEineAboBestellungBehaeltIhrZahlungsziel(): void
    {
        $this->orders->ofSubscription = true;
        $this->orders->order['paymentDueAt'] = 1_790_500_000;

        $this->change()->change(1, 4, 'de', 1_790_000_000);

        self::assertSame(1_790_500_000, $this->orders->order['paymentDueAt']);
    }

    /**
     * Zweites Review Abo-5, S2: Die neue Vorkasse-Rechnung schreibt selbst
     * `paymentDueAt = 0` (DocumentIssuer, eine Rechnung ohne Ziel). Der
     * Doppel stellt das jetzt nach — vorher antwortete er nur mit der Id, und
     * der Test oben blieb grün, während das Loch offen war (Regel 25, Punkt 38).
     */
    public function testAuchNachEinerNeuenRechnungOhneZielBleibtDasAlte(): void
    {
        $this->orders->ofSubscription = true;
        $this->orders->order['paymentDueAt'] = 1_790_500_000;
        $this->issuerClearsDue = true;

        $changed = $this->change()->change(1, 3, 'de', 1_790_000_000);

        self::assertSame(56, $changed->invoiceId, 'Vorkasse stellt aus');
        self::assertSame(1_790_500_000, $this->orders->order['paymentDueAt']);
    }

    /**
     * Review Nachlese, B1: Ein Einmalkauf mit digitalen Gütern ruht nach Ziel
     * und Nachfrist (PurchaseDelinquency). Ein Wechsel auf Vorkasse setzte
     * das Ziel auf 0, und der nächste Lauf gab die Downloads ohne Zahlung
     * wieder frei. Jetzt wie beim Abo: nach dem Ziel kein Wechsel, davor
     * bleibt das Ziel.
     */
    public function testEinEinmalkaufMitBerechtigungenWieEinAbo(): void
    {
        $this->orders->oneTimeEntitlements = true;
        $this->orders->order['paymentDueAt'] = 1_790_500_000;
        $this->issuerClearsDue = true;

        self::assertSame(PaymentChangeBlock::Overdue, $this->change()->options(1, 'de', 1_790_500_001)->block);

        $this->change()->change(1, 3, 'de', 1_790_000_000);

        self::assertSame(1_790_500_000, $this->orders->order['paymentDueAt'], 'die Vorkasse-Rechnung nimmt das Ziel nicht mit');
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

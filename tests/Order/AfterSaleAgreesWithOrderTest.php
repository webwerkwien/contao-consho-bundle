<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentRepository;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentSource;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuer;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPdfInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceView;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\DbalOrderConfirmationSource;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Notification\RecipientResolver;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderStatusRepository;
use Webwerkwien\ContaoConshoBundle\Order\DbalPaymentChangeRepository;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeAllocator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalance;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusWriterInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\StatusFlags;
use Webwerkwien\ContaoConshoBundle\Payment\DbalPaymentMethodRepository;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\TestPaymentHandler;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ErrorSpyLogger;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\SpyTransport;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Withdrawal\DbalWithdrawalRepository;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalDeclaration;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalOutcome;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalProcessing;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalRecorder;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalSource;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalWindowReader;

// Translator, spy transport and error logger of the confirmation mail.
require_once __DIR__.'/../Notification/OrderConfirmationTest.php';

/**
 * After the purchase, against the order (Spec D, section 11; decision M1:
 * an integration test per event like CheckoutAgreesWithOrderTest — real
 * services, SQLite, no double that anticipates the question).
 *
 * Until now every event test replaced exactly the collaborators that
 * decide the question: whether an invoice really is created was answered
 * by a stub of DocumentIssuerInterface, whether the status changes by a
 * spy on the advancer, whether the mail goes out by a double of
 * OrderEventMail. Here the REAL services run against each other — event,
 * balance, number range, document with snapshot, status change,
 * withdrawal, mail with the real templates and language files — and what
 * is checked is the FINAL STATE: what is in the database and which mails
 * the transport has seen.
 *
 * ── What stays a double, and why it decides nothing ──────────────────────
 *
 *  - The PDF (DocumentPdfInterface, DocumentArchiverInterface): a fixed
 *    text instead of Dompdf. The file name comes from the document number
 *    as in the original — read from the database, not passed along.
 *  - The link to the order page (OrderPageLink): a fixed address. The
 *    original needs Contao's framework and the page structure.
 *  - The status WRITER: writes only tl_consho_order.orderStatus.
 *    ContaoOrderStatusWriter needs Contao's framework and `Versions` (the
 *    DCA of tl_consho_order, tl_version) — WHICH status is set is decided
 *    by the real OrderStatusAdvancer before it. Less than the original: no
 *    version, no cancelledAt (no case here cancels).
 *  - The mail connection (MailTransportFactoryInterface): the spy.
 *  - No payment handler any more: "invoice on shipping" runs through the
 *    real payment method "on invoice" (InvoicePaymentHandler) since E-2.
 *    Until then a stand-in was here, because no core payment method issued
 *    on shipping (D-7, M2).
 *
 * ── FOR UPDATE ─────────────────────────────────────────────────────────────
 *
 * SQLite has no `FOR UPDATE`; lockOrder() and the number range write it
 * out. Instead of doubling both away (as the earlier tests did), a DBAL
 * middleware strips exactly these two words at the end of a statement and
 * remembers the statement — everything else goes to the database
 * unchanged. So the real queries run, and the test sees THAT a lock was
 * taken.
 *
 * ── The schema ─────────────────────────────────────────────────────────────
 *
 * Column names, nullability and defaults as in contao/dca/*.php. decimal
 * columns as TEXT: MySQL returns `2.0000`, a NUMERIC column in SQLite `2`
 * — and exactly this difference put "2.0000 × Wanderstock" into a mail
 * without any test seeing it. The withdrawal tables from WithdrawalSchema.
 *
 * Order: 2 × hiking pole (60.00), 1 × backpack (35.00), shipping 5.00 —
 * together €100.00 gross, 20 % VAT.
 */
final class AfterSaleAgreesWithOrderTest extends TestCase
{
    private const SHOP = 1;
    private const ORDER = 7;

    private const PREPAYMENT = 1;
    private const ONLINE = 2;
    private const ON_SHIPMENT = 3;

    private const OPEN = 10;
    private const PAID = 11;
    private const SHIPPED = 12;
    private const DONE = 13;

    private const WANDERSTOCK = 1;
    private const RUCKSACK = 2;
    private const PAKET = 3;

    private const VIEW_URL = 'https://shop.test/bestellung?consho_order=abc';

    /** @var list<string> statements that went to the database with FOR UPDATE */
    private static array $rowLocks = [];

    private Connection $connection;

    private SpyTransport $transport;

    private ErrorSpyLogger $logger;

    /** @var \ArrayObject<int, int> what was to go into the archive — the documents an event issued */
    private \ArrayObject $archived;

    private string $zone;

    private PaymentRecorded $payments;

    private DeliveryReported $deliveries;

    private WithdrawalRecorder $recorder;

    private WithdrawalProcessing $processing;

    private WithdrawalWindowReader $windows;

    private DocumentIssuer $issuer;

    private OrderBalanceReader $balances;

    public static function withoutRowLock(string $sql): string
    {
        $stripped = preg_replace('/\s+FOR\s+UPDATE\s*$/i', '', $sql, 1, $count);

        if ($count > 0) {
            self::$rowLocks[] = (string) $stripped;
        }

        return (string) $stripped;
    }

    protected function setUp(): void
    {
        // The period ends "at the end of the day … in the time zone of the
        // installation" (WithdrawalWindow). The installation is Vienna.
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        self::$rowLocks = [];
        $this->archived = new \ArrayObject();

        $configuration = (new Configuration())->setMiddlewares([new class implements Middleware {
            public function wrap(Driver $driver): Driver
            {
                return new class($driver) extends AbstractDriverMiddleware {
                    public function connect(array $params): DriverConnection
                    {
                        return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                            public function prepare(string $sql): DriverStatement
                            {
                                return parent::prepare(AfterSaleAgreesWithOrderTest::withoutRowLock($sql));
                            }

                            public function query(string $sql): DriverResult
                            {
                                return parent::query(AfterSaleAgreesWithOrderTest::withoutRowLock($sql));
                            }

                            public function exec(string $sql): int|string
                            {
                                return parent::exec(AfterSaleAgreesWithOrderTest::withoutRowLock($sql));
                            }
                        };
                    }
                };
            }
        }]);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->createSchema();
        $this->seedShop();

        $this->transport = new SpyTransport();
        $this->logger = new ErrorSpyLogger();

        $this->wire();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    // ── 1. Prepayment: invoice at purchase, payment in the backend ────────

    public function testVorkasseImBackendBezahltSperrtDieZahlungSetztDenStatusUndStelltKeineZweiteRechnungAus(): void
    {
        $this->order('WEB-00071', self::PREPAYMENT);
        $invoice = $this->purchaseInvoice();

        self::assertSame('RE-00001', $this->documentNumber($invoice));

        // As the backend does: DC_Table saves the row, the callback calls
        // PaymentRecorded (PaymentRecordListener) — without a copy, the operator
        // recorded it himself (Z3).
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'tstamp' => 1, 'receivedAt' => self::at('2026-10-01 09:00:00'), 'amount' => '100.0000', 'method' => 'Überweisung', 'reference' => 'WEB-00071']);
        $now = self::at('2026-10-01 09:05:00');

        $this->payments->handle(self::ORDER, false, $now);

        // Balance settled (A1), payment locked (A2), status after payment (5.3).
        $balance = $this->balance();
        self::assertSame(PaymentState::Paid, $balance->paymentState);
        self::assertTrue($balance->paymentSettled());
        self::assertSame($now, (int) $this->orderRow()['paymentSettledAt']);
        self::assertSame([$now], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT lockedAt FROM tl_consho_payment')));
        self::assertSame(self::PAID, (int) $this->orderRow()['orderStatus'], '„Status nach vollständiger Zahlung" — nicht „wenn alles erledigt": geliefert ist nichts');

        // No second invoice: the same row, the number range untouched.
        self::assertSame([$invoice], $this->documentIds(DocumentType::Invoice));
        self::assertSame(1, $this->rangeCurrent('invoice'));
        self::assertSame([], $this->archived->getArrayCopy(), 'nichts Neues zu archivieren');

        // Exactly one mail, to the customer; without an attachment, because she has had the invoice since the purchase.
        self::assertCount(1, $this->transport->sent);
        $mail = $this->transport->sent[0];
        self::assertSame('erika@example.org', $mail->getTo()[0]->getAddress());
        self::assertSame('Zahlung erhalten: Bestellung WEB-00071', $mail->getSubject());
        self::assertSame([], $mail->getAttachments());
        self::assertStringNotContainsString('Anhang', (string) $mail->getHtmlBody());
        self::assertStringContainsString(self::VIEW_URL, (string) $mail->getHtmlBody());
        $this->assertCleanMail($mail);

        // The order was locked (5.4, concurrency).
        self::assertNotEmpty(array_filter(self::$rowLocks, static fn (string $sql): bool => str_contains($sql, 'FROM tl_consho_order WHERE')));

        // A second call (callback again, version restored …) changes nothing.
        $this->payments->handle(self::ORDER, false, $now + 60);

        self::assertCount(1, $this->transport->sent);
        self::assertSame($now, (int) $this->orderRow()['paymentSettledAt']);
        self::assertSame([], $this->logger->errors);
    }

    // ── 2. Paid online: invoice on payment ────────────────────────────────

    public function testOnlineBezahltStelltGenauEineRechnungMitDerNaechstenNummerAus(): void
    {
        $this->order('WEB-00072', self::ONLINE);

        // Two invoices of this shop already exist: the next one is RE-00003.
        $this->connection->update('tl_consho_number_range', ['current' => 2], ['pid' => self::SHOP, 'type' => 'invoice']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_document'), 'bei Zahlung: vor der Zahlung keine Rechnung');

        // As NotificationProcessor does: the attempt's row, then the consequences (online).
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'tstamp' => 1, 'receivedAt' => self::at('2026-10-01 09:00:00'), 'amount' => '100.0000', 'method' => 'test', 'reference' => 'TEST-1', 'attempt' => 1]);
        $now = self::at('2026-10-01 09:00:05');

        $this->payments->handle(self::ORDER, true, $now);

        $invoices = $this->documentIds(DocumentType::Invoice);
        self::assertCount(1, $invoices);
        self::assertSame('RE-00003', $this->documentNumber($invoices[0]), 'die nächste Nummer aus dem echten Nummernkreis');
        self::assertSame(3, $this->rangeCurrent('invoice'));
        self::assertSame($invoices, $this->archived->getArrayCopy());

        // The snapshot is written — from the order, not from a template in the test.
        $payload = $this->payload($invoices[0]);
        self::assertSame('WEB-00072', $payload['order']['orderNumber']);
        self::assertTrue(BigDecimal::of('100')->isEqualTo($payload['order']['grandTotal']));
        self::assertSame(['Wanderstock', 'Rucksack', 'Paket'], array_column($payload['items'], 'productName'));
        self::assertSame('Muster Wanderbedarf', $payload['issuer']['company']);

        self::assertSame(self::PAID, (int) $this->orderRow()['orderStatus']);
        self::assertSame($now, (int) $this->connection->fetchOne('SELECT lockedAt FROM tl_consho_payment'));

        // Customer with the invoice attached, operator with a copy (Z3: online).
        self::assertCount(2, $this->transport->sent);
        [$customer, $operator] = $this->transport->sent;
        self::assertSame('Zahlung erhalten: Bestellung WEB-00072', $customer->getSubject());
        self::assertStringContainsString('Die Rechnung RE-00003 finden Sie im Anhang.', (string) $customer->getHtmlBody());
        self::assertSame(['RE-00003.pdf'], $this->attachmentNames($customer));
        self::assertSame('office@shop.test', $operator->getTo()[0]->getAddress());
        self::assertSame('Zahlung eingegangen: Bestellung WEB-00072', $operator->getSubject());
        $this->assertCleanMail($customer);
        $this->assertCleanMail($operator);

        // The notification comes once more: no second invoice, no number used up, no mail.
        $this->payments->handle(self::ORDER, true, $now + 30);

        self::assertCount(1, $this->documentIds(DocumentType::Invoice));
        self::assertSame(3, $this->rangeCurrent('invoice'));
        self::assertCount(2, $this->transport->sent);
        self::assertSame([], $this->logger->errors);
    }

    // ── 3. Invoice on shipping ────────────────────────────────────────────

    public function testRechnungBeiVersandEntstehtMitDerMeldungUndGehtMitDerVersandmail(): void
    {
        $this->order('WEB-00073', self::ON_SHIPMENT);
        $parcel = $this->parcel([self::WANDERSTOCK => '2.0000', self::RUCKSACK => '1.0000'], self::at('2026-10-01 10:00:00'), 'Post AT', 'CA123456789AT');
        $now = self::at('2026-10-01 10:30:00');

        self::assertSame(PaymentState::Open, $this->balance()->paymentState, 'unbezahlt');

        $report = $this->deliveries->report($parcel, $now);

        self::assertSame('reported', $report->outcome);
        self::assertTrue($report->mailSent);

        // Exactly one invoice, over the whole order (Z2).
        $invoices = $this->documentIds(DocumentType::Invoice);
        self::assertCount(1, $invoices);
        self::assertSame('RE-00001', $this->documentNumber($invoices[0]));
        self::assertCount(3, $this->payload($invoices[0])['items']);
        self::assertSame($invoices, $this->archived->getArrayCopy());

        // E-2: the invoice names "payable by" — 14 days from its date —,
        // and the order remembers the end for the "overdue" notice.
        $issuedAt = (int) $this->connection->fetchOne('SELECT issuedAt FROM tl_consho_document WHERE id = ?', [$invoices[0]]);
        $due = (new \DateTimeImmutable('@'.$issuedAt))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->modify('+14 days');
        self::assertSame($due->format('Y-m-d'), $this->payload($invoices[0])['payment']['dueDate']);
        self::assertSame($due->setTime(23, 59, 59)->getTimestamp(), (int) $this->orderRow()['paymentDueAt']);
        self::assertSame('AT611904300234573201', $this->payload($invoices[0])['payment']['details']['iban'], 'die Bankverbindung für die Überweisung');

        // E-6b: at shipping the day of delivery is known — everything in
        // one parcel, so "delivered" on the shipping day.
        $shipped = date('Y-m-d', self::at('2026-10-01 10:00:00'));
        self::assertSame(['from' => $shipped, 'to' => $shipped, 'kind' => 'delivered'], $this->payload($invoices[0])['delivery']);

        // Delivery reported and locked (A2), delivery state complete, status after delivery.
        $delivery = $this->connection->fetchAssociative('SELECT reportedAt, lockedAt FROM tl_consho_delivery WHERE id = ?', [$parcel]);
        self::assertSame([$now, $now], array_map(intval(...), array_values((array) $delivery)));
        $balance = $this->balance();
        self::assertSame(DeliveryState::Complete, $balance->deliveryState);
        self::assertTrue($balance->deliverySettled());
        self::assertSame(PaymentState::Open, $balance->paymentState);
        self::assertSame(self::SHIPPED, (int) $this->orderRow()['orderStatus'], '„nach vollständiger Lieferung" — nicht „erledigt": bezahlt ist nichts');

        // The shipping mail names what is in the parcel and brings the invoice along (Z4).
        self::assertCount(1, $this->transport->sent, 'keine Kopie: der Betreiber hat gemeldet');
        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();
        self::assertSame('Ihre Bestellung WEB-00073 ist unterwegs', $mail->getSubject());
        self::assertStringContainsString('2 × Wanderstock', $html, 'Menge wie gespeichert „2.0000" — ohne Nullen in der Mail');
        self::assertStringContainsString('1 × Rucksack', $html);
        self::assertStringNotContainsString('Paket</li>', $html, 'Versand ist keine Ware im Paket');
        self::assertStringContainsString('Sendungsnummer (Post AT): CA123456789AT', $html);
        self::assertStringNotContainsString('Die übrigen Artikel folgen', $html);
        self::assertStringContainsString('Die Rechnung RE-00001 finden Sie im Anhang.', $html);
        self::assertSame(['RE-00001.pdf'], $this->attachmentNames($mail));
        $this->assertCleanMail($mail);

        // A second report of the same delivery: refused, no second invoice.
        self::assertSame('alreadyReported', $this->deliveries->report($parcel, $now + 60)->outcome);
        self::assertCount(1, $this->documentIds(DocumentType::Invoice));
        self::assertSame(1, $this->rangeCurrent('invoice'));
        self::assertCount(1, $this->transport->sent);
        self::assertSame([], $this->logger->errors);
    }

    // ── 4. Withdrawal: declared, accepted, returned, refunded ─────────────

    public function testTeilwiderrufVonDerErklaerungBisZurErstattung(): void
    {
        // The shop as it sold before D-1: without "status when everything
        // is done" (the field came with D-1). With the field from the start
        // the order would already be at "completed" after payment and
        // shipping — and no automatic change leads out of a status with
        // isCompleted (StatusFlags::holds()); F10 would not be visible.
        $this->connection->update('tl_consho_shop', ['statusWhenSettled' => 0], ['id' => self::SHOP]);

        // ── Starting point through the real events: prepayment, paid, shipped.
        $this->order('WEB-00074', self::PREPAYMENT);
        $invoice = $this->purchaseInvoice();
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'tstamp' => 1, 'receivedAt' => self::at('2026-09-30 09:00:00'), 'amount' => '100.0000', 'method' => 'Überweisung']);
        $this->payments->handle(self::ORDER, false, self::at('2026-09-30 09:05:00'));
        $parcel = $this->parcel([self::WANDERSTOCK => '2.0000', self::RUCKSACK => '1.0000'], self::at('2026-10-01 10:00:00'), 'Post AT', 'CA1AT');
        self::assertSame('reported', $this->deliveries->report($parcel, self::at('2026-10-01 10:00:00'))->outcome);

        self::assertTrue($this->balance()->settled(), 'bezahlt und versandt');
        self::assertSame(self::SHIPPED, (int) $this->orderRow()['orderStatus']);

        // Now the operator sets the field.
        $this->connection->update('tl_consho_shop', ['statusWhenSettled' => self::DONE], ['id' => self::SHOP]);
        $this->transport->sent = [];

        // ── The declaration: 1 of 2 hiking poles, through the order page (W3).
        $received = self::at('2026-10-05 14:03:05');
        $result = $this->recorder->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'Erika Muster', [self::WANDERSTOCK => '1']), $received);

        self::assertSame(WithdrawalOutcome::Recorded, $result->outcome);
        self::assertSame('WEB-00074-W1', $result->number, 'aus der Bestellnummer, kein Nummernkreis (E3)');
        self::assertTrue($result->confirmed);
        $withdrawal = (int) $result->id;
        self::assertSame('received', $this->withdrawalRow($withdrawal)['state']);
        self::assertSame($received, (int) $this->withdrawalRow($withdrawal)['receivedAt']);
        self::assertNotNull($this->withdrawalRow($withdrawal)['confirmationSentAt']);

        // Acknowledgement of receipt with date AND time (Spec D 2, point 4), copy to the operator.
        $sent = $this->transport->sent();
        self::assertCount(2, $sent);
        [$confirmation, $copy] = $sent;
        $html = (string) $confirmation->getHtmlBody();
        self::assertSame('Eingangsbestätigung: Widerruf zur Bestellung WEB-00074', $confirmation->getSubject());
        self::assertStringContainsString('5. Oktober 2026', $html);
        self::assertStringContainsString('14:03:05', $html, 'die Uhrzeit, nicht nur das Datum');
        self::assertStringContainsString('1 × Wanderstock', $html);
        self::assertSame('Widerruf eingegangen: Bestellung WEB-00074', $copy->getSubject());
        self::assertSame('office@shop.test', $copy->getTo()[0]->getAddress());
        $this->assertCleanMail($confirmation);
        $this->assertCleanMail($copy);
        $this->transport->sent = [];

        // ── Accept: the claim drops by the goods (F7), the payment still stands.
        $accepted = $this->processing->accept($withdrawal, [self::WANDERSTOCK => '1'], self::at('2026-10-06 08:00:00'));

        self::assertTrue($accepted->done, $accepted->key);
        self::assertSame('accepted', $this->withdrawalRow($withdrawal)['state']);
        self::assertTrue(BigDecimal::zero()->isEqualTo((string) $this->withdrawalRow($withdrawal)['refundShipping']), 'Teilwiderruf: kein Versand vorgeschlagen (F1)');
        $balance = $this->balance();
        self::assertTrue(BigDecimal::of('70')->isEqualTo($balance->claim));
        self::assertSame(PaymentState::Overpaid, $balance->paymentState, 'bis zur Erstattung ist zu viel bezahlt');
        self::assertFalse($balance->settled());
        self::assertSame(self::SHIPPED, (int) $this->orderRow()['orderStatus']);

        // ── Return shipment (F8).
        $row = (int) $this->connection->fetchOne('SELECT id FROM tl_consho_withdrawal_item WHERE pid = ?', [$withdrawal]);
        self::assertTrue($this->processing->recordReturn($withdrawal, self::at('2026-10-09 11:00:00'), [$row => '1'], self::at('2026-10-09 11:05:00'))->done);
        self::assertSame(self::at('2026-10-09 11:00:00'), (int) $this->withdrawalRow($withdrawal)['returnReceivedAt']);
        self::assertTrue(BigDecimal::one()->isEqualTo((string) $this->connection->fetchOne('SELECT returnedQuantity FROM tl_consho_withdrawal_item WHERE id = ?', [$row])));
        self::assertSame([], $this->transport->sent, 'Annehmen und Rücksendung schreiben keine Mail');

        // ── Book the refund (F3, F4).
        $refundedAt = self::at('2026-10-09 11:10:00');
        $refund = $this->processing->refund($withdrawal, '0', $refundedAt);

        self::assertTrue($refund->done, $refund->key);
        self::assertSame('refunded', $refund->key);

        // The negative payment, locked at once.
        $payment = $this->connection->fetchAssociative('SELECT id, amount, method, reference, lockedAt FROM tl_consho_payment WHERE pid = ? AND id != (SELECT MIN(id) FROM tl_consho_payment)', [self::ORDER]);
        self::assertIsArray($payment);
        self::assertTrue(BigDecimal::of('-30')->isEqualTo((string) $payment['amount']));
        self::assertSame('refund', $payment['method']);
        self::assertSame('WEB-00074-W1', $payment['reference']);
        self::assertSame($refundedAt, (int) $payment['lockedAt']);

        // The invoice correction (F2, internally CreditNote): its own number range,
        // referring to the invoice, negative with the withdrawn goods (F3).
        $corrections = $this->documentIds(DocumentType::CreditNote);
        self::assertCount(1, $corrections);
        $correction = $this->connection->fetchAssociative('SELECT documentNumber, relatedDocument, shop FROM tl_consho_document WHERE id = ?', [$corrections[0]]);
        self::assertSame(['documentNumber' => 'RK-00001', 'relatedDocument' => $invoice, 'shop' => self::SHOP], array_map(static fn ($v): int|string => is_numeric($v) ? (int) $v : (string) $v, (array) $correction));
        self::assertSame(1, $this->rangeCurrent('creditNote'));
        self::assertSame(1, $this->rangeCurrent('invoice'), 'die Rechnungsnummern bleiben unberührt');

        $payload = $this->payload($corrections[0]);
        self::assertSame(['number' => 'RE-00001'], array_intersect_key($payload['corrects'], ['number' => true]));
        self::assertSame('WEB-00074-W1', $payload['withdrawal']);
        self::assertCount(1, $payload['items']);
        self::assertSame('Wanderstock', $payload['items'][0]['productName']);
        self::assertSame('1', $payload['items'][0]['quantity'], 'Menge positiv, Beträge negativ — die Regel des Stornos');
        self::assertSame('-30.0000', $payload['items'][0]['lineTotal']);
        self::assertSame('-25.0000', $payload['items'][0]['unitPrice']);
        self::assertTrue(BigDecimal::of('-30')->isEqualTo($payload['order']['grandTotal']));

        // No cancellation (not everything refunded, F3), no second invoice.
        self::assertSame([], $this->documentIds(DocumentType::Cancellation));
        self::assertSame([$invoice], $this->documentIds(DocumentType::Invoice));

        // The withdrawal carries what was booked.
        $booked = $this->withdrawalRow($withdrawal);
        self::assertTrue(BigDecimal::of('30')->isEqualTo((string) $booked['refundAmount']));
        self::assertSame($refundedAt, (int) $booked['refundedAt']);
        self::assertSame((int) $payment['id'], (int) $booked['refundPayment']);
        self::assertSame($corrections[0], (int) $booked['refundDocument']);

        // After that everything is done: claim 70, paid 70, nothing left to deliver.
        $balance = $this->balance();
        self::assertTrue(BigDecimal::of('70')->isEqualTo($balance->claim));
        self::assertTrue(BigDecimal::of('70')->isEqualTo($balance->paid));
        self::assertSame(PaymentState::Paid, $balance->paymentState);
        self::assertTrue($balance->settled());

        // … and "status when everything is done" applies (F10).
        self::assertSame(self::DONE, (int) $this->orderRow()['orderStatus']);

        // The refund mail, with the correction attached.
        $sent = $this->transport->sent();
        self::assertCount(1, $sent);
        $mail = $sent[0];
        $html = (string) $mail->getHtmlBody();
        self::assertSame('Ihr Widerruf WEB-00074-W1 zur Bestellung WEB-00074: Erstattung', $mail->getSubject());
        self::assertStringContainsString('Wir erstatten Ihnen 30,00', $html);
        self::assertStringContainsString('Die Belege finden Sie im Anhang: RK-00001.', $html);
        self::assertStringNotContainsString('verbleibenden Betrag', $html, 'bezahlt war alles — es bleibt nichts zu überweisen');
        self::assertSame(['RK-00001.pdf'], $this->attachmentNames($mail));
        self::assertContains($corrections[0], $this->archived->getArrayCopy());
        $this->assertCleanMail($mail);

        // Booking again is not possible.
        self::assertSame('alreadyRefunded', $this->processing->refund($withdrawal, '0', $refundedAt + 60)->key);
        self::assertCount(1, $this->documentIds(DocumentType::CreditNote));
        self::assertSame([], $this->logger->errors);
    }

    // ── 5. The period at its limits ───────────────────────────────────────

    /**
     * Period 14 days, buffer 7 days (the defaults, W4 / Spec D 6.2), shipped
     * on 1 October 2026 at 10:00 in Vienna.
     *
     * The expectations are written as calendar dates, not as a formula: the
     * last day of the period is 15 October, the function closes "at the
     * earliest after last shipment + period + buffer" (6.2) — at the end of
     * 22 October, 23:59:59 Vienna time (still summer time). If production
     * computed in UTC, the end would be two hours later, and a declaration at
     * midnight would go through.
     */
    public function testDieFristIstBisZumLetztenPuffertagOffenUndDanachZu(): void
    {
        $this->order('WEB-00075', self::PREPAYMENT);
        $parcel = $this->parcel([self::WANDERSTOCK => '2.0000', self::RUCKSACK => '1.0000'], self::at('2026-10-01 10:00:00'), '', '');
        self::assertSame('reported', $this->deliveries->report($parcel, self::at('2026-10-01 10:00:00'))->outcome);
        $this->transport->sent = [];

        $window = $this->windows->of(self::ORDER);
        self::assertNotNull($window);
        self::assertSame(self::at('2026-10-22 23:59:59'), $window->closesAt);

        // The last day of the period: open.
        $lastDay = $this->recorder->record($this->wholeOrder(), self::at('2026-10-15 23:59:59'));
        self::assertSame(WithdrawalOutcome::Recorded, $lastDay->outcome);

        // The last second of the buffer: open — a day too late costs the
        // operator nothing, a day too early is a violation (WithdrawalWindow).
        $lastSecond = $this->recorder->record($this->wholeOrder(), self::at('2026-10-22 23:59:59'));
        self::assertSame(WithdrawalOutcome::Recorded, $lastSecond->outcome);

        // The first second after it: since WA no longer closed, but
        // stored and flagged — the window counts from shipping, the period
        // from receipt; whether it was in time, the operator decides.
        $late = $this->recorder->record($this->wholeOrder(), self::at('2026-10-23 00:00:00'));
        self::assertSame(WithdrawalOutcome::Recorded, $late->outcome);
        self::assertTrue($late->afterWindow);
        self::assertFalse($lastSecond->afterWindow, 'die letzte Sekunde des Puffers ist noch im Fenster');

        self::assertSame(
            [['WEB-00075-W1', self::at('2026-10-15 23:59:59'), 0], ['WEB-00075-W2', self::at('2026-10-22 23:59:59'), 0], ['WEB-00075-W3', self::at('2026-10-23 00:00:00'), 1]],
            array_map(
                static fn (array $row): array => [(string) $row['number'], (int) $row['receivedAt'], (int) $row['afterWindow']],
                $this->connection->fetchAllAssociative('SELECT number, receivedAt, afterWindow FROM tl_consho_withdrawal WHERE pid = ? ORDER BY id', [self::ORDER]),
            ),
        );
        $sent = $this->transport->sent();
        self::assertSame(
            array_merge(...array_fill(0, 3, ['Eingangsbestätigung: Widerruf zur Bestellung WEB-00075', 'Widerruf eingegangen: Bestellung WEB-00075'])),
            array_map(static fn (Email $mail): string => (string) $mail->getSubject(), $sent),
        );
        self::assertStringContainsString('22. Oktober 2026', (string) $sent[2]->getHtmlBody());
        self::assertStringContainsString('23:59:59', (string) $sent[2]->getHtmlBody());
        self::assertSame([], $this->logger->errors);
    }

    // ── Aufbau ────────────────────────────────────────────────────────────

    private function wire(): void
    {
        $orders = new DbalPaymentChangeRepository($this->connection);
        $this->balances = new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator());
        $payloads = new DocumentPayloadBuilder();
        $money = new MoneyFormatter();
        $documentRows = new DbalDocumentRepository($this->connection);

        $this->issuer = new DocumentIssuer(
            $documentRows,
            new NumberRangeAllocator($this->connection),
            $payloads,
            // The file system is only asked for a logo; the shop has none.
            new DbalDocumentSource($this->connection, new TranslationStorage($this->connection), new ShopLanguages($this->connection), $this->createStub(VirtualFilesystemInterface::class), $this->balances),
        );

        // CONSHO_TEST_PAYMENT=1: without the switch PaymentHandlers does not know the test provider.
        $handlers = new PaymentHandlers(new ServiceLocator([
            PrepaymentHandler::getType() => static fn (): PaymentHandlerInterface => new PrepaymentHandler(),
            TestPaymentHandler::getType() => fn (): PaymentHandlerInterface => new TestPaymentHandler($this->createStub(UrlGeneratorInterface::class)),
            InvoicePaymentHandler::getType() => static fn (): PaymentHandlerInterface => new InvoicePaymentHandler(),
        ]), '1');

        $writer = new class($this->connection) implements OrderStatusWriterInterface {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function write(int $orderId, StatusFlags $status, int $now): void
            {
                $this->connection->update('tl_consho_order', ['orderStatus' => $status->id, 'tstamp' => $now], ['id' => $orderId]);
            }
        };

        $statuses = new OrderStatusAdvancer($this->connection, new DbalOrderStatusRepository($this->connection), $this->balances, $writer);

        $archiver = new class($this->archived) implements DocumentArchiverInterface {
            /**
             * @param \ArrayObject<int, int> $archived
             */
            public function __construct(private readonly \ArrayObject $archived)
            {
            }

            public function archive(int $documentId): void
            {
                $this->archived->append($documentId);
            }
        };

        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn(self::VIEW_URL);

        $mail = $this->mail($payloads, $money);
        $methods = new DbalPaymentMethodRepository($this->connection);

        $this->payments = new PaymentRecorded($this->connection, $orders, $this->balances, $methods, $handlers, $this->issuer, $archiver, $statuses, $links, $mail, $this->logger);
        $this->deliveries = new DeliveryReported($this->connection, $orders, $this->balances, $methods, $handlers, $this->issuer, $archiver, $statuses, $links, $mail, $money, $this->logger);
        $this->windows = new WithdrawalWindowReader($this->connection, $this->balances);
        $this->recorder = new WithdrawalRecorder($this->connection, $this->windows, new DbalWithdrawalRepository($this->connection), $mail, $links, $this->logger);
        $this->processing = new WithdrawalProcessing($this->connection, $orders, $documentRows, $this->issuer, $archiver, $payloads, $this->balances, $statuses, $links, $mail, $money);
    }

    private function mail(DocumentPayloadBuilder $payloads, MoneyFormatter $money): OrderEventMail
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../contao/templates', 'Contao');

        $translator = new ContaoLikeTranslator();
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));

        $factory = new class($this->transport) implements MailTransportFactoryInterface {
            public function __construct(private readonly TransportInterface $transport)
            {
            }

            public function create(array $row): TransportInterface
            {
                return $this->transport;
            }
        };

        // As DocumentDelivery does: the file name is the document number. Read,
        // not invented — a wrong document in the attachment would show this way.
        $pdfs = new class($this->connection) implements DocumentPdfInterface {
            public function sentElsewhere(int $documentId): bool
            {
                return false;
            }

            public function __construct(private readonly Connection $connection)
            {
            }

            public function pdf(int $documentId): array|null
            {
                $number = $this->connection->fetchOne('SELECT documentNumber FROM tl_consho_document WHERE id = ?', [$documentId]);

                return false === $number ? null : ['pdf' => '%PDF-'.$number, 'fileName' => $number.'.pdf', 'fromArchive' => false, 'archiveProblem' => false, 'logoMissing' => false];
            }
        };

        return new OrderEventMail(
            new DbalOrderConfirmationSource($this->connection),
            new MailRenderer($twig, $translator),
            new RecipientResolver(),
            $factory,
            $payloads,
            new InvoiceView($money, $payloads),
            $pdfs,
            new \Webwerkwien\ContaoConshoBundle\Voucher\VoucherDocument($this->connection, $twig, $translator, $this->createStub(\Webwerkwien\ContaoConshoBundle\Document\Pdf\PdfRendererInterface::class), $money),
            $this->logger,
            $money,
        );
    }

    private function createSchema(): void
    {
        $c = $this->connection;

        // From the DCA, keys included (unique orderNumber, number range per
        // pid/type, document number per shop/type) — a hand-written schema goes
        // stale with the next column.
        foreach ([
            'tl_consho_shop', 'tl_consho_mail_transport', 'tl_consho_order_status', 'tl_consho_number_range',
            'tl_consho_payment_method', 'tl_consho_order', 'tl_consho_order_item', 'tl_consho_payment',
            'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_document',
        ] as $table) {
            DcaSchema::create($c, $table);
        }

        WithdrawalSchema::create($c);
    }

    private function seedShop(): void
    {
        $this->connection->insert('tl_consho_mail_transport', ['id' => 1, 'title' => 'Shop', 'senderAddress' => 'shop@shop.test', 'senderName' => 'Muster Wanderbedarf']);

        $this->connection->insert('tl_consho_shop', [
            'id' => self::SHOP,
            'title' => 'Wanderbedarf',
            'fallbackLanguage' => 'de',
            'vatId' => 'ATU12345678',
            'statusAfterPayment' => self::PAID,
            'statusAfterDelivery' => self::SHIPPED,
            'statusWhenSettled' => self::DONE,
            'mailTransport' => 1,
            'invoiceCompany' => 'Muster Wanderbedarf',
            'invoiceStreet' => 'Musterstraße 1',
            'invoicePostal' => '1100',
            'invoiceCity' => 'Wien',
            'invoiceCountry' => 'AT',
            'withdrawalDays' => 14,
            'withdrawalGraceDays' => 7,
            'operatorName' => 'Muster Wanderbedarf',
            'operatorAddress' => 'office@shop.test',
        ]);

        foreach ([
            [self::OPEN, 'Offen', []],
            [self::PAID, 'Bezahlt', ['isPaid' => 1]],
            [self::SHIPPED, 'Versendet', ['isShipped' => 1]],
            [self::DONE, 'Abgeschlossen', ['isCompleted' => 1]],
        ] as $sorting => [$id, $title, $flags]) {
            $this->connection->insert('tl_consho_order_status', ['id' => $id, 'pid' => self::SHOP, 'sorting' => ($sorting + 1) * 128, 'title' => $title, ...$flags]);
        }

        foreach (['order' => 'WEB-', 'invoice' => 'RE-', 'creditNote' => 'RK-', 'cancellation' => 'ST-'] as $type => $prefix) {
            $this->connection->insert('tl_consho_number_range', ['pid' => self::SHOP, 'type' => $type, 'prefix' => $prefix, 'padding' => 5, 'current' => 0]);
        }

        $this->connection->insert('tl_consho_payment_method', ['id' => self::PREPAYMENT, 'title' => 'Vorkasse', 'type' => PrepaymentHandler::getType(), 'published' => 1, 'instructions' => '<p>Bitte überweisen Sie den Betrag.</p>',
            'jsonData' => json_encode(['accountHolder' => 'Muster Wanderbedarf', 'iban' => 'AT61 1904 3002 3457 3201'])]);
        $this->connection->insert('tl_consho_payment_method', ['id' => self::ONLINE, 'title' => 'Testzahlung', 'type' => TestPaymentHandler::getType(), 'published' => 1]);
        $this->connection->insert('tl_consho_payment_method', ['id' => self::ON_SHIPMENT, 'title' => 'Auf Rechnung', 'type' => InvoicePaymentHandler::getType(), 'published' => 1,
            'jsonData' => json_encode(['dueDays' => '14', 'accountHolder' => 'Wanderladen', 'iban' => 'AT611904300234573201'])]);
    }

    private function order(string $number, int $method): void
    {
        $this->connection->insert('tl_consho_order', [
            'id' => self::ORDER,
            'tstamp' => 1,
            'createdAt' => self::at('2026-09-29 12:00:00'),
            'language' => 'de',
            'shop' => self::SHOP,
            'orderNumber' => $number,
            'orderStatus' => self::OPEN,
            'currency' => 'EUR',
            'netTotal' => '83.3334',
            'taxTotal' => '16.6666',
            'grandTotal' => '100.0000',
            'shippingMethodTitle' => 'Paket',
            'paymentMethod' => $method,
            'paymentMethodTitle' => (string) $this->connection->fetchOne('SELECT title FROM tl_consho_payment_method WHERE id = ?', [$method]),
            'termsAcceptedAt' => self::at('2026-09-29 12:00:00'),
            'salutation' => 'Frau',
            'firstname' => 'Erika',
            'lastname' => 'Muster',
            'email' => 'erika@example.org',
            'billingStreet' => 'Hauptstraße 1',
            'billingPostal' => '1100',
            'billingCity' => 'Wien',
            'billingCountry' => 'AT',
            'shippingStreet' => 'Hauptstraße 1',
            'shippingPostal' => '1100',
            'shippingCity' => 'Wien',
            'shippingCountry' => 'AT',
        ]);

        foreach ([
            [self::WANDERSTOCK, 'Wanderstock', 'WS-1', '2.0000', '25.0000', '10.0000', '60.0000', 'product'],
            [self::RUCKSACK, 'Rucksack', 'RS-1', '1.0000', '29.1667', '5.8333', '35.0000', 'product'],
            [self::PAKET, 'Paket', '', '1.0000', '4.1667', '0.8333', '5.0000', 'shipping'],
        ] as $i => [$id, $name, $sku, $quantity, $unit, $tax, $total, $type]) {
            $this->connection->insert('tl_consho_order_item', [
                'id' => $id, 'pid' => self::ORDER, 'sorting' => ($i + 1) * 128, 'tstamp' => 1, 'lineType' => $type,
                'productName' => $name, 'sku' => $sku, 'quantity' => $quantity, 'unitPrice' => $unit,
                'taxRate' => '20.0000', 'taxAmount' => $tax, 'lineTotal' => $total,
            ]);
        }
    }

    /**
     * The invoice at purchase — as OrderPlacer issues it when the payment
     * method's handler says OnOrder. Whether it says so, the test asks the
     * original instead of assuming it.
     */
    private function purchaseInvoice(): int
    {
        self::assertSame(DocumentTrigger::OnOrder, (new PrepaymentHandler())->documentTrigger([]));

        return $this->issuer->issueOnce(self::ORDER, self::SHOP, DocumentType::Invoice);
    }

    /**
     * A delivery as the backend creates it — it is only reported through
     * DeliveryReported.
     *
     * @param array<int, string> $items order item id => quantity
     */
    private function parcel(array $items, int $shippedAt, string $carrier, string $tracking): int
    {
        $this->connection->insert('tl_consho_delivery', ['pid' => self::ORDER, 'tstamp' => 1, 'shippedAt' => $shippedAt, 'carrier' => $carrier, 'trackingNumber' => $tracking]);
        $id = (int) $this->connection->lastInsertId();

        foreach ($items as $item => $quantity) {
            $this->connection->insert('tl_consho_delivery_item', ['pid' => $id, 'tstamp' => 1, 'orderItem' => $item, 'quantity' => $quantity]);
        }

        return $id;
    }

    private function wholeOrder(): WithdrawalDeclaration
    {
        return new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'Erika Muster');
    }

    private static function at(string $moment): int
    {
        return (new \DateTimeImmutable($moment, new \DateTimeZone('Europe/Vienna')))->getTimestamp();
    }

    // ── Lesen ─────────────────────────────────────────────────────────────

    private function balance(): OrderBalance
    {
        $balance = $this->balances->of(self::ORDER);
        self::assertNotNull($balance);

        return $balance;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [self::ORDER]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function withdrawalRow(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_withdrawal WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<int>
     */
    private function documentIds(DocumentType $type): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_document WHERE pid = ? AND type = ? ORDER BY id', [self::ORDER, $type->value]));
    }

    private function documentNumber(int $id): string
    {
        return (string) $this->connection->fetchOne('SELECT documentNumber FROM tl_consho_document WHERE id = ?', [$id]);
    }

    /**
     * @return array{order: array<string, string>, items: list<array<string, string>>, issuer: array<string, string>, corrects: array<string, string>, withdrawal: string, payment: array{details: array<string, string>, dueDate?: string}, delivery: array<string, string>|null}
     */
    private function payload(int $id): array
    {
        $payload = json_decode((string) $this->connection->fetchOne('SELECT payload FROM tl_consho_document WHERE id = ?', [$id]), true);
        self::assertIsArray($payload);

        /** @var array{order: array<string, string>, items: list<array<string, string>>, issuer: array<string, string>, corrects: array<string, string>, withdrawal: string, payment: array{details: array<string, string>, dueDate?: string}, delivery: array<string, string>|null} */
        return $payload + ['corrects' => [], 'withdrawal' => '', 'payment' => ['details' => []], 'delivery' => null];
    }

    private function rangeCurrent(string $type): int
    {
        return (int) $this->connection->fetchOne('SELECT current FROM tl_consho_number_range WHERE pid = ? AND type = ?', [self::SHOP, $type]);
    }

    /**
     * @return list<string>
     */
    private function attachmentNames(Email $mail): array
    {
        return array_map(static fn ($part): string => (string) $part->getFilename(), $mail->getAttachments());
    }

    /**
     * No untranslated key, no raw decimal value.
     */
    private function assertCleanMail(Email $mail): void
    {
        $html = (string) $mail->getHtmlBody();

        self::assertStringNotContainsString('CONSHO.', $html.$mail->getSubject());
        // "2.0000" yes, the date "29.09.2026" no.
        self::assertDoesNotMatchRegularExpression('/(?<![\d.])\d+\.\d{4}\b/', $html, 'ein decimal(…,4) roh in der Mail');
    }
}

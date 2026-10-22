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

// Übersetzer, Spion-Transport und Fehler-Logger der Bestätigungsmail.
require_once __DIR__.'/../Notification/OrderConfirmationTest.php';

/**
 * Nach dem Kauf, gegen die Bestellung (Spec D, Abschnitt 11; Entscheidung M1,
 * Michael 2026-09-28: „Integrationstest je Ereignis wie
 * CheckoutAgreesWithOrderTest: echte Dienste, SQLite, kein Doppel, das die
 * Frage vorwegnimmt").
 *
 * Bis hierher ersetzte jeder Ereignis-Test genau die Mitspieler, die die
 * Frage entscheiden: Ob wirklich eine Rechnung entsteht, beantwortete ein
 * Stub von DocumentIssuerInterface, ob der Status wechselt, ein Spion am
 * Advancer, ob die Mail hinausgeht, ein Double von OrderEventMail. Hier
 * laufen die ECHTEN Dienste gegeneinander — Ereignis, Saldo, Nummernkreis,
 * Beleg samt Schnappschuss, Statuswechsel, Widerruf, Mail mit den echten
 * Vorlagen und Sprachdateien — und geprüft wird der ENDSTAND: was in der
 * Datenbank steht und welche Mails der Transport gesehen hat.
 *
 * ── Was ein Double bleibt, und warum es nichts entscheidet ───────────────
 *
 *  - Das PDF (DocumentPdfInterface, DocumentArchiverInterface): ein fester
 *    Text statt Dompdf. Der Dateiname kommt wie im Original aus der
 *    Belegnummer — gelesen aus der Datenbank, nicht mitgegeben.
 *  - Der Link zur Bestellseite (OrderPageLink): eine feste Adresse. Das
 *    Original braucht Contaos Framework und die Seitenstruktur.
 *  - Der Status-SCHREIBER: schreibt nur tl_consho_order.orderStatus.
 *    ContaoOrderStatusWriter braucht Contaos Framework und `Versions` (die
 *    DCA von tl_consho_order, tl_version) — WELCHER Status gesetzt wird,
 *    entscheidet der echte OrderStatusAdvancer davor. Weniger als das
 *    Original: keine Version, kein cancelledAt (kein Fall hier storniert).
 *  - Die Mail-Verbindung (MailTransportFactoryInterface): der Spion.
 *  - Kein Zahlungs-Handler mehr: „Rechnung bei Versand" läuft seit E-2 über
 *    die echte Zahlungsart „Auf Rechnung" (InvoicePaymentHandler). Bis dahin
 *    stand hier ein Ersatz, weil keine Kern-Zahlungsart bei Versand
 *    ausstellte (D-7, M2).
 *
 * ── FOR UPDATE ─────────────────────────────────────────────────────────────
 *
 * SQLite kennt kein `FOR UPDATE`; lockOrder() und der Nummernkreis schreiben
 * es aus. Statt beide wegzudoubeln (wie die bisherigen Tests) streicht eine
 * DBAL-Middleware genau diese zwei Wörter am Ende einer Anweisung und merkt
 * sich die Anweisung — alles andere geht unverändert an die Datenbank. So
 * laufen die echten Abfragen, und der Test sieht, DASS gesperrt wurde.
 *
 * ── Das Schema ─────────────────────────────────────────────────────────────
 *
 * Spaltennamen, NULL-barkeit und Vorgaben wie in contao/dca/*.php (gelesen
 * 2026-09-28; Prüfungsnotiz, Fall 31). decimal-Spalten als TEXT: MySQL gibt
 * `2.0000` zurück, eine NUMERIC-Spalte in SQLite `2` — und genau dieser
 * Unterschied hat „2.0000 × Wanderstock" in eine Mail gebracht, ohne dass
 * ein Test es sah (Fall 30). Die Widerrufstabellen aus WithdrawalSchema.
 *
 * Bestellung: 2 × Wanderstock (60,00), 1 × Rucksack (35,00), Versand 5,00 —
 * zusammen 100,00 € brutto, 20 % USt.
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

    /** @var list<string> Anweisungen, die mit FOR UPDATE an die Datenbank gingen */
    private static array $rowLocks = [];

    private Connection $connection;

    private SpyTransport $transport;

    private ErrorSpyLogger $logger;

    /** @var \ArrayObject<int, int> was ins Archiv sollte — die Belege, die ein Ereignis ausgestellt hat */
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
        // Die Frist endet „am Ende des Tages … in der Zeitzone der
        // Installation" (WithdrawalWindow). Die Installation ist Wien.
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

    // ── 1. Vorkasse: Rechnung beim Kauf, Zahlung im Backend ───────────────

    public function testVorkasseImBackendBezahltSperrtDieZahlungSetztDenStatusUndStelltKeineZweiteRechnungAus(): void
    {
        $this->order('WEB-00071', self::PREPAYMENT);
        $invoice = $this->purchaseInvoice();

        self::assertSame('RE-00001', $this->documentNumber($invoice));

        // Wie das Backend: DC_Table speichert die Zeile, der Callback ruft
        // PaymentRecorded (PaymentRecordListener) — ohne Kopie, der Betreiber
        // hat sie selbst erfasst (Z3).
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'tstamp' => 1, 'receivedAt' => self::at('2026-10-01 09:00:00'), 'amount' => '100.0000', 'method' => 'Überweisung', 'reference' => 'WEB-00071']);
        $now = self::at('2026-10-01 09:05:00');

        $this->payments->handle(self::ORDER, false, $now);

        // Saldo erledigt (A1), Zahlung gesperrt (A2), Status nach Zahlung (5.3).
        $balance = $this->balance();
        self::assertSame(PaymentState::Paid, $balance->paymentState);
        self::assertTrue($balance->paymentSettled());
        self::assertSame($now, (int) $this->orderRow()['paymentSettledAt']);
        self::assertSame([$now], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT lockedAt FROM tl_consho_payment')));
        self::assertSame(self::PAID, (int) $this->orderRow()['orderStatus'], '„Status nach vollständiger Zahlung" — nicht „wenn alles erledigt": geliefert ist nichts');

        // Keine zweite Rechnung: dieselbe Zeile, der Nummernkreis unberührt.
        self::assertSame([$invoice], $this->documentIds(DocumentType::Invoice));
        self::assertSame(1, $this->rangeCurrent('invoice'));
        self::assertSame([], $this->archived->getArrayCopy(), 'nichts Neues zu archivieren');

        // Genau eine Mail, an die Kundin; ohne Anhang, denn die Rechnung hat sie seit dem Kauf.
        self::assertCount(1, $this->transport->sent);
        $mail = $this->transport->sent[0];
        self::assertSame('erika@example.org', $mail->getTo()[0]->getAddress());
        self::assertSame('Zahlung erhalten: Bestellung WEB-00071', $mail->getSubject());
        self::assertSame([], $mail->getAttachments());
        self::assertStringNotContainsString('Anhang', (string) $mail->getHtmlBody());
        self::assertStringContainsString(self::VIEW_URL, (string) $mail->getHtmlBody());
        $this->assertCleanMail($mail);

        // Die Bestellung wurde gesperrt (5.4, Nebenläufigkeit).
        self::assertNotEmpty(array_filter(self::$rowLocks, static fn (string $sql): bool => str_contains($sql, 'FROM tl_consho_order WHERE')));

        // Ein zweiter Aufruf (Callback erneut, Version wiederhergestellt …) ändert nichts.
        $this->payments->handle(self::ORDER, false, $now + 60);

        self::assertCount(1, $this->transport->sent);
        self::assertSame($now, (int) $this->orderRow()['paymentSettledAt']);
        self::assertSame([], $this->logger->errors);
    }

    // ── 2. Online bezahlt: Rechnung bei Zahlung ───────────────────────────

    public function testOnlineBezahltStelltGenauEineRechnungMitDerNaechstenNummerAus(): void
    {
        $this->order('WEB-00072', self::ONLINE);

        // Vorher schon zwei Rechnungen dieses Shops: die nächste ist RE-00003.
        $this->connection->update('tl_consho_number_range', ['current' => 2], ['pid' => self::SHOP, 'type' => 'invoice']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_document'), 'bei Zahlung: vor der Zahlung keine Rechnung');

        // Wie NotificationProcessor: die Zeile des Versuchs, dann die Folgen (online).
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'tstamp' => 1, 'receivedAt' => self::at('2026-10-01 09:00:00'), 'amount' => '100.0000', 'method' => 'test', 'reference' => 'TEST-1', 'attempt' => 1]);
        $now = self::at('2026-10-01 09:00:05');

        $this->payments->handle(self::ORDER, true, $now);

        $invoices = $this->documentIds(DocumentType::Invoice);
        self::assertCount(1, $invoices);
        self::assertSame('RE-00003', $this->documentNumber($invoices[0]), 'die nächste Nummer aus dem echten Nummernkreis');
        self::assertSame(3, $this->rangeCurrent('invoice'));
        self::assertSame($invoices, $this->archived->getArrayCopy());

        // Der Schnappschuss ist geschrieben — aus der Bestellung, nicht aus einer Vorlage im Test.
        $payload = $this->payload($invoices[0]);
        self::assertSame('WEB-00072', $payload['order']['orderNumber']);
        self::assertTrue(BigDecimal::of('100')->isEqualTo($payload['order']['grandTotal']));
        self::assertSame(['Wanderstock', 'Rucksack', 'Paket'], array_column($payload['items'], 'productName'));
        self::assertSame('Muster Wanderbedarf', $payload['issuer']['company']);

        self::assertSame(self::PAID, (int) $this->orderRow()['orderStatus']);
        self::assertSame($now, (int) $this->connection->fetchOne('SELECT lockedAt FROM tl_consho_payment'));

        // Kundin mit der Rechnung im Anhang, Betreiber mit Kopie (Z3: online).
        self::assertCount(2, $this->transport->sent);
        [$customer, $operator] = $this->transport->sent;
        self::assertSame('Zahlung erhalten: Bestellung WEB-00072', $customer->getSubject());
        self::assertStringContainsString('Die Rechnung RE-00003 finden Sie im Anhang.', (string) $customer->getHtmlBody());
        self::assertSame(['RE-00003.pdf'], $this->attachmentNames($customer));
        self::assertSame('office@shop.test', $operator->getTo()[0]->getAddress());
        self::assertSame('Zahlung eingegangen: Bestellung WEB-00072', $operator->getSubject());
        $this->assertCleanMail($customer);
        $this->assertCleanMail($operator);

        // Die Meldung kommt noch einmal: keine zweite Rechnung, keine Nummer verbraucht, keine Mail.
        $this->payments->handle(self::ORDER, true, $now + 30);

        self::assertCount(1, $this->documentIds(DocumentType::Invoice));
        self::assertSame(3, $this->rangeCurrent('invoice'));
        self::assertCount(2, $this->transport->sent);
        self::assertSame([], $this->logger->errors);
    }

    // ── 3. Rechnung bei Versand ───────────────────────────────────────────

    public function testRechnungBeiVersandEntstehtMitDerMeldungUndGehtMitDerVersandmail(): void
    {
        $this->order('WEB-00073', self::ON_SHIPMENT);
        $parcel = $this->parcel([self::WANDERSTOCK => '2.0000', self::RUCKSACK => '1.0000'], self::at('2026-10-01 10:00:00'), 'Post AT', 'CA123456789AT');
        $now = self::at('2026-10-01 10:30:00');

        self::assertSame(PaymentState::Open, $this->balance()->paymentState, 'unbezahlt');

        $report = $this->deliveries->report($parcel, $now);

        self::assertSame('reported', $report->outcome);
        self::assertTrue($report->mailSent);

        // Genau eine Rechnung, über die ganze Bestellung (Z2).
        $invoices = $this->documentIds(DocumentType::Invoice);
        self::assertCount(1, $invoices);
        self::assertSame('RE-00001', $this->documentNumber($invoices[0]));
        self::assertCount(3, $this->payload($invoices[0])['items']);
        self::assertSame($invoices, $this->archived->getArrayCopy());

        // E-2: Die Rechnung nennt „Zahlbar bis" — 14 Tage ab ihrem Datum —,
        // und die Bestellung merkt sich das Ende für den Hinweis „überfällig".
        $issuedAt = (int) $this->connection->fetchOne('SELECT issuedAt FROM tl_consho_document WHERE id = ?', [$invoices[0]]);
        $due = (new \DateTimeImmutable('@'.$issuedAt))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->modify('+14 days');
        self::assertSame($due->format('Y-m-d'), $this->payload($invoices[0])['payment']['dueDate']);
        self::assertSame($due->setTime(23, 59, 59)->getTimestamp(), (int) $this->orderRow()['paymentDueAt']);
        self::assertSame('AT611904300234573201', $this->payload($invoices[0])['payment']['details']['iban'], 'die Bankverbindung für die Überweisung');

        // E-6b: Beim Versand ist der Tag der Lieferung bekannt — alles in
        // einem Paket, also „geliefert" am Versandtag.
        $shipped = date('Y-m-d', self::at('2026-10-01 10:00:00'));
        self::assertSame(['from' => $shipped, 'to' => $shipped, 'kind' => 'delivered'], $this->payload($invoices[0])['delivery']);

        // Lieferung gemeldet und gesperrt (A2), Lieferstand vollständig, Status nach Lieferung.
        $delivery = $this->connection->fetchAssociative('SELECT reportedAt, lockedAt FROM tl_consho_delivery WHERE id = ?', [$parcel]);
        self::assertSame([$now, $now], array_map(intval(...), array_values((array) $delivery)));
        $balance = $this->balance();
        self::assertSame(DeliveryState::Complete, $balance->deliveryState);
        self::assertTrue($balance->deliverySettled());
        self::assertSame(PaymentState::Open, $balance->paymentState);
        self::assertSame(self::SHIPPED, (int) $this->orderRow()['orderStatus'], '„nach vollständiger Lieferung" — nicht „erledigt": bezahlt ist nichts');

        // Die Versandmail nennt, was im Paket ist, und bringt die Rechnung mit (Z4).
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

        // Ein zweites Melden derselben Lieferung: abgewiesen, keine zweite Rechnung.
        self::assertSame('alreadyReported', $this->deliveries->report($parcel, $now + 60)->outcome);
        self::assertCount(1, $this->documentIds(DocumentType::Invoice));
        self::assertSame(1, $this->rangeCurrent('invoice'));
        self::assertCount(1, $this->transport->sent);
        self::assertSame([], $this->logger->errors);
    }

    // ── 4. Widerruf: erklärt, angenommen, zurück, erstattet ───────────────

    public function testTeilwiderrufVonDerErklaerungBisZurErstattung(): void
    {
        // Der Shop, wie er vor D-1 verkaufte: ohne „Status, wenn alles
        // erledigt ist" (das Feld kam mit D-1). Mit dem Feld von Anfang an
        // stünde die Bestellung nach Zahlung und Versand schon auf
        // „Abgeschlossen" — und aus einem Status mit isCompleted führt kein
        // automatischer Wechsel (StatusFlags::holds()); F10 wäre nicht zu sehen.
        $this->connection->update('tl_consho_shop', ['statusWhenSettled' => 0], ['id' => self::SHOP]);

        // ── Ausgangslage über die echten Ereignisse: Vorkasse, bezahlt, versandt.
        $this->order('WEB-00074', self::PREPAYMENT);
        $invoice = $this->purchaseInvoice();
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'tstamp' => 1, 'receivedAt' => self::at('2026-09-30 09:00:00'), 'amount' => '100.0000', 'method' => 'Überweisung']);
        $this->payments->handle(self::ORDER, false, self::at('2026-09-30 09:05:00'));
        $parcel = $this->parcel([self::WANDERSTOCK => '2.0000', self::RUCKSACK => '1.0000'], self::at('2026-10-01 10:00:00'), 'Post AT', 'CA1AT');
        self::assertSame('reported', $this->deliveries->report($parcel, self::at('2026-10-01 10:00:00'))->outcome);

        self::assertTrue($this->balance()->settled(), 'bezahlt und versandt');
        self::assertSame(self::SHIPPED, (int) $this->orderRow()['orderStatus']);

        // Jetzt stellt der Betreiber das Feld ein.
        $this->connection->update('tl_consho_shop', ['statusWhenSettled' => self::DONE], ['id' => self::SHOP]);
        $this->transport->sent = [];

        // ── Die Erklärung: 1 von 2 Wanderstöcken, über die Bestellseite (W3).
        $received = self::at('2026-10-05 14:03:05');
        $result = $this->recorder->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'Erika Muster', [self::WANDERSTOCK => '1']), $received);

        self::assertSame(WithdrawalOutcome::Recorded, $result->outcome);
        self::assertSame('WEB-00074-W1', $result->number, 'aus der Bestellnummer, kein Nummernkreis (E3)');
        self::assertTrue($result->confirmed);
        $withdrawal = (int) $result->id;
        self::assertSame('received', $this->withdrawalRow($withdrawal)['state']);
        self::assertSame($received, (int) $this->withdrawalRow($withdrawal)['receivedAt']);
        self::assertNotNull($this->withdrawalRow($withdrawal)['confirmationSentAt']);

        // Eingangsbestätigung mit Datum UND Uhrzeit (Spec D 2, Punkt 4), Kopie an den Betreiber.
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

        // ── Annehmen: Die Forderung sinkt um die Ware (F7), die Zahlung steht noch.
        $accepted = $this->processing->accept($withdrawal, [self::WANDERSTOCK => '1'], self::at('2026-10-06 08:00:00'));

        self::assertTrue($accepted->done, $accepted->key);
        self::assertSame('accepted', $this->withdrawalRow($withdrawal)['state']);
        self::assertTrue(BigDecimal::zero()->isEqualTo((string) $this->withdrawalRow($withdrawal)['refundShipping']), 'Teilwiderruf: kein Versand vorgeschlagen (F1)');
        $balance = $this->balance();
        self::assertTrue(BigDecimal::of('70')->isEqualTo($balance->claim));
        self::assertSame(PaymentState::Overpaid, $balance->paymentState, 'bis zur Erstattung ist zu viel bezahlt');
        self::assertFalse($balance->settled());
        self::assertSame(self::SHIPPED, (int) $this->orderRow()['orderStatus']);

        // ── Rücksendung (F8).
        $row = (int) $this->connection->fetchOne('SELECT id FROM tl_consho_withdrawal_item WHERE pid = ?', [$withdrawal]);
        self::assertTrue($this->processing->recordReturn($withdrawal, self::at('2026-10-09 11:00:00'), [$row => '1'], self::at('2026-10-09 11:05:00'))->done);
        self::assertSame(self::at('2026-10-09 11:00:00'), (int) $this->withdrawalRow($withdrawal)['returnReceivedAt']);
        self::assertTrue(BigDecimal::one()->isEqualTo((string) $this->connection->fetchOne('SELECT returnedQuantity FROM tl_consho_withdrawal_item WHERE id = ?', [$row])));
        self::assertSame([], $this->transport->sent, 'Annehmen und Rücksendung schreiben keine Mail');

        // ── Erstattung buchen (F3, F4).
        $refundedAt = self::at('2026-10-09 11:10:00');
        $refund = $this->processing->refund($withdrawal, '0', $refundedAt);

        self::assertTrue($refund->done, $refund->key);
        self::assertSame('refunded', $refund->key);

        // Die negative Zahlung, sofort gesperrt.
        $payment = $this->connection->fetchAssociative('SELECT id, amount, method, reference, lockedAt FROM tl_consho_payment WHERE pid = ? AND id != (SELECT MIN(id) FROM tl_consho_payment)', [self::ORDER]);
        self::assertIsArray($payment);
        self::assertTrue(BigDecimal::of('-30')->isEqualTo((string) $payment['amount']));
        self::assertSame('refund', $payment['method']);
        self::assertSame('WEB-00074-W1', $payment['reference']);
        self::assertSame($refundedAt, (int) $payment['lockedAt']);

        // Die Rechnungskorrektur (F2, intern CreditNote): eigener Nummernkreis,
        // bezogen auf die Rechnung, mit der widerrufenen Ware negativ (F3).
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

        // Kein Storno (nicht alles erstattet, F3), keine zweite Rechnung.
        self::assertSame([], $this->documentIds(DocumentType::Cancellation));
        self::assertSame([$invoice], $this->documentIds(DocumentType::Invoice));

        // Der Widerruf trägt, was gebucht wurde.
        $booked = $this->withdrawalRow($withdrawal);
        self::assertTrue(BigDecimal::of('30')->isEqualTo((string) $booked['refundAmount']));
        self::assertSame($refundedAt, (int) $booked['refundedAt']);
        self::assertSame((int) $payment['id'], (int) $booked['refundPayment']);
        self::assertSame($corrections[0], (int) $booked['refundDocument']);

        // Danach ist alles erledigt: Forderung 70, bezahlt 70, nichts mehr zu liefern.
        $balance = $this->balance();
        self::assertTrue(BigDecimal::of('70')->isEqualTo($balance->claim));
        self::assertTrue(BigDecimal::of('70')->isEqualTo($balance->paid));
        self::assertSame(PaymentState::Paid, $balance->paymentState);
        self::assertTrue($balance->settled());

        // … und es greift „Status, wenn alles erledigt ist" (F10).
        self::assertSame(self::DONE, (int) $this->orderRow()['orderStatus']);

        // Die Erstattungsmail, mit der Korrektur im Anhang.
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

        // Noch einmal buchen geht nicht.
        self::assertSame('alreadyRefunded', $this->processing->refund($withdrawal, '0', $refundedAt + 60)->key);
        self::assertCount(1, $this->documentIds(DocumentType::CreditNote));
        self::assertSame([], $this->logger->errors);
    }

    // ── 5. Die Frist an ihren Grenzen ─────────────────────────────────────

    /**
     * Frist 14 Tage, Puffer 7 Tage (die Vorgaben, W4 / Spec D 6.2), versandt
     * am 1. Oktober 2026 um 10:00 in Wien.
     *
     * Die Erwartungen stehen als Kalenderdaten da, nicht als Formel: Der
     * letzte Tag der Frist ist der 15. Oktober, die Funktion schließt
     * „frühestens nach letztem Versand + Frist + Puffer" (6.2) — am Ende des
     * 22. Oktober, 23:59:59 Wiener Zeit (noch Sommerzeit). Rechnete die
     * Produktion in UTC, stünde das Ende zwei Stunden später, und die
     * Erklärung um Mitternacht ginge durch.
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

        // Der letzte Tag der Frist: offen.
        $lastDay = $this->recorder->record($this->wholeOrder(), self::at('2026-10-15 23:59:59'));
        self::assertSame(WithdrawalOutcome::Recorded, $lastDay->outcome);

        // Die letzte Sekunde des Puffers: offen — ein Tag zu spät kostet den
        // Betreiber nichts, ein Tag zu früh ist ein Verstoß (WithdrawalWindow).
        $lastSecond = $this->recorder->record($this->wholeOrder(), self::at('2026-10-22 23:59:59'));
        self::assertSame(WithdrawalOutcome::Recorded, $lastSecond->outcome);

        // Die erste Sekunde danach: seit WA (B1, 2026-10-22) nicht mehr zu,
        // sondern gespeichert und markiert — das Fenster rechnet ab Versand,
        // die Frist ab Erhalt; ob es rechtzeitig war, entscheidet der Betreiber.
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
            // Das Dateisystem fragt nur nach einem Logo; der Shop hat keins.
            new DbalDocumentSource($this->connection, new TranslationStorage($this->connection), new ShopLanguages($this->connection), $this->createStub(VirtualFilesystemInterface::class), $this->balances),
        );

        // CONSHO_TEST_PAYMENT=1: ohne den Schalter kennt PaymentHandlers den Test-Anbieter nicht.
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

        // Wie DocumentDelivery: der Dateiname ist die Belegnummer. Gelesen,
        // nicht erfunden — ein falscher Beleg im Anhang fiele so auf.
        $pdfs = new class($this->connection) implements DocumentPdfInterface {
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
        // pid/type, document number per shop/type) — Fall 31/57/62.
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
     * Die Rechnung beim Kauf — wie OrderPlacer sie ausstellt, wenn der
     * Handler der Zahlungsart OnOrder sagt. Ob er das sagt, fragt der Test
     * das Original, statt es anzunehmen.
     */
    private function purchaseInvoice(): int
    {
        self::assertSame(DocumentTrigger::OnOrder, (new PrepaymentHandler())->documentTrigger([]));

        return $this->issuer->issueOnce(self::ORDER, self::SHOP, DocumentType::Invoice);
    }

    /**
     * Eine Lieferung, wie das Backend sie anlegt — gemeldet ist sie erst
     * über DeliveryReported.
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
     * Kein unübersetzter Schlüssel, kein roher decimal-Wert.
     */
    private function assertCleanMail(Email $mail): void
    {
        $html = (string) $mail->getHtmlBody();

        self::assertStringNotContainsString('CONSHO.', $html.$mail->getSubject());
        // „2.0000" ja, das Datum „29.09.2026" nicht.
        self::assertDoesNotMatchRegularExpression('/(?<![\d.])\d+\.\d{4}\b/', $html, 'ein decimal(…,4) roh in der Mail (Prüfungsnotiz, Fall 30)');
    }
}

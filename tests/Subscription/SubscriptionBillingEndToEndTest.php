<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\CoreBundle\Intl\Countries;
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
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationDeclaration;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationKind;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationOutcome;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationRecorder;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationSource;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationTerm;
use Webwerkwien\ContaoConshoBundle\Cancellation\SubscriptionContact;
use Webwerkwien\ContaoConshoBundle\Digital\EntitlementQuery;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentRepository;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentSource;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuer;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderStatusRepository;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderWriter;
use Webwerkwien\ContaoConshoBundle\Order\DbalPaymentChangeRepository;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeAllocator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OrderFactory;
use Webwerkwien\ContaoConshoBundle\Order\OrderProductRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusWriterInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChange;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeBlock;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Order\WithdrawalCredit;
use Webwerkwien\ContaoConshoBundle\Checkout\ShippingTaxSplitter;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\StatusFlags;
use Webwerkwien\ContaoConshoBundle\Payment\DbalPaymentMethodRepository;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Subscription\DbalSubscriptionLock;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBiller;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingMethod;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\LifecycleChange;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquency;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLifecycle;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquencyRun;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WindowState;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalWindowReader;

/**
 * Eine Abo-Periode von Anfang bis Ende (Review Abo-4, S3): echte
 * Bestellfabrik, echte Lieferungsmeldung, echte Rechnung, echter Zeilen-Lock —
 * die Nähte, die SubscriptionBillerTest mit Doppeln überbrückt.
 *
 * Gedoppelt ist nur, was nicht zur Frage gehört: Katalog und Produktdaten
 * (die eingefrorene Zeile darf sie nie fragen — beide werfen, wenn doch),
 * Steuersätze (20 % AT, 19 % DE), Mail und Bestellseite, die Archiv-Datei.
 *
 * FOR UPDATE wie in AfterSaleAgreesWithOrderTest: eine DBAL-Middleware
 * streicht die zwei Wörter und merkt sich die Anweisung. Alle Tabellen aus
 * den DCAs (DcaSchema).
 */
final class SubscriptionBillingEndToEndTest extends TestCase
{
    use TaxFixture;

    private const ZONE = 'Europe/Vienna';

    /** @var list<string> */
    private static array $rowLocks = [];

    private Connection $connection;

    private string $zone;

    /** @var \ArrayObject<int, array{int, int}> invoice id, transaction level */
    private \ArrayObject $archived;

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
        $this->zone = date_default_timezone_get();
        date_default_timezone_set(self::ZONE);
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
                                return parent::prepare(SubscriptionBillingEndToEndTest::withoutRowLock($sql));
                            }

                            public function query(string $sql): DriverResult
                            {
                                return parent::query(SubscriptionBillingEndToEndTest::withoutRowLock($sql));
                            }

                            public function exec(string $sql): int|string
                            {
                                return parent::exec(SubscriptionBillingEndToEndTest::withoutRowLock($sql));
                            }
                        };
                    }
                };
            }
        }]);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);

        foreach ([
            'tl_consho_shop', 'tl_consho_number_range', 'tl_consho_order_status', 'tl_consho_payment_method', 'tl_consho_translation',
            'tl_consho_order', 'tl_consho_order_item', 'tl_consho_payment', 'tl_consho_delivery', 'tl_consho_delivery_item',
            'tl_consho_document', 'tl_consho_subscription', 'tl_consho_entitlement', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item',
            'tl_consho_payment_attempt', 'tl_consho_cancellation',
        ] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        // Core tables the document source asks for the shop's languages.
        $this->connection->executeStatement("CREATE TABLE tl_page (id INTEGER PRIMARY KEY, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, language TEXT NOT NULL DEFAULT '', consho_currency TEXT NOT NULL DEFAULT '')");
        $this->connection->executeStatement("CREATE TABLE tl_files (id INTEGER PRIMARY KEY, uuid BLOB NULL, type TEXT NOT NULL DEFAULT '', hash TEXT NOT NULL DEFAULT '')");
        // The member's groups, which the payment method change asks.
        $this->connection->executeStatement('CREATE TABLE tl_member (id INTEGER PRIMARY KEY, "groups" BLOB NULL)');

        $this->seed();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    public function testEinePeriodeVonAnfangBisEnde(): void
    {
        $now = $this->at('2026-02-28 11:00');

        $billed = $this->biller()->billDue(1, $now);

        self::assertNotNull($billed);

        // Die Folgebestellung: Nummernkreis, Rechnungsart, eingefrorener Zeitraum.
        $order = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$billed->orderId]);
        self::assertIsArray($order);
        self::assertSame(['WEB-00091', 1, 6, 'Auf Rechnung'], [$order['orderNumber'], (int) $order['subscription'], (int) $order['paymentMethod'], $order['paymentMethodTitle']]);
        self::assertSame(['2026-02-28 10:15', '2026-03-31 10:15'], [$this->local($order['servicePeriodFrom']), $this->local($order['servicePeriodUntil'])]);
        self::assertNull($order['digitalConsentAt']);

        // Reverse Charge eingefroren: 2 × 4,1667 netto, 0 %, brutto = netto.
        self::assertEqualsWithDelta(8.33, (float) $order['grandTotal'], 0.001);
        $item = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order_item WHERE pid = ?', [$billed->orderId]);
        self::assertIsArray($item);
        self::assertSame(['E-Book Kräuterkunde', 'EB-001', 'reverseCharge', 1, 'Monatsabo, 1 Jahr'], [$item['productName'], $item['sku'], $item['priceSource'], (int) $item['subscriptionRule'], $item['subscriptionTerms']]);
        self::assertEqualsWithDelta(0.0, (float) $item['taxRate'], 0.0001);

        // Geliefert gemeldet → Rechnung „bei Versand", mit Zahlungsziel.
        self::assertNotNull($this->connection->fetchOne('SELECT reportedAt FROM tl_consho_delivery WHERE pid = ?', [$billed->orderId]));
        $invoice = $this->connection->fetchAssociative("SELECT * FROM tl_consho_document WHERE pid = ? AND type = 'invoice'", [$billed->orderId]);
        self::assertIsArray($invoice, 'die Folgebestellung hat eine Rechnung');
        self::assertSame('RE-00001', $invoice['documentNumber']);
        // Zahlungsziel aus der Rechnungsart (14 Tage ab Ausstellung, E-2).
        self::assertGreaterThan($now + 13 * 86_400, (int) $order['paymentDueAt']);

        $payload = json_decode((string) $invoice['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(10, $payload['formatVersion']);
        // Block R: der Testshop stellt keine E-Rechnungen aus.
        self::assertFalse($payload['eInvoice']);
        // Nichts vorher bezahlt: weder Zahlungen noch offener Betrag (WG12).
        self::assertArrayNotHasKey('outstanding', $payload['payment']);
        self::assertSame(['number' => 'WEB-00090-1', 'from' => '2026-02-28', 'to' => '2026-03-31'], $payload['subscription']);

        // PDF erst nach dem Commit (S2).
        self::assertSame([[(int) $invoice['id'], 0]], $this->archived->getArrayCopy());

        // Die Berechtigung reicht weiter, keine neue (P27).
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_entitlement'));
        self::assertSame('2026-03-31 10:15', $this->local($this->connection->fetchOne('SELECT validUntil FROM tl_consho_entitlement WHERE id = 30')));

        // Das Abo wurde gesperrt, bevor es gelesen wurde (FOR UPDATE).
        self::assertContains('SELECT * FROM tl_consho_subscription WHERE id = ?', self::$rowLocks);

        // A12: kein Widerrufsfenster für die Folgebestellung.
        $window = (new WithdrawalWindowReader($this->connection, $this->balances()))->of($billed->orderId);
        self::assertNotNull($window);
        self::assertSame(WindowState::Off, $window->state);

        // Derselbe Zeitpunkt noch einmal: nichts mehr fällig.
        self::assertNull($this->biller()->billDue(1, $now));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_order WHERE subscription = 1'));
    }

    /**
     * Abo-5 (Lehre aus Fall 44): Verzug mit echten Bausteinen — echte
     * Folgebestellung und Rechnung, echtes Zahlungsziel, echter Saldo, echter
     * Lock, echte Zahlungsmeldung. Gedoppelt sind nur Mail und Bestellseite.
     */
    public function testVerzugRuhtUndDieZahlungWecktEsWieder(): void
    {
        $this->connection->update('tl_consho_subscription', ['graceDays' => 14], ['id' => 1]);
        $billed = $this->biller()->billDue(1, $this->at('2026-02-28 11:00'));
        self::assertNotNull($billed);

        // Das Zahlungsziel kommt aus der echten Rechnung. DocumentIssuer stellt
        // mit der Uhr des Servers aus, nicht mit dem $now des Billers — im
        // Betrieb dasselbe, hier nicht; also ab dem Ziel gerechnet, das dasteht.
        $dueAt = (int) $this->connection->fetchOne('SELECT paymentDueAt FROM tl_consho_order WHERE id = ?', [$billed->orderId]);
        self::assertSame('23:59:59', (new \DateTimeImmutable('@'.$dueAt))->setTimezone(new \DateTimeZone(self::ZONE))->format('H:i:s'), 'Tagesende');

        /** @var \ArrayObject<int, array{string, int, string}> $mails */
        $mails = new \ArrayObject();
        $run = $this->delinquencyRun($mails);
        $query = new EntitlementQuery($this->connection);
        $graceEnds = (new SubscriptionCalendar(self::ZONE))->addDays($dueAt, 14);

        // Aus demselben Grund läge das Ziel nach dem Periodenende; hier zählt
        // nur das Ruhen, also dauerhaft.
        $this->connection->update('tl_consho_entitlement', ['validUntil' => null], ['id' => 30]);

        // In der Nachfrist: nichts.
        self::assertSame([], $run->run($graceEnds - 3_600));
        self::assertTrue($query->isActive(30, $graceEnds - 3_600));

        // Danach ruht es — mit Lock, Mail und offenem Betrag aus dem echten Saldo.
        $restingAt = $graceEnds + 3_600;
        self::assertCount(1, $run->run($restingAt));
        self::assertSame('suspended', $this->connection->fetchOne('SELECT state FROM tl_consho_subscription WHERE id = 1'));
        self::assertSame($restingAt, (int) $this->connection->fetchOne('SELECT suspendedAt FROM tl_consho_entitlement WHERE id = 30'));
        self::assertFalse($query->isActive(30, $restingAt));
        self::assertContains('SELECT * FROM tl_consho_subscription WHERE id = ?', self::$rowLocks);
        self::assertCount(1, $mails);
        self::assertSame('suspended', $mails[0][0]);
        self::assertSame($billed->orderId, $mails[0][1]);
        self::assertEqualsWithDelta(8.33, (float) $mails[0][2], 0.001);

        // Der Kauf selbst (Vorkasse, ohne Zahlungsziel) zählt nicht mit.
        self::assertSame([$billed->orderId], $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_order WHERE paymentDueAt > 0'));

        // Ein zweiter Lauf schickt nichts mehr.
        self::assertSame([], $run->run($restingAt + 3_600));
        self::assertCount(1, $mails);

        // Die Zahlung kommt: begleicht die Bestellung und weckt das Abo sofort.
        $paidAt = $restingAt + 4 * 86_400;
        $this->connection->insert('tl_consho_payment', ['pid' => $billed->orderId, 'amount' => (string) $this->connection->fetchOne('SELECT grandTotal FROM tl_consho_order WHERE id = ?', [$billed->orderId]), 'tstamp' => $paidAt]);
        $this->paymentRecorded($run)->handle($billed->orderId, false, $paidAt);

        self::assertSame('active', $this->connection->fetchOne('SELECT state FROM tl_consho_subscription WHERE id = 1'));
        self::assertNull($this->connection->fetchOne('SELECT suspendedAt FROM tl_consho_entitlement WHERE id = 30'));
        self::assertTrue($query->isActive(30, $paidAt));
        self::assertSame(['resumed', $billed->orderId], [$mails[1][0], $mails[1][1]]);
    }

    /**
     * Zweites Review Abo-5, S2 — mit dem ECHTEN DocumentIssuer, weil das Loch
     * genau an seinem Doppel vorbeiging: Die Vorkasse-Rechnung schreibt selbst
     * `paymentDueAt = 0`. Vor dem Ziel darf gewechselt werden, das Ziel bleibt;
     * danach nicht mehr.
     */
    public function testZahlungsartwechselHebtDenVerzugNichtAuf(): void
    {
        $billed = $this->biller()->billDue(1, $this->at('2026-02-28 11:00'));
        self::assertNotNull($billed);
        $dueAt = (int) $this->connection->fetchOne('SELECT paymentDueAt FROM tl_consho_order WHERE id = ?', [$billed->orderId]);

        $changed = $this->paymentChange()->change($billed->orderId, 2, 'de', $dueAt - 86_400);

        self::assertNotNull($changed->cancellationId, 'die Rechnung „Auf Rechnung" ist storniert');
        self::assertNotNull($changed->invoiceId, 'Vorkasse stellt neu aus');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT paymentMethod FROM tl_consho_order WHERE id = ?', [$billed->orderId]));
        self::assertSame($dueAt, (int) $this->connection->fetchOne('SELECT paymentDueAt FROM tl_consho_order WHERE id = ?', [$billed->orderId]), 'das alte Ziel steht noch');

        // Und nach dem Ziel: kein Wechsel mehr (die Sperre „einmal" käme
        // zuerst — also an einer frischen Periode geprüft).
        $this->connection->update('tl_consho_order', ['paymentChangedAt' => null], ['id' => $billed->orderId]);
        self::assertSame(PaymentChangeBlock::Overdue, $this->paymentChange()->options($billed->orderId, 'de', $dueAt + 1)->block);
    }

    /**
     * Abo-6 (P46): Am Ende der Mindestlaufzeit geht das Monatsabo in
     * Unbefristet über — echter Lebenszyklus, danach echter Biller mit echter
     * Fabrik, Lieferung und Rechnung über die alte Grenze hinweg.
     */
    public function testNachDerMindestlaufzeitWeiterMonatlich(): void
    {
        $this->connection->update('tl_consho_subscription', [
            'periodFrom' => $this->at('2026-12-31 10:15'), 'periodUntil' => $this->at('2027-01-31 10:15'), 'nextBillingAt' => $this->at('2027-01-31 10:15'),
        ], ['id' => 1]);

        $now = $this->at('2027-01-31 11:00');

        // Innerhalb der Mindestlaufzeit ist nichts mehr fällig.
        self::assertNull($this->biller()->billDue(1, $now));

        self::assertSame(LifecycleChange::OpenEnded, $this->lifecycle()->transition(1, $now));

        $billed = $this->biller()->billDue(1, $now);
        self::assertNotNull($billed);
        self::assertSame(['2027-01-31 10:15', '2027-02-28 10:15'], [$this->local($billed->periodFrom), $this->local($billed->periodUntil)]);
        self::assertEqualsWithDelta(8.33, (float) $this->connection->fetchOne('SELECT grandTotal FROM tl_consho_order WHERE id = ?', [$billed->orderId]), 0.001, 'Preis unverändert');
        self::assertNotFalse($this->connection->fetchOne("SELECT id FROM tl_consho_document WHERE pid = ? AND type = 'invoice'", [$billed->orderId]));
        self::assertSame('2027-02-28 10:15', $this->local($this->connection->fetchOne('SELECT validUntil FROM tl_consho_entitlement WHERE id = 30')));
    }

    /**
     * Variante A (2026-10-08) mit echten Bausteinen: Eine Altregel
     * „jederzeit kündbar" mit einer Zahlung je Laufzeit wird nicht mehr in
     * den Monatspreis umgewandelt — sie endet, und der echte Biller rechnet
     * danach nichts mehr ab.
     */
    public function testEineAltregelOhneMonatlicheZahlungEndetUndWirdNichtWeiterAbgerechnet(): void
    {
        $this->connection->update('tl_consho_subscription', [
            'rule' => 2, 'ruleTitle' => 'Jahresabo', 'billingInterval' => 0, 'unitNet' => '10.0000',
            'periodFrom' => $this->at('2026-01-31 10:15'), 'periodUntil' => $this->at('2027-01-31 10:15'), 'nextBillingAt' => $this->at('2027-01-31 10:15'),
        ], ['id' => 1]);

        $now = $this->at('2027-01-31 11:00');

        self::assertSame(LifecycleChange::Ended, $this->lifecycle()->transition(1, $now));
        self::assertSame('ended', $this->connection->fetchOne('SELECT state FROM tl_consho_subscription WHERE id = 1'));

        $orders = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_order');
        self::assertNull($this->biller()->billDue(1, $now));
        self::assertSame($orders, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_order'), 'keine Folgebestellung');
    }

    /**
     * P45: Eine Zahlung je Laufzeit, gleiche Laufzeit — nach der Verlängerung
     * rechnet der echte Biller die ganze neue Laufzeit ab.
     */
    public function testGleicheLaufzeitMitEinerZahlungJeLaufzeit(): void
    {
        $this->connection->update('tl_consho_subscription', [
            'billingInterval' => 0, 'renewal' => 'sameTerm',
            'periodFrom' => $this->at('2026-01-31 10:15'), 'periodUntil' => $this->at('2027-01-31 10:15'), 'nextBillingAt' => $this->at('2027-01-31 10:15'),
        ], ['id' => 1]);

        $now = $this->at('2027-01-31 11:00');

        self::assertSame(LifecycleChange::Renewed, $this->lifecycle()->transition(1, $now));

        $billed = $this->biller()->billDue(1, $now);
        self::assertNotNull($billed);
        self::assertSame(['2027-01-31 10:15', '2028-01-31 10:15'], [$this->local($billed->periodFrom), $this->local($billed->periodUntil)]);
        self::assertNull($this->biller()->billDue(1, $now), 'einmal je Laufzeit');
    }

    /**
     * Abo-7 (P54–P56): rechtzeitig ordentlich gekündigt — echter Recorder mit
     * echtem Lock, dann echter Biller und Lebenszyklus: Das Abo endet am Ende
     * der Mindestlaufzeit, statt unbefristet weiterzulaufen, und nichts wird
     * mehr abgerechnet. Die Berechtigung reicht bis zum Ende der bezahlten
     * Periode (P55).
     */
    public function testRechtzeitigGekuendigtEndetMitDerMindestlaufzeit(): void
    {
        $this->lastMonthOfTheTerm();

        $result = $this->cancellationRecorder()->record(new CancellationDeclaration(1, CancellationSource::Form, CancellationKind::Ordinary, 'John Muster'), $this->at('2026-12-01 09:00'));

        self::assertSame(CancellationOutcome::Recorded, $result->outcome);
        self::assertSame('2027-01-31 10:15', $this->local($result->effectiveAt));
        self::assertContains('SELECT * FROM tl_consho_subscription WHERE id = ?', self::$rowLocks);

        $now = $this->at('2027-01-31 11:00');

        self::assertNull($this->biller()->billDue(1, $now));
        self::assertSame(LifecycleChange::Ended, $this->lifecycle()->transition(1, $now));
        self::assertSame('ended', $this->connection->fetchOne('SELECT state FROM tl_consho_subscription WHERE id = 1'));
        self::assertNull($this->biller()->billDue(1, $this->at('2027-03-01 11:00')));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_order WHERE subscription = 1'));
        self::assertSame('2027-01-31 10:15', $this->local($this->connection->fetchOne('SELECT validUntil FROM tl_consho_entitlement WHERE id = 30')));
    }

    /**
     * Abo-7 (P54, P55): nach dem Stichtag gekündigt — unbefristet heißt dann
     * Eingang + Frist. Die Periode, die davor beginnt, wird noch ganz
     * abgerechnet (keine Anteilsrechnung), danach nichts mehr; am Ende `ended`.
     */
    public function testZuSpaetGekuendigtLaeuftBisEingangPlusFrist(): void
    {
        $this->lastMonthOfTheTerm();

        // Stichtag 31.01. − 14 Tage = 17.01. 10:15 — vorbei.
        $result = $this->cancellationRecorder()->record(new CancellationDeclaration(1, CancellationSource::Account, CancellationKind::Ordinary, 'John Muster'), $this->at('2027-01-25 12:00'));

        self::assertSame('2027-02-08 12:00', $this->local($result->effectiveAt));

        $now = $this->at('2027-01-31 11:00');
        self::assertSame(LifecycleChange::OpenEnded, $this->lifecycle()->transition(1, $now));

        $billed = $this->biller()->billDue(1, $now);
        self::assertNotNull($billed, 'die Periode beginnt vor dem Ende');
        self::assertSame(['2027-01-31 10:15', '2027-02-28 10:15'], [$this->local($billed->periodFrom), $this->local($billed->periodUntil)]);

        self::assertNull($this->lifecycle()->transition(1, $this->at('2027-02-08 11:59')), 'eine Minute vor dem Ende');
        self::assertSame(LifecycleChange::Ended, $this->lifecycle()->transition(1, $this->at('2027-02-08 12:00')));
        self::assertNull($this->biller()->billDue(1, $this->at('2027-02-28 11:00')));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_order WHERE subscription = 1'));
    }

    /**
     * Die letzte Periode der Mindestlaufzeit, 14 Tage Frist.
     */
    private function lastMonthOfTheTerm(): void
    {
        $this->connection->update('tl_consho_subscription', [
            'noticeDays' => 14,
            'periodFrom' => $this->at('2026-12-31 10:15'), 'periodUntil' => $this->at('2027-01-31 10:15'), 'nextBillingAt' => $this->at('2027-01-31 10:15'),
        ], ['id' => 1]);
        $this->connection->update('tl_consho_entitlement', ['validUntil' => $this->at('2027-01-31 10:15')], ['id' => 30]);
    }

    private function cancellationRecorder(): CancellationRecorder
    {
        $mail = $this->createStub(OrderEventMail::class);
        $mail->method('cancellationReceived')->willReturn(true);

        return new CancellationRecorder(
            $this->connection,
            new DbalSubscriptionLock($this->connection),
            new CancellationTerm(new SubscriptionCalendar(self::ZONE)),
            $mail,
            $this->createStub(OrderPageLink::class),
            new SubscriptionContact($this->connection),
        );
    }

    private function lifecycle(): SubscriptionLifecycle
    {
        return new SubscriptionLifecycle($this->connection, new DbalSubscriptionLock($this->connection), new SubscriptionCalendar(self::ZONE));
    }

    private function paymentChange(): PaymentChange
    {
        return new PaymentChange(
            $this->connection,
            new DbalPaymentChangeRepository($this->connection),
            new DbalPaymentMethodRepository($this->connection),
            $this->handlers(),
            new ShippingTaxSplitter(),
            new PaymentStateCalculator(),
            new DbalDocumentRepository($this->connection),
            $this->issuer(),
            $this->createStub(DocumentArchiverInterface::class),
            new WithdrawalCredit($this->connection),
        );
    }

    /**
     * @param \ArrayObject<int, array{string, int, string}> $mails
     */
    private function delinquencyRun(\ArrayObject $mails): SubscriptionDelinquencyRun
    {
        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/bestellung');

        $mail = $this->createStub(OrderEventMail::class);
        $mail->method('subscriptionSuspended')->willReturnCallback(static function (int $orderId, string $number, string $outstanding) use ($mails): bool {
            $mails->append(['suspended', $orderId, $outstanding]);

            return true;
        });
        $mail->method('subscriptionResumed')->willReturnCallback(static function (int $orderId) use ($mails): bool {
            $mails->append(['resumed', $orderId, '']);

            return true;
        });

        return new SubscriptionDelinquencyRun(
            $this->connection,
            new SubscriptionDelinquency($this->connection, new DbalSubscriptionLock($this->connection), $this->balances(), new SubscriptionCalendar(self::ZONE)),
            $this->balances(),
            $links,
            $mail,
        );
    }

    private function paymentRecorded(SubscriptionDelinquencyRun $run): PaymentRecorded
    {
        $methods = new DbalPaymentMethodRepository($this->connection);
        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/bestellung');

        return new PaymentRecorded(
            $this->connection,
            new DbalPaymentChangeRepository($this->connection),
            $this->balances(),
            $methods,
            $this->handlers(),
            $this->issuer(),
            $this->createStub(DocumentArchiverInterface::class),
            $this->statuses(),
            $links,
            $this->createStub(OrderEventMail::class),
            new NullLogger(),
            null,
            $run,
        );
    }

    private function handlers(): PaymentHandlers
    {
        return new PaymentHandlers(new ServiceLocator([
            PrepaymentHandler::getType() => static fn (): PaymentHandlerInterface => new PrepaymentHandler(),
            InvoicePaymentHandler::getType() => static fn (): PaymentHandlerInterface => new InvoicePaymentHandler(),
        ]));
    }

    private function issuer(): DocumentIssuer
    {
        return new DocumentIssuer(
            new DbalDocumentRepository($this->connection),
            new NumberRangeAllocator($this->connection),
            new DocumentPayloadBuilder(),
            new DbalDocumentSource($this->connection, new TranslationStorage($this->connection), new ShopLanguages($this->connection), $this->createStub(VirtualFilesystemInterface::class), $this->balances()),
        );
    }

    private function statuses(): OrderStatusAdvancer
    {
        $writer = new class($this->connection) implements OrderStatusWriterInterface {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function write(int $orderId, StatusFlags $status, int $now): void
            {
                $this->connection->update('tl_consho_order', ['orderStatus' => $status->id, 'tstamp' => $now], ['id' => $orderId]);
            }
        };

        return new OrderStatusAdvancer($this->connection, new DbalOrderStatusRepository($this->connection), $this->balances(), $writer);
    }

    private function biller(): SubscriptionBiller
    {
        $balances = $this->balances();
        $methods = new DbalPaymentMethodRepository($this->connection);
        $handlers = $this->handlers();

        $rates = $this->createStub(TaxRateRepositoryInterface::class);
        $rates->method('findRate')->willReturnCallback(static fn (int $class, string $country): string|null => match ($country) {
            'AT' => '20.0000',
            'DE' => '19.0000',
            default => null,
        });
        $countries = $this->createStub(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE']);

        // Der Katalog darf nie gefragt werden (P25).
        $prices = $this->createMock(CartLinePriceSource::class);
        $prices->expects(self::never())->method(self::anything());
        $products = $this->createMock(OrderProductRepositoryInterface::class);
        $products->expects(self::never())->method(self::anything());

        $factory = new OrderFactory(new NumberRangeAllocator($this->connection), $prices, new TaxResolver($rates, new CountryProvider($countries)), $products, new DbalOrderWriter($this->connection));

        $issuer = $this->issuer();

        $archiver = new class($this->archived, $this->connection) implements DocumentArchiverInterface {
            /**
             * @param \ArrayObject<int, array{int, int}> $archived
             */
            public function __construct(private readonly \ArrayObject $archived, private readonly Connection $connection)
            {
            }

            public function archive(int $documentId): void
            {
                $this->archived->append([$documentId, $this->connection->getTransactionNestingLevel()]);
            }
        };

        $mail = $this->createMock(OrderEventMail::class);
        $mail->expects(self::never())->method('shipped');

        $deliveries = new DeliveryReported(
            $this->connection,
            new DbalPaymentChangeRepository($this->connection),
            $balances,
            $methods,
            $handlers,
            $issuer,
            $archiver,
            $this->statuses(),
            $this->createStub(OrderPageLink::class),
            $mail,
            new MoneyFormatter(),
        );

        return new SubscriptionBiller(
            $this->connection,
            new DbalSubscriptionLock($this->connection),
            $factory,
            new SubscriptionBillingMethod($methods),
            $deliveries,
            new SubscriptionCalendar(self::ZONE),
            $archiver,
        );
    }

    private function balances(): OrderBalanceReader
    {
        return new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator());
    }

    private function seed(): void
    {
        $c = $this->connection;

        $c->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop', 'country' => 'AT', 'vatId' => 'ATU12345678', 'fallbackLanguage' => 'de', 'paymentMethods' => serialize(['2', '6']), 'invoiceCompany' => 'Muster', 'invoiceCountry' => 'AT']);
        $c->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'order', 'prefix' => 'WEB-', 'padding' => 5, 'current' => 90]);
        $c->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'invoice', 'prefix' => 'RE-', 'padding' => 5, 'current' => 0]);
        $c->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'cancellation', 'prefix' => 'ST-', 'padding' => 5, 'current' => 0]);
        $c->insert('tl_consho_payment_method', ['id' => 2, 'type' => 'prepayment', 'title' => 'Vorkasse', 'published' => 1]);
        $c->insert('tl_consho_payment_method', ['id' => 6, 'type' => 'invoice', 'title' => 'Auf Rechnung', 'published' => 1, 'jsonData' => json_encode(['dueDays' => 14], JSON_THROW_ON_ERROR)]);

        $start = $this->at('2026-01-31 10:15');

        $c->insert('tl_consho_order', [
            'id' => 90, 'orderNumber' => 'WEB-00090', 'shop' => 1, 'member' => 7, 'language' => 'de', 'currency' => 'EUR', 'createdAt' => $start,
            'firstname' => 'John', 'lastname' => 'Muster', 'email' => 'kunde@example.org', 'company' => 'Muster GmbH',
            'billingStreet' => 'Hauptstraße 1', 'billingPostal' => '1100', 'billingCity' => 'Wien', 'billingCountry' => 'AT',
            'shippingCountry' => 'DE', 'vatId' => 'DE123456789', 'businessCustomer' => 1, 'paymentMethod' => 2, 'paymentMethodTitle' => 'Vorkasse',
        ]);
        $c->insert('tl_consho_order_item', ['id' => 2, 'pid' => 90, 'lineType' => 'product', 'product' => 4, 'productName' => 'E-Book Kräuterkunde', 'sku' => 'EB-001', 'kind' => 'digital', 'quantity' => '2.0000']);

        $c->insert('tl_consho_subscription', [
            'id' => 1, 'subscriptionNumber' => 'WEB-00090-1', 'shop' => 1, 'member' => 7, 'product' => 4, 'firstOrder' => 90, 'orderItem' => 2,
            'rule' => 1, 'ruleTitle' => 'Monatsabo', 'termLength' => 1, 'termUnit' => 'year', 'billingInterval' => 1, 'renewal' => 'indefinite',
            'quantity' => '2.0000', 'unitNet' => '4.1667', 'taxClass' => 3, 'currency' => 'EUR', 'reverseCharge' => 1, 'businessCustomer' => 1,
            'kind' => 'digital', 'terms' => 'Monatsabo, 1 Jahr',
            'startedAt' => $start, 'minimumEndsAt' => $this->at('2027-01-31 10:15'),
            'periodFrom' => $start, 'periodUntil' => $this->at('2026-02-28 10:15'), 'nextBillingAt' => $this->at('2026-02-28 10:15'),
        ]);
        $c->insert('tl_consho_entitlement', ['id' => 30, 'pid' => 90, 'orderItem' => 2, 'subscription' => 1, 'validUntil' => $this->at('2026-02-28 10:15')]);
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }

    private function local(mixed $timestamp): string
    {
        return (new \DateTimeImmutable('@'.(int) $timestamp))->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d H:i');
    }
}

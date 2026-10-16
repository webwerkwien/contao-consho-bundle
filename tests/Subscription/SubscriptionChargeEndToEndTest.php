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
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\StatusFlags;
use Webwerkwien\ContaoConshoBundle\Payment\ChargeResult;
use Webwerkwien\ContaoConshoBundle\Payment\DbalPaymentAttemptRepository;
use Webwerkwien\ContaoConshoBundle\Payment\DbalPaymentMethodRepository;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationProcessor;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationResult;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Subscription\DbalSubscriptionLock;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBiller;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingMethod;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionChargeFallback;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCharger;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionMandates;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Payment\FakeRecurringHandler;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;

/**
 * Abo-4b (P94–P97, P99) mit echten Bausteinen: echter Biller, echte
 * Folgebestellung, echter Zahlungsversuch, echte Zahlungszeile, echte
 * Rechnung, echter Rückfall „Auf Rechnung". Gedoppelt sind nur das
 * Zahlungsmodul (FakeRecurringHandler), Mail, Bestellseite und Archiv.
 *
 * FOR UPDATE wie in SubscriptionBillingEndToEndTest: eine DBAL-Middleware
 * streicht die zwei Wörter. Alle Tabellen aus den DCAs.
 */
final class SubscriptionChargeEndToEndTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    private Connection $connection;

    private string $zone;

    private FakeRecurringHandler $card;

    /** @var \ArrayObject<int, list<mixed>> */
    private \ArrayObject $mails;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $log;

    public static function withoutRowLock(string $sql): string
    {
        return (string) preg_replace('/\s+FOR\s+UPDATE\s*$/i', '', $sql, 1);
    }

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set(self::ZONE);
        $this->card = new FakeRecurringHandler();
        $this->mails = new \ArrayObject();
        $this->log = new \ArrayObject();

        $configuration = (new Configuration())->setMiddlewares([new class implements Middleware {
            public function wrap(Driver $driver): Driver
            {
                return new class($driver) extends AbstractDriverMiddleware {
                    public function connect(array $params): DriverConnection
                    {
                        return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                            public function prepare(string $sql): DriverStatement
                            {
                                return parent::prepare(SubscriptionChargeEndToEndTest::withoutRowLock($sql));
                            }

                            public function query(string $sql): DriverResult
                            {
                                return parent::query(SubscriptionChargeEndToEndTest::withoutRowLock($sql));
                            }

                            public function exec(string $sql): int|string
                            {
                                return parent::exec(SubscriptionChargeEndToEndTest::withoutRowLock($sql));
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

        $this->connection->executeStatement("CREATE TABLE tl_page (id INTEGER PRIMARY KEY, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, language TEXT NOT NULL DEFAULT '', consho_currency TEXT NOT NULL DEFAULT '')");
        $this->connection->executeStatement("CREATE TABLE tl_files (id INTEGER PRIMARY KEY, uuid BLOB NULL, type TEXT NOT NULL DEFAULT '', hash TEXT NOT NULL DEFAULT '')");
        $this->connection->executeStatement('CREATE TABLE tl_member (id INTEGER PRIMARY KEY, "groups" BLOB NULL)');

        $this->seed();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    /**
     * P94, P97: bezahlt → Zahlungszeile, Rechnung nach dem Auslöser des
     * Moduls (bei Zahlung), „Rechnung zum Abo" mit Abbuchungstag statt
     * „Zahlung erhalten".
     */
    public function testBezahltBuchtDieZahlungUndSchicktDieAbgebuchteFassung(): void
    {
        $this->card->charge = ChargeResult::paid('8.33', 'ch_1');
        $now = $this->at('2026-02-28 11:00');

        $billed = $this->billingRun()->run($now);

        self::assertCount(1, $billed);
        self::assertTrue($billed[0]->charged);
        $orderId = $billed[0]->orderId;
        $order = $this->order($orderId);
        self::assertSame([8, 'Karte'], [(int) $order['paymentMethod'], $order['paymentMethodTitle']]);
        self::assertSame($now, (int) $order['paymentSettledAt']);

        // Das Modul bekam Mandat, Versuch und Sicht mit der Abo-Zeile.
        self::assertCount(1, $this->card->charges);
        [$attempt, $view, $mandate] = $this->card->charges[0];
        self::assertSame('cus_1|pm_2', $mandate);
        self::assertTrue($attempt->charge);
        self::assertSame('8.3300', $attempt->amount);
        self::assertSame(['WEB-00090-1'], array_map(static fn ($l): string => $l->subscriptionNumber, $view->subscriptionLines));

        $row = $this->connection->fetchAssociative('SELECT state, charge, providerReference FROM tl_consho_payment_attempt WHERE pid = ?', [$orderId]);
        self::assertSame(['succeeded', 1, 'ch_1'], [$row['state'], (int) $row['charge'], $row['providerReference']]);
        self::assertSame(['8.33'], array_map(static fn ($a): string => number_format((float) $a, 2), $this->connection->fetchFirstColumn('SELECT amount FROM tl_consho_payment WHERE pid = ?', [$orderId])));

        self::assertSame('RE-00001', $this->connection->fetchOne("SELECT documentNumber FROM tl_consho_document WHERE pid = ? AND type = 'invoice'", [$orderId]), 'Rechnung bei Zahlung');
        self::assertSame([['subscriptionInvoice', $orderId, 'WEB-00090-1', $now]], $this->mails->getArrayCopy());
    }

    /**
     * P95: fehlgeschlagen → sofort „Auf Rechnung", ohne Gebühr, Rechnung und
     * Mail wie bisher; das Mandat bleibt.
     */
    public function testFehlgeschlagenGehtSofortAufRechnung(): void
    {
        $this->card->charge = ChargeResult::failed('Karte abgelaufen');
        $now = $this->at('2026-02-28 11:00');

        $orderId = $this->billingRun()->run($now)[0]->orderId;

        $order = $this->order($orderId);
        self::assertSame([6, 'Auf Rechnung'], [(int) $order['paymentMethod'], $order['paymentMethodTitle']]);
        self::assertNull($order['paymentChangedAt'], 'der eine Wechsel bleibt dem Kunden');
        self::assertNull($order['paymentSettledAt']);
        self::assertGreaterThan($now, (int) $order['paymentDueAt'], 'Zahlungsziel aus der neuen Rechnung');
        self::assertEqualsWithDelta(8.33, (float) $order['grandTotal'], 0.001, 'ohne Gebühr');
        self::assertSame('failed', $this->connection->fetchOne('SELECT state FROM tl_consho_payment_attempt WHERE pid = ?', [$orderId]));
        self::assertSame('RE-00001', $this->connection->fetchOne("SELECT documentNumber FROM tl_consho_document WHERE pid = ? AND type = 'invoice'", [$orderId]));
        self::assertSame([['subscriptionInvoice', $orderId, 'WEB-00090-1', 0]], $this->mails->getArrayCopy());
        self::assertSame(['cus_1|pm_2', 8], [$this->subscription()['mandate'], (int) $this->subscription()['mandateMethod']], 'das Mandat bleibt');
        self::assertContains('Charging follow-up order WEB-00091 failed: Karte abgelaufen.', $this->log->getArrayCopy());

        // Die nächste Periode wird wieder abgebucht.
        $this->card->charge = ChargeResult::paid('8.33', 'ch_2');
        $next = $this->billingRun()->run($this->at('2026-03-31 11:00'));
        self::assertSame(8, (int) $this->order($next[0]->orderId)['paymentMethod']);
        self::assertCount(2, $this->card->charges);
    }

    public function testEinWerfendesModulIstEinFehlschlag(): void
    {
        $this->card->charge = new \RuntimeException('Anbieter nicht erreichbar');

        $orderId = $this->billingRun()->run($this->at('2026-02-28 11:00'))[0]->orderId;

        self::assertSame(6, (int) $this->order($orderId)['paymentMethod']);
        self::assertSame('failed', $this->connection->fetchOne('SELECT state FROM tl_consho_payment_attempt WHERE pid = ?', [$orderId]));
    }

    /**
     * P94, P97: ausstehend → keine Rechnung, keine Mail; die Meldung des
     * Anbieters bucht und schickt die abgebuchte Fassung.
     */
    public function testAusstehendWartetAufDieMeldung(): void
    {
        $this->card->charge = ChargeResult::pending('sepa_1');
        $now = $this->at('2026-02-28 11:00');

        $orderId = $this->billingRun()->run($now)[0]->orderId;

        self::assertSame('pending', $this->connection->fetchOne('SELECT state FROM tl_consho_payment_attempt WHERE pid = ?', [$orderId]));
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM tl_consho_document WHERE pid = ? AND type = 'invoice'", [$orderId]));
        // E7: der Kunde erfährt, dass abgebucht wird — mit Zeitraum; die Rechnung folgt mit der Zahlung.
        self::assertSame([['subscriptionChargePending', $orderId, 'WEB-00090-1', true]], $this->mails->getArrayCopy());

        // Ein zweiter Lauf bucht nicht noch einmal ab: Der Versuch steht schon.
        self::assertNull($this->charger()->charge($orderId, $now + 60));
        self::assertCount(1, $this->mails, 'und schreibt auch nicht noch einmal');
        self::assertSame([], $this->charger()->chargeOutstanding($now + 60));
        self::assertCount(1, $this->card->charges);

        // Drei Tage später meldet der Anbieter: bezahlt.
        $this->card->notification = NotificationResult::paid('8.33', 'sepa_1');
        $paidAt = $now + 3 * 86_400;
        self::assertSame(200, $this->processor()->process($this->tokenOf($orderId), new Request(), $paidAt));

        self::assertSame($paidAt, (int) $this->order($orderId)['paymentSettledAt']);
        self::assertSame('RE-00001', $this->connection->fetchOne("SELECT documentNumber FROM tl_consho_document WHERE pid = ? AND type = 'invoice'", [$orderId]));
        self::assertSame([['subscriptionChargePending', $orderId, 'WEB-00090-1', true], ['subscriptionInvoice', $orderId, 'WEB-00090-1', $paidAt]], $this->mails->getArrayCopy());
    }

    /**
     * P95 auch über die Meldung: Eine ausstehende Lastschrift, die der
     * Anbieter später als fehlgeschlagen meldet.
     */
    public function testEineSpaeterFehlgeschlageneLastschriftGehtAufRechnung(): void
    {
        $this->card->charge = ChargeResult::pending('sepa_1');
        $now = $this->at('2026-02-28 11:00');
        $orderId = $this->billingRun()->run($now)[0]->orderId;

        $this->card->notification = NotificationResult::failed();
        self::assertSame(200, $this->processor()->process($this->tokenOf($orderId), new Request(), $now + 86_400));

        self::assertSame(6, (int) $this->order($orderId)['paymentMethod']);
        self::assertSame([['subscriptionChargePending', $orderId, 'WEB-00090-1', true], ['subscriptionInvoice', $orderId, 'WEB-00090-1', 0]], $this->mails->getArrayCopy());
    }

    /**
     * P96: Ausstehend länger als 7 Tage → wie P95, im stündlichen Lauf. Nicht
     * vorher, und der abgebrochene Versuch wird dem Modul gemeldet.
     */
    public function testNachSiebenTagenOhneAntwortAufRechnung(): void
    {
        $this->card->charge = ChargeResult::pending('sepa_1');
        $now = $this->at('2026-02-28 11:00');
        $orderId = $this->billingRun()->run($now)[0]->orderId;
        $createdAt = (int) $this->order($orderId)['createdAt'];

        self::assertSame([], $this->fallback()->lapse($createdAt + 7 * 86_400 - 1));
        self::assertSame(8, (int) $this->order($orderId)['paymentMethod']);

        self::assertSame([$orderId], $this->fallback()->lapse($createdAt + 7 * 86_400));
        self::assertSame(6, (int) $this->order($orderId)['paymentMethod']);
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT state FROM tl_consho_payment_attempt WHERE pid = ?', [$orderId]));
        self::assertCount(1, $this->card->cancelled);
        self::assertSame([['subscriptionChargePending', $orderId, 'WEB-00090-1', true], ['subscriptionInvoice', $orderId, 'WEB-00090-1', 0]], $this->mails->getArrayCopy());

        // Ein zweiter Lauf findet nichts mehr.
        self::assertSame([], $this->fallback()->lapse($createdAt + 8 * 86_400));
    }

    /**
     * P96 fragt die BESTELLUNG: Auch eine, deren Abbuchung nie begann, geht
     * nach 7 Tagen auf Rechnung — vorher holt der Lauf die Abbuchung nach.
     */
    public function testEineNieBegonneneAbbuchungWirdNachgeholt(): void
    {
        $now = $this->at('2026-02-28 11:00');
        // Der Lauf stirbt zwischen Commit und Abbuchung: ohne Abbucher abgerechnet.
        $billed = $this->biller()->billDue(1, $now);
        self::assertNotNull($billed);
        self::assertTrue($billed->charged);
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM tl_consho_payment_attempt WHERE pid = ?', [$billed->orderId]));

        $this->card->charge = ChargeResult::paid('8.33', 'ch_1');
        self::assertSame([$billed->orderId], $this->charger()->chargeOutstanding($now + 3_600));
        self::assertNotNull($this->order($billed->orderId)['paymentSettledAt']);

        // Nichts doppelt.
        self::assertSame([], $this->charger()->chargeOutstanding($now + 7_200));
        self::assertNull($this->charger()->charge($billed->orderId, $now + 7_200));
        self::assertCount(1, $this->card->charges);
    }

    /**
     * Wer im Browser gerade selbst zahlt, wird nicht umgestellt; eine
     * bezahlte oder stornierte Bestellung auch nicht.
     */
    public function testDerRueckfallLaesstBezahltesUndKundenzahlungenInRuhe(): void
    {
        $this->card->charge = ChargeResult::pending('sepa_1');
        $now = $this->at('2026-02-28 11:00');
        $orderId = $this->billingRun()->run($now)[0]->orderId;
        $later = (int) $this->order($orderId)['createdAt'] + 8 * 86_400;

        $this->connection->insert('tl_consho_payment_attempt', ['pid' => $orderId, 'method' => 8, 'methodType' => 'card', 'state' => 'open', 'tokenHash' => str_repeat('f', 64), 'createdAt' => $later - 60, 'charge' => 0]);
        self::assertSame([], $this->fallback()->lapse($later), 'der Kunde zahlt gerade');

        $this->connection->delete('tl_consho_payment_attempt', ['tokenHash' => str_repeat('f', 64)]);
        $this->connection->insert('tl_consho_payment', ['pid' => $orderId, 'amount' => '1.0000', 'tstamp' => $later]);
        self::assertSame([], $this->fallback()->lapse($later), 'angezahlt');
        self::assertSame(8, (int) $this->order($orderId)['paymentMethod']);
    }

    /**
     * Review S1: Hat der Betreiber schon eine Zahlung erfasst, holt der Lauf
     * die Abbuchung nicht nach — der volle Betrag würde überzahlen.
     */
    public function testNachholenNichtWennSchonGezahltWurde(): void
    {
        $now = $this->at('2026-02-28 11:00');
        $billed = $this->biller()->billDue(1, $now);
        self::assertNotNull($billed);
        $this->connection->insert('tl_consho_payment', ['pid' => $billed->orderId, 'amount' => '3.0000', 'tstamp' => $now]);

        $this->card->charge = ChargeResult::paid('8.33', 'ch_1');
        self::assertSame([], $this->charger()->chargeOutstanding($now + 3_600));
        self::assertNull($this->charger()->charge($billed->orderId, $now + 3_600), 'auch direkt nicht');
        self::assertSame([], $this->card->charges);

        $this->connection->delete('tl_consho_payment', ['pid' => $billed->orderId]);
        $this->connection->update('tl_consho_order', ['cancelledAt' => $now], ['id' => $billed->orderId]);
        self::assertSame([], $this->charger()->chargeOutstanding($now + 3_600), 'storniert');
        self::assertNull($this->charger()->charge($billed->orderId, $now + 3_600));
        self::assertSame([], $this->card->charges);
    }

    /**
     * Review S2: Kommt das Geld einer Lastschrift erst nach dem Rückfall,
     * wird es gebucht (Z3) — aber mit „Zahlung erhalten", nicht mit
     * „abgebucht über Auf Rechnung".
     */
    public function testSpaetesGeldNachDemRueckfallIstZahlungErhalten(): void
    {
        $this->card->charge = ChargeResult::pending('sepa_1');
        $now = $this->at('2026-02-28 11:00');
        $orderId = $this->billingRun()->run($now)[0]->orderId;
        $lapsedAt = (int) $this->order($orderId)['createdAt'] + 7 * 86_400;
        self::assertSame([$orderId], $this->fallback()->lapse($lapsedAt));

        $this->card->notification = NotificationResult::paid('8.33', 'sepa_1');
        self::assertSame(200, $this->processor()->process($this->tokenOf($orderId), new Request(), $lapsedAt + 86_400));

        self::assertSame(
            [['subscriptionChargePending', $orderId, 'WEB-00090-1', true], ['subscriptionInvoice', $orderId, 'WEB-00090-1', 0], ['paymentReceived', $orderId]],
            $this->mails->getArrayCopy(),
        );
    }

    /**
     * Review S5: Ohne „Auf Rechnung" im Shop wiederholt der stündliche Lauf
     * keine Warnung — der Befund aus P24 sagt es. Stornierte Bestellungen
     * sucht er gar nicht erst.
     */
    public function testDerRueckfallLaufWiederholtSichNichtImLog(): void
    {
        $this->card->charge = ChargeResult::pending('sepa_1');
        $now = $this->at('2026-02-28 11:00');
        $orderId = $this->billingRun()->run($now)[0]->orderId;
        $later = (int) $this->order($orderId)['createdAt'] + 8 * 86_400;
        $this->connection->update('tl_consho_payment_method', ['published' => 0], ['id' => 6]);
        $this->log->exchangeArray([]);

        self::assertSame([], $this->fallback()->lapse($later));
        self::assertSame([], $this->fallback()->lapse($later + 3_600));
        self::assertSame([], $this->log->getArrayCopy());

        $this->connection->update('tl_consho_payment_method', ['published' => 1], ['id' => 6]);
        $this->connection->update('tl_consho_order', ['cancelledAt' => $later], ['id' => $orderId]);
        self::assertSame([], $this->fallback()->lapse($later + 7_200), 'storniert');
        self::assertSame(8, (int) $this->order($orderId)['paymentMethod']);
    }

    /**
     * P99: Die Zahlungsart des Mandats ist nicht mehr veröffentlicht → „Auf
     * Rechnung", eine Logzeile, nichts abgebucht.
     */
    public function testEineNichtMehrVerfuegbareZahlungsartRechnetAufRechnungAb(): void
    {
        $this->connection->update('tl_consho_payment_method', ['published' => 0], ['id' => 8]);

        $billed = $this->billingRun()->run($this->at('2026-02-28 11:00'));

        self::assertFalse($billed[0]->charged);
        self::assertSame(6, (int) $this->order($billed[0]->orderId)['paymentMethod']);
        self::assertSame([], $this->card->charges);
        self::assertContains('Subscription WEB-00090-1 was billed "on account": the payment method 8 of its mandate is not available any more.', $this->log->getArrayCopy());
        self::assertSame([['subscriptionInvoice', $billed[0]->orderId, 'WEB-00090-1', 0]], $this->mails->getArrayCopy());
    }

    public function testOhneMandatWieBisher(): void
    {
        $this->connection->update('tl_consho_subscription', ['mandate' => ''], ['id' => 1]);

        $billed = $this->billingRun()->run($this->at('2026-02-28 11:00'));

        self::assertFalse($billed[0]->charged);
        self::assertSame(6, (int) $this->order($billed[0]->orderId)['paymentMethod']);
        self::assertSame([], $this->log->getArrayCopy(), 'kein Mandat ist kein Befund');
    }

    // ── Bausteine ───────────────────────────────────────────────────────────

    private function billingRun(): SubscriptionBillingRun
    {
        return new SubscriptionBillingRun($this->connection, $this->biller(), $this->links(), $this->mail(), $this->logger(), $this->charger());
    }

    private function charger(): SubscriptionCharger
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $p): string => 'https://shop.test/'.$name.'/'.$p['token']);

        return new SubscriptionCharger(
            $this->connection,
            new DbalPaymentChangeRepository($this->connection),
            new DbalPaymentMethodRepository($this->connection),
            $this->handlers(),
            new DbalPaymentAttemptRepository($this->connection),
            $router,
            new SubscriptionMandates($this->connection),
            $this->paymentRecorded(),
            $this->fallback(),
            $this->logger(),
        );
    }

    private function fallback(): SubscriptionChargeFallback
    {
        $methods = new DbalPaymentMethodRepository($this->connection);

        return new SubscriptionChargeFallback(
            $this->connection,
            new DbalPaymentChangeRepository($this->connection),
            new SubscriptionBillingMethod($methods, $this->handlers(), $this->logger()),
            $methods,
            $this->handlers(),
            new DbalDocumentRepository($this->connection),
            $this->issuer(),
            $this->createStub(DocumentArchiverInterface::class),
            $this->links(),
            $this->mail(),
            $this->logger(),
        );
    }

    private function processor(): NotificationProcessor
    {
        return new NotificationProcessor(
            $this->connection,
            new DbalPaymentAttemptRepository($this->connection),
            new DbalPaymentMethodRepository($this->connection),
            $this->handlers(),
            $this->paymentRecorded(),
            $this->logger(),
            new SubscriptionMandates($this->connection),
            $this->fallback(),
        );
    }

    private function paymentRecorded(): PaymentRecorded
    {
        return new PaymentRecorded(
            $this->connection,
            new DbalPaymentChangeRepository($this->connection),
            $this->balances(),
            new DbalPaymentMethodRepository($this->connection),
            $this->handlers(),
            $this->issuer(),
            $this->createStub(DocumentArchiverInterface::class),
            $this->statuses(),
            $this->links(),
            $this->mail(),
            new NullLogger(),
        );
    }

    private function biller(): SubscriptionBiller
    {
        $methods = new DbalPaymentMethodRepository($this->connection);

        $rates = $this->createStub(TaxRateRepositoryInterface::class);
        $rates->method('findRate')->willReturn('20.0000');
        $countries = $this->createStub(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE']);

        $factory = new OrderFactory(
            new NumberRangeAllocator($this->connection),
            $this->createStub(CartLinePriceSource::class),
            new TaxResolver($rates, new CountryProvider($countries)),
            $this->createStub(OrderProductRepositoryInterface::class),
            new DbalOrderWriter($this->connection),
        );

        $deliveries = new DeliveryReported(
            $this->connection,
            new DbalPaymentChangeRepository($this->connection),
            $this->balances(),
            $methods,
            $this->handlers(),
            $this->issuer(),
            $this->createStub(DocumentArchiverInterface::class),
            $this->statuses(),
            $this->createStub(OrderPageLink::class),
            $this->createStub(OrderEventMail::class),
            new MoneyFormatter(),
        );

        return new SubscriptionBiller(
            $this->connection,
            new DbalSubscriptionLock($this->connection),
            $factory,
            new SubscriptionBillingMethod($methods, $this->handlers(), $this->logger()),
            $deliveries,
            new SubscriptionCalendar(self::ZONE),
            $this->createStub(DocumentArchiverInterface::class),
        );
    }

    private function handlers(): PaymentHandlers
    {
        $card = $this->card;

        return new PaymentHandlers(new ServiceLocator([
            PrepaymentHandler::getType() => static fn (): PaymentHandlerInterface => new PrepaymentHandler(),
            InvoicePaymentHandler::getType() => static fn (): PaymentHandlerInterface => new InvoicePaymentHandler(),
            FakeRecurringHandler::TYPE => static fn (): PaymentHandlerInterface => $card,
        ]));
    }

    private function mail(): OrderEventMail
    {
        $mails = $this->mails;
        $mail = $this->createMock(OrderEventMail::class);
        $mail->method('subscriptionInvoice')->willReturnCallback(static function (int $orderId, string $number, int $from, int $until, string $url, int $chargedAt = 0) use ($mails): bool {
            $mails->append(['subscriptionInvoice', $orderId, $number, $chargedAt]);

            return true;
        });
        $mail->method('paymentReceived')->willReturnCallback(static function (int $orderId) use ($mails): bool {
            $mails->append(['paymentReceived', $orderId]);

            return true;
        });
        $mail->method('subscriptionChargePending')->willReturnCallback(static function (int $orderId, string $number, int $from, int $until) use ($mails): bool {
            $mails->append(['subscriptionChargePending', $orderId, $number, $from > 0 && $until > $from]);

            return true;
        });

        return $mail;
    }

    private function links(): OrderPageLink
    {
        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/bestellung');

        return $links;
    }

    private function logger(): AbstractLogger
    {
        return new class($this->log) extends AbstractLogger {
            /**
             * @param \ArrayObject<int, string> $log
             */
            public function __construct(private readonly \ArrayObject $log)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->log->append((string) $message);
            }
        };
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

    private function balances(): OrderBalanceReader
    {
        return new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator());
    }

    /**
     * The notify token of the order's attempt is stored hashed — a charge
     * test sets one it knows.
     */
    private function tokenOf(int $orderId): string
    {
        $token = str_repeat('e', 64);
        $this->connection->update('tl_consho_payment_attempt', ['tokenHash' => hash('sha256', $token)], ['pid' => $orderId]);

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function order(int $orderId): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$orderId]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = 1');
        self::assertIsArray($row);

        return $row;
    }

    private function seed(): void
    {
        $c = $this->connection;

        $c->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop', 'country' => 'AT', 'vatId' => 'ATU12345678', 'fallbackLanguage' => 'de', 'paymentMethods' => serialize(['2', '6', '8']), 'invoiceCompany' => 'Muster', 'invoiceCountry' => 'AT']);
        $c->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'order', 'prefix' => 'WEB-', 'padding' => 5, 'current' => 90]);
        $c->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'invoice', 'prefix' => 'RE-', 'padding' => 5, 'current' => 0]);
        $c->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'cancellation', 'prefix' => 'ST-', 'padding' => 5, 'current' => 0]);
        $c->insert('tl_consho_payment_method', ['id' => 2, 'type' => 'prepayment', 'title' => 'Vorkasse', 'published' => 1]);
        $c->insert('tl_consho_payment_method', ['id' => 6, 'type' => 'invoice', 'title' => 'Auf Rechnung', 'published' => 1, 'jsonData' => json_encode(['dueDays' => 14], JSON_THROW_ON_ERROR)]);
        $c->insert('tl_consho_payment_method', ['id' => 8, 'type' => FakeRecurringHandler::TYPE, 'title' => 'Karte', 'published' => 1]);

        $start = $this->at('2026-01-31 10:15');

        $c->insert('tl_consho_order', [
            'id' => 90, 'orderNumber' => 'WEB-00090', 'shop' => 1, 'member' => 7, 'language' => 'de', 'currency' => 'EUR', 'createdAt' => $start,
            'firstname' => 'John', 'lastname' => 'Muster', 'email' => 'kunde@example.org', 'company' => 'Muster GmbH',
            'billingStreet' => 'Hauptstraße 1', 'billingPostal' => '1100', 'billingCity' => 'Wien', 'billingCountry' => 'AT',
            'shippingCountry' => 'DE', 'vatId' => 'DE123456789', 'businessCustomer' => 1, 'paymentMethod' => 8, 'paymentMethodTitle' => 'Karte',
        ]);
        $c->insert('tl_consho_order_item', ['id' => 2, 'pid' => 90, 'lineType' => 'product', 'product' => 4, 'productName' => 'E-Book Kräuterkunde', 'sku' => 'EB-001', 'kind' => 'digital', 'quantity' => '2.0000', 'lineTotal' => '8.3300']);

        $c->insert('tl_consho_subscription', [
            'id' => 1, 'subscriptionNumber' => 'WEB-00090-1', 'shop' => 1, 'member' => 7, 'product' => 4, 'firstOrder' => 90, 'orderItem' => 2,
            'rule' => 1, 'ruleTitle' => 'Monatsabo', 'termLength' => 1, 'termUnit' => 'year', 'billingInterval' => 1, 'renewal' => 'indefinite',
            'quantity' => '2.0000', 'unitNet' => '4.1667', 'taxClass' => 3, 'currency' => 'EUR', 'reverseCharge' => 1, 'businessCustomer' => 1,
            'kind' => 'digital', 'terms' => 'Monatsabo, 1 Jahr',
            'startedAt' => $start, 'minimumEndsAt' => $this->at('2027-01-31 10:15'),
            'periodFrom' => $start, 'periodUntil' => $this->at('2026-02-28 10:15'), 'nextBillingAt' => $this->at('2026-02-28 10:15'),
            'mandate' => 'cus_1|pm_2', 'mandateMethod' => 8,
        ]);
        $c->insert('tl_consho_entitlement', ['id' => 30, 'pid' => 90, 'orderItem' => 2, 'subscription' => 1, 'validUntil' => $this->at('2026-02-28 10:15')]);
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }
}

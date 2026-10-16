<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Digital;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveryHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveryHandlers;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalProductSettings;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalRelease;
use Webwerkwien\ContaoConshoBundle\Digital\Entitlement;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReport;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalance;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * F-4: Wann digitale Positionen freigegeben werden (F10, F16) und was die
 * Freigabe schreibt (F17).
 */
class DigitalReleaseTest extends TestCase
{
    private const CREATED = 1_758_000_000;

    private Connection $connection;

    /** @var array<string, mixed> */
    private array $order;

    /** @var array<int, string> */
    private array $open = [10 => '2.0000', 11 => '1.0000'];

    /** @var list<array{int, int, bool}> */
    private array $reported = [];

    private bool $handlerDelivers = true;

    private bool $handlerThrows = false;

    private string $zone = 'UTC';

    /** @var list<Entitlement> */
    private array $handed = [];

    protected function setUp(): void
    {
        // The period ends at the end of a day in the installation's zone.
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        // Without row locks: the voucher ledger reads under FOR UPDATE (WG-4).
        $this->connection = \Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks::connection();
        $c = $this->connection;
        // Aus der DCA (Fall 31, 57): die Ausgabe der Gutscheine (WG-4) liest Spalten, die eine abgeschriebene Tabelle nicht hatte.
        foreach (['tl_consho_order_item', 'tl_consho_product', 'tl_consho_voucher', 'tl_consho_voucher_booking', 'tl_consho_withdrawal', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_shop'] as $table) {
            \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($c, $table);
        }
        DigitalSchema::create($c);
        // Was eine Auslieferung schreibt, bevor sie „noch nicht" sagt (F-6).
        $c->executeStatement('CREATE TABLE handler_writes (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement INTEGER NOT NULL)');

        // Grace explicitly 0: the DCA default is 7 (the hand-written table had
        // 0), and the dates below count without it; one test sets it to 2.
        $c->insert('tl_consho_shop', ['id' => 1, 'withdrawalDays' => 14, 'withdrawalGraceDays' => 0]);
        // Zeile 10: E-Book (digital, Datei, 12 Monate), 11: Buch (körperlich).
        $c->insert('tl_consho_product', ['id' => 7, 'digitalDelivery' => 'test', 'entitlementMonths' => 12]);
        $c->insert('tl_consho_product', ['id' => 8]);
        $c->insert('tl_consho_order_item', ['id' => 10, 'pid' => 5, 'product' => 7, 'quantity' => 2, 'kind' => 'digital']);
        $c->insert('tl_consho_order_item', ['id' => 11, 'pid' => 5, 'product' => 8, 'quantity' => 1, 'kind' => 'physical']);
        $c->insert('tl_consho_order_item', ['id' => 12, 'pid' => 5, 'lineType' => 'shipping', 'kind' => 'digital']);

        $this->order = [
            'id' => 5, 'shop' => 1, 'member' => 3, 'createdAt' => self::CREATED, 'paymentMethod' => 1,
            'paymentSettledAt' => null, 'cancelledAt' => null, 'currency' => 'EUR', 'digitalConsentAt' => null, 'businessCustomer' => 0,
            'confirmationSentAt' => self::CREATED,
        ];
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    public function testVorkasseWartetAufDieZahlung(): void
    {
        $this->order['digitalConsentAt'] = self::CREATED;

        self::assertSame(0, $this->release()->releaseDue(5, self::CREATED + 60));
        self::assertSame([], $this->reported);
    }

    /**
     * Bezahlt und zugestimmt: Die Freigabe schreibt die Berechtigung mit
     * Menge und Ende, fragt die Auslieferung und meldet genau die digitale
     * Zeile als Lieferung — ohne „Versendet"-Mail.
     */
    public function testBezahltUndZugestimmtWirdFreigegeben(): void
    {
        $this->order['digitalConsentAt'] = self::CREATED;
        $this->order['paymentSettledAt'] = self::CREATED + 30;
        $now = self::CREATED + 60;

        self::assertSame(1, $this->release()->releaseDue(5, $now));

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_entitlement');
        self::assertIsArray($row);
        self::assertSame([5, 10, 3, 7, 'test', $now], [(int) $row['pid'], (int) $row['orderItem'], (int) $row['member'], (int) $row['product'], $row['deliveryType'], (int) $row['validFrom']]);
        // SQLite gibt NUMERIC ohne Nachkommastellen zurück, MariaDB decimal mit.
        self::assertEquals(2, (float) $row['quantity'], 'eine Berechtigung mit Menge (F7)');
        self::assertSame((new \DateTimeImmutable('@'.$now))->modify('+12 months')->getTimestamp(), (int) $row['validUntil']);
        // Kein Abo zur Zeile (Einmalkauf, oder gekauft vor Abo-3 — P22): wie bisher.
        self::assertSame(0, (int) $row['subscription']);

        self::assertCount(1, $this->handed);
        self::assertEquals([[10, 2.0]], array_map(static fn (array $r): array => [(int) $r['orderItem'], (float) $r['quantity']], $this->connection->fetchAllAssociative('SELECT orderItem, quantity FROM tl_consho_delivery_item')));
        self::assertSame([[1, $now, false]], $this->reported, 'gemeldet, ohne Mail');
    }

    /**
     * DL1: Eine Dienstleistung liefert nichts aus. Die Freigabe ist die
     * Berechtigung allein — auch wenn am Produkt aus einer früheren Art noch
     * eine Auslieferungsart steht (dafür gibt es den Befund).
     */
    public function testEineDienstleistungWirdOhneAuslieferungFreigeschaltet(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'service'], ['id' => 10]);
        $this->order['digitalConsentAt'] = self::CREATED;
        $this->order['paymentSettledAt'] = self::CREATED + 30;

        self::assertSame(1, $this->release()->releaseDue(5, self::CREATED + 60));

        self::assertSame('', $this->connection->fetchOne('SELECT deliveryType FROM tl_consho_entitlement WHERE orderItem = 10'));
        self::assertCount(0, $this->handed, 'keine Auslieferung gefragt');
    }

    /**
     * WG3, WG-4: Ein Wertgutschein wird keine Berechtigung, sondern je Stück
     * ein Gutschein — sobald bezahlt, ohne auf die Widerrufsfrist zu warten
     * und ohne Zustimmung. Wert und Bezahltes aus der Zeile, Währung und Shop
     * aus der Bestellung; die Zeile gilt als geliefert.
     */
    public function testEinBezahlterGutscheinWirdAusgegeben(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'voucher', 'lineTotal' => '90.0000', 'voucherValue' => '50.0000'], ['id' => 10]);
        $this->connection->update('tl_consho_product', ['voucherValidMonths' => 0], ['id' => 7]);
        $this->order['paymentSettledAt'] = self::CREATED + 30;

        self::assertSame(1, $this->release()->releaseDue(5, self::CREATED + 60));

        self::assertFalse($this->connection->fetchOne('SELECT id FROM tl_consho_entitlement'));
        self::assertCount(0, $this->handed);
        $vouchers = $this->connection->fetchAllAssociative('SELECT shop, currency, faceValue, paidAmount, balance, validUntil, source, orderId, orderItem FROM tl_consho_voucher ORDER BY id');
        self::assertCount(2, $vouchers, 'je Stück einer');
        self::assertEquals(['shop' => 1, 'currency' => 'EUR', 'faceValue' => 50, 'paidAmount' => 45, 'balance' => 50, 'validUntil' => null, 'source' => 'purchase', 'orderId' => 5, 'orderItem' => 10], array_map(static fn ($v) => is_numeric($v) ? (float) $v : $v, $vouchers[0]));
        self::assertEquals([[10, 2.0]], array_map(static fn (array $r): array => [(int) $r['orderItem'], (float) $r['quantity']], $this->connection->fetchAllAssociative('SELECT orderItem, quantity FROM tl_consho_delivery_item')));
    }

    public function testUnbezahltWirdKeinGutscheinAusgegeben(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'voucher', 'lineTotal' => '90.0000'], ['id' => 10]);
        // Zugestimmt und Frist vorbei: zählt für den Gutschein nicht, nur die Zahlung (WG6).
        $this->order['digitalConsentAt'] = self::CREATED;

        self::assertSame(0, $this->release()->releaseDue(5, self::CREATED + 30 * 86_400));
        self::assertFalse($this->connection->fetchOne('SELECT id FROM tl_consho_voucher'));
    }

    /**
     * Ohne Wert gilt, was für ein Stück bezahlt wurde; die Gültigkeit kommt
     * vom Grundprodukt und endet am letzten Tag.
     */
    public function testOhneWertDasBezahlteUndDieGueltigkeitAusMonaten(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'voucher', 'lineTotal' => '90.0000', 'quantity' => 2], ['id' => 10]);
        $this->connection->update('tl_consho_product', ['voucherValidMonths' => 36], ['id' => 7]);
        $this->order['paymentSettledAt'] = self::CREATED + 30;
        $now = self::CREATED + 60;

        $this->release()->releaseDue(5, $now);

        $voucher = $this->connection->fetchAssociative('SELECT faceValue, validUntil FROM tl_consho_voucher ORDER BY id LIMIT 1');
        self::assertIsArray($voucher);
        self::assertEquals(45, (float) $voucher['faceValue']);
        // Ausgegeben am 16.09.2025 (Wien) → gültig bis 16.09.2028. Ein festes
        // Datum, keine nachgerechnete Formel (Regel 24, Review WG-7).
        self::assertSame((int) strtotime('2028-09-16 00:00'), (int) $voucher['validUntil']);
    }

    /**
     * Review WG-7, L3: 31. Jänner plus ein Monat ist der 28. Februar, nicht
     * PHPs 3. März.
     */
    public function testEinMonatAbMonatsendeEndetAmMonatsende(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'voucher', 'lineTotal' => '45.0000', 'quantity' => 1], ['id' => 10]);
        $this->connection->update('tl_consho_product', ['voucherValidMonths' => 1], ['id' => 7]);
        $now = (int) strtotime('2027-01-31 12:00');
        $this->order['paymentSettledAt'] = $now - 60;

        $this->release()->releaseDue(5, $now);

        self::assertSame((int) strtotime('2027-02-28 00:00'), (int) $this->connection->fetchOne('SELECT validUntil FROM tl_consho_voucher ORDER BY id LIMIT 1'));
    }

    public function testEinAusgegebenerGutscheinWirdNichtNochEinmalAusgegeben(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'voucher', 'lineTotal' => '90.0000'], ['id' => 10]);
        $this->order['paymentSettledAt'] = self::CREATED + 30;

        $this->release()->releaseDue(5, self::CREATED + 60);

        // Geliefert gemeldet: die Zeile ist nicht mehr offen — wie der echte
        // Saldo es nach der Meldung sagt (OrderBalance aus den Lieferungen).
        unset($this->open[10]);
        self::assertSame(0, $this->release()->releaseDue(5, self::CREATED + 120));

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_voucher'));
    }

    /**
     * Abo-3, P21: Die Berechtigung einer Abo-Zeile zeigt auf ihr Abo und
     * endet mit der bezahlten Periode — nicht nach `entitlementMonths`, das
     * zum Einmalkauf gehört (hier 12 Monate am Produkt, bewusst stehen gelassen).
     */
    public function testEineAboZeileEndetMitDerPeriode(): void
    {
        $this->order['digitalConsentAt'] = self::CREATED;
        $this->order['paymentSettledAt'] = self::CREATED + 30;
        $periodUntil = self::CREATED + 30 * 86_400;

        $this->connection->insert('tl_consho_subscription', ['id' => 44, 'subscriptionNumber' => 'WEB-00005-1', 'orderItem' => 10, 'periodUntil' => $periodUntil]);
        // Gegenprobe: ein Abo einer anderen Zeile ändert an Zeile 10 nichts.
        $this->connection->insert('tl_consho_subscription', ['id' => 45, 'subscriptionNumber' => 'WEB-00006-1', 'orderItem' => 99, 'periodUntil' => 1]);

        self::assertSame(1, $this->release()->releaseDue(5, self::CREATED + 60));

        $row = $this->connection->fetchAssociative('SELECT subscription, validUntil FROM tl_consho_entitlement');
        self::assertIsArray($row);
        self::assertSame([44, $periodUntil], [(int) $row['subscription'], (int) $row['validUntil']]);

        // Die Auslieferung bekommt dasselbe Ende und dasselbe Abo.
        self::assertSame([44, $periodUntil], [$this->handed[0]->subscription, $this->handed[0]->validUntil]);
        self::assertNull($this->handed[0]->suspendedAt);
    }

    /**
     * Review Abo-5, L3: Wird freigegeben, während das Abo ruht, ruht die neue
     * Berechtigung von Anfang an — nicht erst ab dem nächsten Cron-Lauf.
     */
    public function testEineFreigabeWaehrendDasAboRuhtRuhtMit(): void
    {
        $this->order['digitalConsentAt'] = self::CREATED;
        $this->order['paymentSettledAt'] = self::CREATED + 30;
        $this->connection->insert('tl_consho_subscription', ['id' => 44, 'subscriptionNumber' => 'WEB-00005-1', 'orderItem' => 10, 'periodUntil' => self::CREATED + 30 * 86_400, 'state' => 'suspended']);

        $now = self::CREATED + 60;
        self::assertSame(1, $this->release()->releaseDue(5, $now));

        self::assertSame($now, (int) $this->connection->fetchOne('SELECT suspendedAt FROM tl_consho_entitlement'));
        self::assertSame($now, $this->handed[0]->suspendedAt, 'die Auslieferung sieht es auch');
        self::assertFalse($this->handed[0]->isActive($now));
    }

    /**
     * Abo-7b (P69): Die Verlängerung übernimmt die Berechtigung des alten
     * Abos — dieselbe Zeile, dieselben Schlüssel, kein Aufruf der Auslieferung.
     */
    public function testEineVerlaengerungUebernimmtDieBerechtigungDesAltenAbos(): void
    {
        $this->order['digitalConsentAt'] = self::CREATED;
        $this->order['paymentSettledAt'] = self::CREATED + 30;
        $periodUntil = self::CREATED + 60 * 86_400;

        // Altes Abo 40 aus Bestellung 3 (Zeile 7), seine Berechtigung 70.
        $this->connection->insert('tl_consho_subscription', ['id' => 40, 'subscriptionNumber' => 'WEB-00003-1', 'orderItem' => 7, 'periodUntil' => self::CREATED + 10 * 86_400, 'renewedBy' => 44]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 70, 'pid' => 3, 'orderItem' => 7, 'member' => 9, 'product' => 3, 'quantity' => '1.0000', 'deliveryType' => 'key', 'validFrom' => 1, 'validUntil' => self::CREATED + 10 * 86_400, 'subscription' => 40]);
        $this->connection->insert('tl_consho_subscription', ['id' => 44, 'subscriptionNumber' => 'WEB-00005-1', 'orderItem' => 10, 'periodUntil' => $periodUntil, 'predecessor' => 40]);

        self::assertSame(1, $this->release()->releaseDue(5, self::CREATED + 60));

        $rows = $this->connection->fetchAllAssociative('SELECT id, pid, orderItem, subscription, validUntil FROM tl_consho_entitlement');
        self::assertSame([[70, 5, 10, 44, $periodUntil]], array_map(static fn (array $r): array => array_map('intval', array_values($r)), $rows));
        self::assertSame([], $this->handed, 'kein neuer Schlüssel');

        // Geliefert gemeldet wie jede Freigabe — die Rechnung hängt daran.
        self::assertEquals([[10, 2.0]], array_map(static fn (array $r): array => [(int) $r['orderItem'], (float) $r['quantity']], $this->connection->fetchAllAssociative('SELECT orderItem, quantity FROM tl_consho_delivery_item')));
    }

    public function testOhneAlteBerechtigungWirdGewoehnlichFreigegeben(): void
    {
        // Das alte Abo wurde nie freigegeben (leerer Vorrat): neue Berechtigung.
        $this->order['digitalConsentAt'] = self::CREATED;
        $this->order['paymentSettledAt'] = self::CREATED + 30;
        $this->connection->insert('tl_consho_subscription', ['id' => 44, 'subscriptionNumber' => 'WEB-00005-1', 'orderItem' => 10, 'periodUntil' => self::CREATED + 30 * 86_400, 'predecessor' => 40]);

        self::assertSame(1, $this->release()->releaseDue(5, self::CREATED + 60));

        self::assertCount(1, $this->handed);
        self::assertSame(44, $this->handed[0]->subscription);
    }

    public function testAufRechnungMitDerBestellung(): void
    {
        $this->order['paymentMethod'] = 2;
        $this->order['businessCustomer'] = 1;

        self::assertSame(1, $this->release()->releaseDue(5, self::CREATED));
    }

    /**
     * F16: Ohne Zustimmung wartet ein Verbraucher das Ende der Frist ab —
     * dann wird freigegeben, das Widerrufsrecht ist bis dahin geblieben.
     */
    /**
     * F16, Review S1: Ohne Zustimmung erst nach Ablauf der Frist — ab dem Tag
     * des Vertragsabschlusses (§ 11 Abs. 2 Z 3 FAGG, § 355 Abs. 2 BGB), bis
     * zum ENDE des letzten Tages in der Zeitzone der Installation, plus
     * Kulanz, wie das Widerrufsfenster aus D. Bestellt am 16.09.2025 um 07:20
     * Wiener Zeit: frei ab 01.10.2025 00:00.
     */
    public function testOhneZustimmungNachAblaufDerFrist(): void
    {
        $this->order['paymentSettledAt'] = self::CREATED;
        $release = $this->release();
        $endOf30th = 1_759_269_599; // 30.09.2025 23:59:59 +02:00

        self::assertSame($endOf30th, $release->periodEndsAt($this->order));
        self::assertSame(0, $release->releaseDue(5, $endOf30th), 'am letzten Tag noch nicht');
        self::assertSame(1, $release->releaseDue(5, $endOf30th + 1));
    }

    public function testDieKulanzDesShopsZaehltMit(): void
    {
        $this->connection->update('tl_consho_shop', ['withdrawalGraceDays' => 2], ['id' => 1]);

        self::assertSame(1_759_442_399, $this->release()->periodEndsAt($this->order), '02.10.2025 23:59:59');
    }

    /**
     * Review B2: Ein erklärter, noch nicht entschiedener Widerruf hält die
     * Freigabe an — der Kunde ist womöglich zurückgetreten.
     */
    public function testEinOffenerWiderrufHaeltDieFreigabeAn(): void
    {
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 5, 'state' => 'received']);

        self::assertSame(0, $this->release()->releaseDue(5, self::CREATED));

        $this->connection->update('tl_consho_withdrawal', ['state' => 'rejected'], ['pid' => 5]);
        self::assertSame(1, $this->release()->releaseDue(5, self::CREATED), 'abgelehnt: frei');
    }

    /**
     * Review S2: Die Zustimmung lässt das Widerrufsrecht erst erlöschen, wenn
     * die Bestätigung auf dauerhaftem Datenträger beim Kunden ist. Ist die
     * Bestellbestätigung nicht hinausgegangen, wartet die Freigabe.
     */
    public function testZustimmungWirktErstMitVerschickterBestaetigung(): void
    {
        $this->order['digitalConsentAt'] = self::CREATED;
        $this->order['paymentSettledAt'] = self::CREATED;
        $this->order['confirmationSentAt'] = null;

        self::assertSame(0, $this->release()->releaseDue(5, self::CREATED + 60));

        // Zweiter Review, S-A: Kommt die Bestätigung nie, gilt die Frist wie
        // ohne Zustimmung — danach wird geliefert, nicht nie.
        self::assertSame(1, $this->release()->releaseDue(5, 1_759_269_600), 'nach Fristende auch ohne Bestätigung');
    }

    public function testMitNachgeholterBestaetigungSofort(): void
    {
        $this->order['digitalConsentAt'] = self::CREATED;
        $this->order['paymentSettledAt'] = self::CREATED;
        $this->order['confirmationSentAt'] = self::CREATED + 30;

        self::assertSame(1, $this->release()->releaseDue(5, self::CREATED + 60));
    }

    /**
     * Zweiter Review, S-B: Ruft jemand die Freigabe in seiner eigenen
     * Transaktion auf, bleibt dessen Ebene stehen — und die Entscheidung
     * über sie bei ihm.
     */
    public function testInEinerOffenenTransaktionDesAufrufers(): void
    {
        $this->handlerThrows = true;
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        $this->connection->beginTransaction();

        try {
            $this->release()->releaseDue(5, self::CREATED);
            self::fail('erwartet: Ausnahme');
        } catch (\LogicException) {
        }

        self::assertSame(1, $this->connection->getTransactionNestingLevel(), 'die Ebene des Aufrufers');
        $this->connection->rollBack();
    }

    /**
     * Spec F, offene Punkte (zweites Review, K-A): Scheitert auch das
     * Zurückrollen — die Verbindung ist weg —, kommt trotzdem die Ursache
     * beim Aufrufer an, nicht der Fehler des Zurückrollens.
     */
    public function testEinScheiterndesZurueckrollenVerdecktDieUrsacheNicht(): void
    {
        $this->handlerThrows = true;
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        SqliteWithoutRowLocks::$rollbackFails = true;

        try {
            $this->release()->releaseDue(5, self::CREATED);
            self::fail('erwartet: Ausnahme');
        } catch (\Throwable $e) {
            self::assertInstanceOf(\LogicException::class, $e, 'die Ursache, nicht „rollback failed"');
        } finally {
            SqliteWithoutRowLocks::$rollbackFails = false;

            while ($this->connection->isTransactionActive()) {
                try {
                    $this->connection->rollBack();
                } catch (\Throwable) {
                    break;
                }
            }
        }
    }

    /**
     * Review B1: Wirft eine Auslieferung, steht danach keine Transaktion mehr
     * offen — sonst liefe im Cron jede weitere Bestellung in einer, die nie
     * festgeschrieben wird.
     */
    public function testEineWerfendeAuslieferungHinterlaesstKeineOffeneTransaktion(): void
    {
        $this->handlerThrows = true;
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        try {
            $this->release()->releaseDue(5, self::CREATED);
            self::fail('erwartet: Ausnahme');
        } catch (\LogicException) {
        }

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_entitlement'));
    }

    /**
     * Review S4, Regel 24: dieselbe Freigabe mit der ECHTEN Lieferungs-
     * meldung statt eines Doubles — äußere Transaktion, Savepoint je Zeile,
     * report() mit eigenem begin/commit darin. Danach ist die Lieferung
     * gemeldet und alles festgeschrieben.
     */
    public function testMitDerEchtenLieferungsmeldung(): void
    {
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $orders->method('lockOrder')->willReturnCallback(fn (int $id): array|null => 5 === $id ? $this->asStrings($this->order) : null);

        $balances = $this->createStub(OrderBalanceReaderInterface::class);
        $balances->method('of')->willReturnCallback(fn (): OrderBalance => new OrderBalance('10.00', '10.00', PaymentState::Paid, DeliveryState::Open, $this->open, false));

        $methods = $this->createStub(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturn(new PaymentMethod(1, 'prepayment', 'Vorkasse', []));
        $paymentHandlers = new PaymentHandlers(new ServiceLocator(['prepayment' => static fn (): PaymentHandlerInterface => new PrepaymentHandler()]));

        $statuses = $this->createStub(\Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer::class);
        $archiver = $this->createStub(\Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface::class);
        $documents = $this->createStub(\Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface::class);
        $links = $this->createStub(\Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink::class);
        $mail = $this->createMock(\Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail::class);
        $mail->expects(self::never())->method('shipped');

        $real = new DeliveryReported($this->connection, $orders, $balances, $methods, $paymentHandlers, $documents, $archiver, $statuses, $links, $mail, new \Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter());

        $handler = $this->handler();
        $release = new DigitalRelease(
            $this->connection,
            $orders,
            $balances,
            $methods,
            $paymentHandlers,
            new DigitalProductSettings($this->connection),
            new DeliveryHandlers(new ServiceLocator(['test' => static fn (): DeliveryHandlerInterface => $handler])),
            $real,
            $archiver,
            $this->voucherIssuer(),
        );

        self::assertSame(1, $release->releaseDue(5, self::CREATED + 10));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(self::CREATED + 10, (int) $this->connection->fetchOne('SELECT reportedAt FROM tl_consho_delivery'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_entitlement'));
    }

    public function testDauerhaftOhneLaufzeit(): void
    {
        $this->connection->update('tl_consho_product', ['entitlementMonths' => 0], ['id' => 7]);
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        $this->release()->releaseDue(5, self::CREATED);

        self::assertNull($this->connection->fetchOne('SELECT validUntil FROM tl_consho_entitlement'));
    }

    /**
     * F19: Kann die Auslieferung noch nicht liefern — ein leerer Vorrat —,
     * bleibt die Zeile offen: keine Berechtigung, keine Lieferung.
     */
    public function testKannDieAuslieferungNichtLiefernBleibtDieZeileOffen(): void
    {
        $this->handlerDelivers = false;
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        self::assertSame(0, $this->release()->releaseDue(5, self::CREATED));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_entitlement'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_delivery'));
        self::assertSame([], $this->reported);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM handler_writes'), 'per Savepoint zurückgenommen, was sie schrieb');
    }

    /**
     * Review Nachlese, S2: Was spät freigegeben wird — ein aufgefüllter
     * Vorrat —, fragt sofort, ob die Bestellung ruht; ohne Freigabe nicht.
     */
    public function testEineFreigabeFragtObDieBestellungRuht(): void
    {
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        $purchases = $this->createMock(\Webwerkwien\ContaoConshoBundle\Digital\PurchaseDelinquency::class);
        $purchases->expects(self::once())->method('joinRest')->with(5, self::CREATED);

        self::assertSame(1, $this->release(purchases: $purchases)->releaseDue(5, self::CREATED));

        $this->handlerDelivers = false;
        $this->connection->executeStatement('DELETE FROM tl_consho_entitlement');
        $this->connection->executeStatement('DELETE FROM tl_consho_delivery_item');
        $this->connection->executeStatement('DELETE FROM tl_consho_delivery');
        $none = $this->createMock(\Webwerkwien\ContaoConshoBundle\Digital\PurchaseDelinquency::class);
        $none->expects(self::never())->method('joinRest');

        self::assertSame(0, $this->release(purchases: $none)->releaseDue(5, self::CREATED), 'nichts freigegeben: keine Frage');
    }

    public function testStornierteBestellungNicht(): void
    {
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;
        $this->order['cancelledAt'] = self::CREATED;

        self::assertSame(0, $this->release()->releaseDue(5, self::CREATED));
    }

    public function testNichtsMehrOffenNichtsZuTun(): void
    {
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;
        $this->open = [11 => '1.0000'];

        self::assertSame(0, $this->release()->releaseDue(5, self::CREATED));
    }

    public function testEinGescheitertesMeldenNimmtAllesZurueck(): void
    {
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        try {
            $this->release(DeliveryReport::tooMuch(['E-Book']))->releaseDue(5, self::CREATED);
            self::fail('erwartet: Ausnahme');
        } catch (\RuntimeException) {
        }

        // Scheitert es NACH den Zeilen (in report()), bleibt ebenso nichts offen.
        self::assertSame(0, $this->connection->getTransactionNestingLevel());

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_entitlement'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_delivery'));
    }

    /**
     * F-7: Nur der Cron lässt „bereit" schicken — mit dem Zeitpunkt der
     * Freigabe, damit die Mail nur das Neue nennt.
     */
    public function testNurAufWunschEineMail(): void
    {
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        // Ein Objekt, kein Array per Referenz: PHPStan verfolgt die Closure nicht.
        /** @var \ArrayObject<int, array{int, int, string}> $sent */
        $sent = new \ArrayObject();
        $mail = $this->createMock(\Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail::class);
        $mail->method('digitalReleased')->willReturnCallback(static function (int $orderId, int $since, string $url) use ($sent): bool {
            $sent[] = [$orderId, $since, $url];

            return true;
        });
        $links = $this->createStub(\Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/o');

        $this->release(null, $links, $mail)->releaseDue(5, self::CREATED);
        self::assertSame([], $sent->getArrayCopy(), 'Bestellung und Zahlung haben eigene Mails');

        $this->open = [10 => '2.0000'];
        $this->connection->executeStatement('DELETE FROM tl_consho_entitlement');
        $this->release(null, $links, $mail)->releaseDue(5, self::CREATED + 5, true);
        self::assertSame([[5, self::CREATED + 5, 'https://shop.test/o']], $sent->getArrayCopy());
    }

    /**
     * Abnahme DL, Fund 4 (Michael, 2026-10-02: „ja, Fund 4 wie vorgeschlagen"):
     * Stellt die Freigabe die Rechnung aus („Auf Rechnung", bei Lieferung),
     * geht die Freigabemail auch ohne Wunsch hinaus — mit der Rechnung. Sonst
     * erreicht sie den Kunden nur auf der Bestellseite.
     */
    public function testEineAusgestellteRechnungGehtMitDerFreigabemail(): void
    {
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        /** @var \ArrayObject<int, array{int, int|null}> $sent */
        $sent = new \ArrayObject();
        $mail = $this->createMock(\Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail::class);
        $mail->method('digitalReleased')->willReturnCallback(static function (int $orderId, int $since, string $url, int|null $invoiceId = null) use ($sent): bool {
            $sent[] = [$orderId, $invoiceId];

            return true;
        });
        $links = $this->createStub(\Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/o');

        $this->release(DeliveryReport::reported(false, 77), $links, $mail)->releaseDue(5, self::CREATED);

        self::assertSame([[5, 77]], $sent->getArrayCopy());
    }

    /**
     * WG4: Die Gutscheine gehen immer per Mail hinaus — sie ist der Weg zum
     * Käufer — und tragen die Rechnung, die die Freigabe ausstellt. Eine Mail
     * „digitale Inhalte bereit" gibt es dabei nicht: es wurde nichts
     * freigeschaltet.
     */
    public function testDieGutscheineGehenMitIhrerMailUndDerRechnung(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'voucher', 'lineTotal' => '90.0000'], ['id' => 10]);
        $this->order['paymentSettledAt'] = self::CREATED;

        /** @var \ArrayObject<int, array{int, int, int|null}> $sent */
        $sent = new \ArrayObject();
        $mail = $this->createMock(\Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail::class);
        $mail->expects(self::never())->method('digitalReleased');
        $mail->method('vouchersIssued')->willReturnCallback(static function (int $orderId, array $ids, string $url, int|null $invoiceId = null) use ($sent): bool {
            $sent[] = [$orderId, \count($ids), $invoiceId];

            return true;
        });
        $links = $this->createStub(\Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/o');

        $this->release(DeliveryReport::reported(false, 77), $links, $mail)->releaseDue(5, self::CREATED);

        self::assertSame([[5, 2, 77]], $sent->getArrayCopy());
    }

    /**
     * Gutschein und E-Book in einem Lauf: das E-Book trägt die Rechnung in
     * seiner Mail, die Gutscheinmail kommt ohne.
     */
    public function testNebenDigitalenInhaltenTraegtDieAndereMailDieRechnung(): void
    {
        $this->connection->insert('tl_consho_order_item', ['id' => 13, 'pid' => 5, 'product' => 7, 'quantity' => 1, 'kind' => 'voucher', 'lineTotal' => '25.0000']);
        $this->open[13] = '1';
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        /** @var \ArrayObject<int, array{string, int|null}> $sent */
        $sent = new \ArrayObject();
        $mail = $this->createMock(\Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail::class);
        $mail->method('digitalReleased')->willReturnCallback(static function (int $orderId, int $since, string $url, int|null $invoiceId = null) use ($sent): bool {
            $sent[] = ['digital', $invoiceId];

            return true;
        });
        $mail->method('vouchersIssued')->willReturnCallback(static function (int $orderId, array $ids, string $url, int|null $invoiceId = null) use ($sent): bool {
            $sent[] = ['vouchers', $invoiceId];

            return true;
        });
        $links = $this->createStub(\Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/o');

        $this->release(DeliveryReport::reported(false, 77), $links, $mail)->releaseDue(5, self::CREATED);

        self::assertSame([['digital', 77], ['vouchers', null]], $sent->getArrayCopy());
    }

    /**
     * Review Abo-4, S2: Die Rechnung, die eine Freigabe ausstellt, wird erst
     * NACH dem Commit der Freigabe archiviert — nicht darin, wo ein späterer
     * Rollback die Nummer freigäbe und die PDF-Datei zurückließe.
     */
    public function testDieRechnungWirdNachDemCommitArchiviert(): void
    {
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        $archiver = $this->createMock(\Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface::class);
        $archiver->expects(self::once())->method('archive')->willReturnCallback(function (int $id): void {
            self::assertSame(77, $id);
            self::assertSame(0, $this->connection->getTransactionNestingLevel(), 'nach dem Commit');
        });

        self::assertSame(1, $this->release(DeliveryReport::reported(false, 77), archiver: $archiver)->releaseDue(5, self::CREATED));
    }

    /**
     * WG-4: the real issuer, on the tables of this test (DigitalSchema).
     */
    private function voucherIssuer(): \Webwerkwien\ContaoConshoBundle\Voucher\VoucherIssuer
    {
        return new \Webwerkwien\ContaoConshoBundle\Voucher\VoucherIssuer($this->connection, new \Webwerkwien\ContaoConshoBundle\Voucher\VoucherLedger($this->connection));
    }

    private function release(DeliveryReport|null $outcome = null, \Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink|null $links = null, \Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail|null $mail = null, \Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface|null $archiver = null, \Webwerkwien\ContaoConshoBundle\Digital\PurchaseDelinquency|null $purchases = null): DigitalRelease
    {
        $orders = $this->createMock(PaymentChangeRepositoryInterface::class);
        // As DbalPaymentChangeRepository returns it: every value a string,
        // NULL as '' (acceptance 2026-09-29, rule 25).
        $orders->method('lockOrder')->willReturnCallback(fn (int $id): array|null => 5 === $id ? $this->asStrings($this->order) : null);
        $orders->method('order')->willReturnCallback(fn (int $id): array|null => 5 === $id ? $this->asStrings($this->order) : null);

        $balances = $this->createMock(OrderBalanceReaderInterface::class);
        $balances->method('of')->willReturnCallback(fn (): OrderBalance => new OrderBalance('10.00', '0.00', PaymentState::Open, DeliveryState::Open, $this->open, false));

        $methods = $this->createMock(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturnCallback(static fn (int $id): PaymentMethod|null => match ($id) {
            1 => new PaymentMethod(1, 'prepayment', 'Vorkasse', []),
            2 => new PaymentMethod(2, 'invoice', 'Auf Rechnung', []),
            default => null,
        });

        $handler = $this->handler();

        $deliveries = $this->createMock(DeliveryReported::class);
        $deliveries->method('report')->willReturnCallback(function (int $deliveryId, int $now, bool $mail) use ($outcome): DeliveryReport {
            $this->reported[] = [$deliveryId, $now, $mail];

            return $outcome ?? DeliveryReport::reported(false);
        });

        return new DigitalRelease(
            $this->connection,
            $orders,
            $balances,
            $methods,
            new PaymentHandlers(new ServiceLocator([
                'prepayment' => static fn (): PaymentHandlerInterface => new PrepaymentHandler(),
                'invoice' => static fn (): PaymentHandlerInterface => new InvoicePaymentHandler(),
            ])),
            new DigitalProductSettings($this->connection),
            new DeliveryHandlers(new ServiceLocator(['test' => static fn (): DeliveryHandlerInterface => $handler])),
            $deliveries,
            $archiver ?? $this->createStub(\Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface::class),
            $this->voucherIssuer(),
            $links,
            $mail,
            $purchases,
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function asStrings(array $row): array
    {
        return array_map(static fn ($v): string => (string) $v, $row);
    }

    /**
     * Beide Formen der Bestellzeile — roh aus der Datenbank (NULL) und
     * durch lockOrder() (''): Storniert ist keine, zugestimmt hat keine.
     */
    public function testNullUndLeerSindDasselbe(): void
    {
        $this->order['paymentSettledAt'] = self::CREATED;
        $release = $this->release();

        self::assertSame(1_759_269_599, $release->periodEndsAt($this->order), 'roh: keine Zustimmung');
        self::assertSame(1_759_269_599, $release->periodEndsAt($this->asStrings($this->order)), 'als Strings: ebenso');
        self::assertTrue($release->paymentReady($this->asStrings($this->order)));
        self::assertFalse($release->paymentReady($this->asStrings([...$this->order, 'paymentSettledAt' => null])));
    }

    private function handler(): DeliveryHandlerInterface
    {
        return new class($this) implements DeliveryHandlerInterface {
            public function __construct(private readonly DigitalReleaseTest $test)
            {
            }

            public static function getType(): string
            {
                return 'test';
            }

            public function release(Entitlement $entitlement): bool
            {
                return $this->test->handOut($entitlement);
            }
        };
    }

    /**
     * @internal the test handler's answer
     */
    public function handOut(Entitlement $entitlement): bool
    {
        if ($this->handlerThrows) {
            throw new \LogicException('Auslieferung kaputt');
        }

        $this->connection->insert('handler_writes', ['entitlement' => $entitlement->id]);

        if ($this->handlerDelivers) {
            $this->handed[] = $entitlement;
        }

        return $this->handlerDelivers;
    }
}

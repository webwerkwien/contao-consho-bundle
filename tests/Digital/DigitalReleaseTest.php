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
 * F-4: When digital items are released (F10, F16) and what the release
 * writes (F17).
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
        // From the DCA: issuing the vouchers (WG-4) reads columns a hand-copied table did not have.
        foreach (['tl_consho_order_item', 'tl_consho_product', 'tl_consho_voucher', 'tl_consho_voucher_booking', 'tl_consho_withdrawal', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_shop'] as $table) {
            \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($c, $table);
        }
        DigitalSchema::create($c);
        // What a delivery writes before it says "not yet" (F-6).
        $c->executeStatement('CREATE TABLE handler_writes (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement INTEGER NOT NULL)');

        // Grace explicitly 0: the DCA default is 7 (the hand-written table had
        // 0), and the dates below count without it; one test sets it to 2.
        $c->insert('tl_consho_shop', ['id' => 1, 'withdrawalDays' => 14, 'withdrawalGraceDays' => 0]);
        // Line 10: e-book (digital, file, 12 months), 11: book (physical).
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
     * Paid and consented: the release writes the entitlement with quantity and
     * end, asks the delivery and reports exactly the digital line as a
     * delivery — without a "shipped" mail.
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
        // SQLite returns NUMERIC without decimal places, MariaDB returns decimal with them.
        self::assertEquals(2, (float) $row['quantity'], 'eine Berechtigung mit Menge (F7)');
        self::assertSame((new \DateTimeImmutable('@'.$now))->modify('+12 months')->getTimestamp(), (int) $row['validUntil']);
        // No subscription for the line (one-off purchase, or bought before Abo-3 — P22): as before.
        self::assertSame(0, (int) $row['subscription']);

        self::assertCount(1, $this->handed);
        self::assertEquals([[10, 2.0]], array_map(static fn (array $r): array => [(int) $r['orderItem'], (float) $r['quantity']], $this->connection->fetchAllAssociative('SELECT orderItem, quantity FROM tl_consho_delivery_item')));
        self::assertSame([[1, $now, false]], $this->reported, 'gemeldet, ohne Mail');
    }

    /**
     * DL1: A service delivers nothing. The release is the entitlement alone —
     * even if a delivery type from an earlier kind is still set on the product
     * (there is an issue for that).
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
     * WG3, WG-4: A voucher does not become an entitlement but one voucher per
     * piece — as soon as it is paid, without waiting for the withdrawal period
     * and without consent. Value and amount paid from the line, currency and
     * shop from the order; the line counts as delivered.
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

    /**
     * EZ-5: class, rate and country come from the line that sold it — what
     * the line was sold as, not what the product says by the time it is paid.
     * A multi-purpose line leaves them empty.
     */
    public function testEinEinzweckGutscheinFriertKlasseSatzUndLandEin(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'voucher', 'lineTotal' => '60.0000', 'quantity' => 1, 'voucherValue' => '60.0000', 'voucherSinglePurpose' => 1, 'taxClass' => 3, 'taxRate' => '20.0000', 'taxCountry' => 'AT'], ['id' => 10]);
        $this->connection->update('tl_consho_product', ['voucherValidMonths' => 0, 'voucherSinglePurpose' => 0], ['id' => 7]);
        $this->order['paymentSettledAt'] = self::CREATED + 30;

        self::assertSame(1, $this->release()->releaseDue(5, self::CREATED + 60));

        self::assertEquals(['taxClass' => 3, 'taxRate' => 20, 'taxCountry' => 'AT'], array_map(static fn ($v) => is_numeric($v) ? (float) $v : $v, (array) $this->connection->fetchAssociative('SELECT taxClass, taxRate, taxCountry FROM tl_consho_voucher')));
    }

    public function testEinMehrzweckGutscheinHatKeineKlasse(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'voucher', 'lineTotal' => '60.0000', 'quantity' => 1, 'taxClass' => 3, 'taxRate' => '20.0000', 'taxCountry' => 'AT'], ['id' => 10]);
        $this->order['paymentSettledAt'] = self::CREATED + 30;

        self::assertSame(1, $this->release()->releaseDue(5, self::CREATED + 60));

        self::assertEquals(['taxClass' => 0, 'taxRate' => 0, 'taxCountry' => ''], array_map(static fn ($v) => is_numeric($v) ? (float) $v : $v, (array) $this->connection->fetchAssociative('SELECT taxClass, taxRate, taxCountry FROM tl_consho_voucher')));
    }

    public function testUnbezahltWirdKeinGutscheinAusgegeben(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'voucher', 'lineTotal' => '90.0000'], ['id' => 10]);
        // Consented and period over: does not count for the voucher, only the payment does (WG6).
        $this->order['digitalConsentAt'] = self::CREATED;

        self::assertSame(0, $this->release()->releaseDue(5, self::CREATED + 30 * 86_400));
        self::assertFalse($this->connection->fetchOne('SELECT id FROM tl_consho_voucher'));
    }

    /**
     * Without a value, what was paid for one piece applies; the validity comes
     * from the base product and ends on the last day.
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
        // Issued on 16.09.2025 (Vienna) → valid until 16.09.2028. A fixed
        // date, not a formula computed the same way as the code (WG-7).
        self::assertSame((int) strtotime('2028-09-16 00:00'), (int) $voucher['validUntil']);
    }

    /**
     * WG-7: 31 January plus one month is 28 February, not PHP's 3 March.
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

        // Reported as delivered: the line is no longer open — as the real
        // balance says after the report (OrderBalance from the deliveries).
        unset($this->open[10]);
        self::assertSame(0, $this->release()->releaseDue(5, self::CREATED + 120));

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_voucher'));
    }

    /**
     * Abo-3, P21: The entitlement of a subscription line points to its
     * subscription and ends with the paid period — not after
     * `entitlementMonths`, which belongs to the one-off purchase (12 months on
     * the product here, deliberately left in place).
     */
    public function testEineAboZeileEndetMitDerPeriode(): void
    {
        $this->order['digitalConsentAt'] = self::CREATED;
        $this->order['paymentSettledAt'] = self::CREATED + 30;
        $periodUntil = self::CREATED + 30 * 86_400;

        $this->connection->insert('tl_consho_subscription', ['id' => 44, 'subscriptionNumber' => 'WEB-00005-1', 'orderItem' => 10, 'periodUntil' => $periodUntil]);
        // Control case: a subscription of another line changes nothing on line 10.
        $this->connection->insert('tl_consho_subscription', ['id' => 45, 'subscriptionNumber' => 'WEB-00006-1', 'orderItem' => 99, 'periodUntil' => 1]);

        self::assertSame(1, $this->release()->releaseDue(5, self::CREATED + 60));

        $row = $this->connection->fetchAssociative('SELECT subscription, validUntil FROM tl_consho_entitlement');
        self::assertIsArray($row);
        self::assertSame([44, $periodUntil], [(int) $row['subscription'], (int) $row['validUntil']]);

        // The delivery gets the same end and the same subscription.
        self::assertSame([44, $periodUntil], [$this->handed[0]->subscription, $this->handed[0]->validUntil]);
        self::assertNull($this->handed[0]->suspendedAt);
    }

    /**
     * Abo-5: If the release happens while the subscription is suspended, the
     * new entitlement is suspended from the start — not only from the next
     * cron run.
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
     * Abo-7b (P69): The renewal takes over the old subscription's entitlement
     * — the same row, the same keys, no call to the delivery.
     */
    public function testEineVerlaengerungUebernimmtDieBerechtigungDesAltenAbos(): void
    {
        $this->order['digitalConsentAt'] = self::CREATED;
        $this->order['paymentSettledAt'] = self::CREATED + 30;
        $periodUntil = self::CREATED + 60 * 86_400;

        // Old subscription 40 from order 3 (line 7), its entitlement 70.
        $this->connection->insert('tl_consho_subscription', ['id' => 40, 'subscriptionNumber' => 'WEB-00003-1', 'orderItem' => 7, 'periodUntil' => self::CREATED + 10 * 86_400, 'renewedBy' => 44]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 70, 'pid' => 3, 'orderItem' => 7, 'member' => 9, 'product' => 3, 'quantity' => '1.0000', 'deliveryType' => 'key', 'validFrom' => 1, 'validUntil' => self::CREATED + 10 * 86_400, 'subscription' => 40]);
        $this->connection->insert('tl_consho_subscription', ['id' => 44, 'subscriptionNumber' => 'WEB-00005-1', 'orderItem' => 10, 'periodUntil' => $periodUntil, 'predecessor' => 40]);

        self::assertSame(1, $this->release()->releaseDue(5, self::CREATED + 60));

        $rows = $this->connection->fetchAllAssociative('SELECT id, pid, orderItem, subscription, validUntil FROM tl_consho_entitlement');
        self::assertSame([[70, 5, 10, 44, $periodUntil]], array_map(static fn (array $r): array => array_map('intval', array_values($r)), $rows));
        self::assertSame([], $this->handed, 'kein neuer Schlüssel');

        // Reported as delivered like every release — the invoice depends on it.
        self::assertEquals([[10, 2.0]], array_map(static fn (array $r): array => [(int) $r['orderItem'], (float) $r['quantity']], $this->connection->fetchAllAssociative('SELECT orderItem, quantity FROM tl_consho_delivery_item')));
    }

    public function testOhneAlteBerechtigungWirdGewoehnlichFreigegeben(): void
    {
        // The old subscription was never released (empty pool): new entitlement.
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
     * F16: Without consent a consumer waits for the end of the period — then
     * the release happens; the right of withdrawal has remained until then.
     */
    /**
     * F16: Without consent only after the period has expired — from the day
     * the contract was concluded (§ 11 Abs. 2 Z 3 FAGG, § 355 Abs. 2 BGB),
     * until the END of the last day in the installation's time zone, plus
     * goodwill buffer, like the withdrawal window from D. Ordered on
     * 16.09.2025 at 07:20 Vienna time: free from 01.10.2025 00:00.
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
     * A declared withdrawal that has not been decided yet holds the release
     * back — the customer may have withdrawn.
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
     * Consent only makes the right of withdrawal expire once the confirmation
     * on a durable medium has reached the customer. If the order confirmation
     * has not gone out, the release waits.
     */
    public function testZustimmungWirktErstMitVerschickterBestaetigung(): void
    {
        $this->order['digitalConsentAt'] = self::CREATED;
        $this->order['paymentSettledAt'] = self::CREATED;
        $this->order['confirmationSentAt'] = null;

        self::assertSame(0, $this->release()->releaseDue(5, self::CREATED + 60));

        // If the confirmation never comes, the period applies as without
        // consent — after it, delivery happens, not never.
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
     * If someone calls the release within their own transaction, their level
     * stays open — and the decision about it stays with them.
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
     * Spec F, open points: If the rollback fails too — the connection is gone
     * —, the cause still reaches the caller, not the error of the rollback.
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
     * If a delivery throws, no transaction is left open afterwards — otherwise
     * in the cron every further order would run in one that is never
     * committed.
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
     * The same release with the REAL delivery report instead of a double —
     * outer transaction, savepoint per line, report() with its own
     * begin/commit inside. Afterwards the delivery is reported and everything
     * committed.
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
     * F19: If the delivery cannot deliver yet — an empty pool —, the line
     * stays open: no entitlement, no delivery.
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
     * Nachlese: What is released late — a refilled pool — asks right away
     * whether the order is suspended; without a release it does not.
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

        // If it fails AFTER the lines (in report()), nothing stays open either.
        self::assertSame(0, $this->connection->getTransactionNestingLevel());

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_entitlement'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_delivery'));
    }

    /**
     * F-7: Only the cron has "ready" sent — with the time of the release, so
     * that the mail only names what is new.
     */
    public function testNurAufWunschEineMail(): void
    {
        $this->order['businessCustomer'] = 1;
        $this->order['paymentSettledAt'] = self::CREATED;

        // An object, not an array by reference: PHPStan does not follow the closure.
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
     * DL:
     * If the release issues the invoice ("on account", on delivery), the
     * release mail goes out even without being requested — with the invoice.
     * Otherwise it only reaches the customer on the order page.
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
     * WG4: The vouchers always go out by mail — it is the way to the buyer —
     * and carry the invoice the release issues. There is no "digital content
     * ready" mail in that case: nothing was activated.
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
     * Voucher and e-book in one run: the e-book carries the invoice in its
     * mail, the voucher mail comes without.
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
     * Abo-4: The invoice a release issues is only archived AFTER the release's
     * commit — not inside it, where a later rollback would free the number
     * and leave the PDF file behind.
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
        // NULL as '' — the shape the runtime really hands over.
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
     * Both forms of the order row — raw from the database (NULL) and through
     * lockOrder() (''): neither is cancelled, neither has consented.
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Contao\CoreBundle\Intl\Countries;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Webwerkwien\ContaoConshoBundle\Address\AddressNormalizer;
use Webwerkwien\ContaoConshoBundle\Address\PhoneNormalizer;
use Webwerkwien\ContaoConshoBundle\Checkout\MemberCheckoutDefaults;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReport;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;
use Webwerkwien\ContaoConshoBundle\Order\OrderDraft;
use Webwerkwien\ContaoConshoBundle\Order\OrderFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineDraft;
use Webwerkwien\ContaoConshoBundle\Payment\DbalPaymentMethodRepository;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBiller;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingMethod;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-4: billing one period of a subscription (P24–P29, P31).
 *
 * Subscription 1 `WEB-00090-1`: monthly subscription, bought 31.01.2026 10:15 Vienna in order
 * 90, 2 licences at 4.1667 net, reverse charge, minimum term until
 * 31.01.2027. After the purchase, period 1 runs until 28.02. 10:15.
 *
 * The tables come from the DCAs. Doubled are only the row lock (SQLite
 * has no FOR UPDATE), the order factory (its frozen line is checked by
 * OrderFactoryTest) and the delivery report (DeliveryReportedTest) — here
 * what counts is WHAT they receive.
 */
class SubscriptionBillerTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    private Connection $connection;

    /** @var list<array{OrderDraft, list<OrderLineDraft>, int}> */
    private array $created = [];

    /** @var list<array{int, int, bool}> */
    private array $reported = [];

    private bool $factoryThrows = false;

    /** @var list<array{int, int}> invoice id, transaction level at archiving */
    private array $archived = [];

    /** @var list<string> */
    private array $warnings = [];

    private string $reportOutcome = 'reported';

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_shop', 'tl_consho_payment_method', 'tl_consho_translation', 'tl_consho_order', 'tl_consho_order_item', 'tl_consho_subscription', 'tl_consho_entitlement', 'tl_consho_delivery', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop', 'country' => 'AT', 'vatId' => 'ATU12345678', 'fallbackLanguage' => 'de', 'paymentMethods' => serialize(['2', '6'])]);
        $this->connection->insert('tl_consho_payment_method', ['id' => 2, 'type' => 'prepayment', 'title' => 'Vorkasse', 'published' => 1]);
        $this->connection->insert('tl_consho_payment_method', ['id' => 6, 'type' => 'invoice', 'title' => 'Auf Rechnung', 'instructions' => 'Zahlbar in 14 Tagen', 'published' => 1]);

        $this->connection->insert('tl_consho_order', [
            'id' => 90, 'orderNumber' => 'WEB-00090', 'shop' => 1, 'member' => 7, 'language' => 'de', 'currency' => 'EUR',
            'createdAt' => $this->at('2026-01-31 10:15'), 'firstname' => 'John', 'lastname' => 'Muster', 'email' => 'kunde@example.org',
            'company' => 'Muster GmbH', 'billingStreet' => 'Hauptstraße 1', 'billingPostal' => '1100', 'billingCity' => 'Wien', 'billingCountry' => 'AT',
            'shippingCountry' => 'DE', 'vatId' => 'DE123456789', 'businessCustomer' => 1, 'paymentMethod' => 2, 'paymentMethodTitle' => 'Vorkasse',
            'shippingMethod' => 3, 'shippingMethodTitle' => 'Elektronische Zustellung', 'digitalConsentAt' => $this->at('2026-01-31 10:15'),
        ]);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 90, 'lineType' => 'product', 'product' => 4, 'productName' => 'E-Book Kräuterkunde', 'sku' => 'EB-001', 'kind' => 'digital']);

        $this->connection->insert('tl_consho_subscription', [
            'id' => 1, 'subscriptionNumber' => 'WEB-00090-1', 'shop' => 1, 'member' => 7, 'product' => 4, 'firstOrder' => 90, 'orderItem' => 2,
            'rule' => 1, 'ruleTitle' => 'Monatsabo', 'termLength' => 1, 'termUnit' => 'year', 'billingInterval' => 1, 'renewal' => 'indefinite',
            'quantity' => '2.0000', 'unitNet' => '4.1667', 'taxClass' => 3, 'currency' => 'EUR', 'reverseCharge' => 1, 'businessCustomer' => 1,
            'kind' => 'digital', 'terms' => '4,17 € / Monat · Laufzeit 1 Jahr',
            'startedAt' => $this->at('2026-01-31 10:15'), 'minimumEndsAt' => $this->at('2027-01-31 10:15'),
            'periodFrom' => $this->at('2026-01-31 10:15'), 'periodUntil' => $this->at('2026-02-28 10:15'), 'nextBillingAt' => $this->at('2026-02-28 10:15'),
        ]);

        $this->connection->insert('tl_consho_entitlement', ['id' => 30, 'pid' => 90, 'orderItem' => 2, 'subscription' => 1, 'validUntil' => $this->at('2026-02-28 10:15')]);
        // Counter-check: the entitlement of another subscription.
        $this->connection->insert('tl_consho_entitlement', ['id' => 31, 'pid' => 80, 'orderItem' => 9, 'subscription' => 2, 'validUntil' => 123]);
    }

    public function testEineFaelligePeriodeWirdMitEingefrorenemPreisAbgerechnet(): void
    {
        $billed = $this->biller()->billDue(1, $this->at('2026-02-28 11:00'));

        self::assertNotNull($billed);
        self::assertCount(1, $this->created);
        [$draft, $lines, $timestamp] = $this->created[0];

        self::assertSame($this->at('2026-02-28 11:00'), $timestamp);
        self::assertSame([1, 7, 1, 'EUR', 'de', 'de'], [$draft->subscriptionId, $draft->memberId, $draft->shopId, $draft->currency, $draft->language, $draft->fallbackLanguage]);
        // P24: the invoice payment method, although the first payment was by prepayment.
        self::assertSame([6, 'Auf Rechnung', 'Zahlbar in 14 Tagen'], [$draft->paymentMethod, $draft->paymentMethodTitle, $draft->paymentInstructions]);
        // P26, P29: frozen, or taken from the last order.
        self::assertTrue($draft->frozenReverseCharge);
        self::assertTrue($draft->businessCustomer);
        self::assertSame(['DE123456789', 'AT', 'ATU12345678'], [$draft->customerVatId, $draft->shopCountry, $draft->shopVatId]);
        self::assertSame(['Muster GmbH', 'John', 'Muster', 'Hauptstraße 1', 'Wien', 'AT', 'kunde@example.org'], [$draft->billing->company, $draft->billing->firstname, $draft->billing->lastname, $draft->billing->street, $draft->billing->city, $draft->billing->country, $draft->billing->email]);
        self::assertSame('DE', $draft->shipping->country);
        // A12: no new withdrawal window, no new consent.
        self::assertNull($draft->digitalConsentAt);
        self::assertNull($draft->termsAcceptedAt);

        // P25: the frozen line.
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertTrue($line->frozen);
        // Compared as numbers: SQLite returns NUMERIC without decimals, MariaDB with them.
        self::assertSame([4, 2.0, 4.1667, 3, 1], [$line->productId, (float) $line->quantity, (float) $line->net, $line->taxClassId, $line->subscriptionRule]);
        self::assertSame(['E-Book Kräuterkunde', 'EB-001', '4,17 € / Monat · Laufzeit 1 Jahr', 'digital'], [$line->title, $line->sku, $line->terms, $line->kind]);

        self::assertSame([1, 'WEB-00090-1', 91], [$billed->subscriptionId, $billed->subscriptionNumber, $billed->orderId]);
    }

    public function testDiePeriodeKommtAusDemKalenderVomStart(): void
    {
        $billed = $this->biller()->billDue(1, $this->at('2026-02-28 11:00'));

        // Counted from the start on 31.01.: 28.02. → 31.03., not 28.03.
        self::assertSame(['2026-02-28 10:15', '2026-03-31 10:15'], [$this->local($billed?->periodFrom), $this->local($billed?->periodUntil)]);

        // The order carries its own period — the subscription moves on (P31).
        [$draft] = $this->created[0];
        self::assertSame(['2026-02-28 10:15', '2026-03-31 10:15'], [$this->local($draft->servicePeriodFrom), $this->local($draft->servicePeriodUntil)]);

        $row = $this->subscription();
        self::assertSame(['2026-02-28 10:15', '2026-03-31 10:15', '2026-03-31 10:15'], [$this->local($row['periodFrom']), $this->local($row['periodUntil']), $this->local($row['nextBillingAt'])]);
    }

    /**
     * P32: `nextBillingAt` can be brought forward by hand. The
     * period must not take that over — it comes from the calendar.
     */
    public function testEineVorgezogeneFaelligkeitVerschiebtDiePeriodeNicht(): void
    {
        $this->connection->update('tl_consho_subscription', ['nextBillingAt' => $this->at('2026-02-10 09:00')], ['id' => 1]);

        $billed = $this->biller()->billDue(1, $this->at('2026-02-10 09:30'));

        self::assertSame(['2026-02-28 10:15', '2026-03-31 10:15'], [$this->local($billed?->periodFrom), $this->local($billed?->periodUntil)]);
    }

    /**
     * P27, A6: no new entitlement, no key — the follow-up line counts
     * as delivered (which, via DeliveryReported, issues the invoice), and
     * the subscription's entitlement extends to the new end of the period.
     */
    public function testLieferungGemeldetUndBerechtigungVerlaengert(): void
    {
        $this->biller()->billDue(1, $this->at('2026-02-28 11:00'));

        $delivery = $this->connection->fetchAssociative('SELECT id, pid, shippedAt FROM tl_consho_delivery');
        self::assertIsArray($delivery);
        self::assertSame([91, $this->at('2026-02-28 11:00')], [(int) $delivery['pid'], (int) $delivery['shippedAt']]);
        self::assertEquals(
            [['orderItem' => 191, 'quantity' => 2.0]],
            array_map(static fn (array $r): array => ['orderItem' => (int) $r['orderItem'], 'quantity' => (float) $r['quantity']], $this->connection->fetchAllAssociative('SELECT orderItem, quantity FROM tl_consho_delivery_item')),
        );
        // Reported without the "shipped" mail — the invoice goes with a mail of its own (P30).
        self::assertSame([[(int) $delivery['id'], $this->at('2026-02-28 11:00'), false]], $this->reported);
        // The invoice as a PDF only after the commit.
        self::assertSame([[55, 0]], $this->archived);

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_entitlement'), 'keine neue Berechtigung');
        self::assertSame('2026-03-31 10:15', $this->local($this->connection->fetchOne('SELECT validUntil FROM tl_consho_entitlement WHERE id = 30')));
        self::assertSame(123, (int) $this->connection->fetchOne('SELECT validUntil FROM tl_consho_entitlement WHERE id = 31'));
    }

    /**
     * P29: a correction by the operator on a follow-up order carries forward —
     * the address comes from the subscription's LAST order.
     */
    public function testDieAdresseKommtAusDerLetztenBestellung(): void
    {
        $this->connection->insert('tl_consho_order', [
            'id' => 95, 'orderNumber' => 'WEB-00095', 'shop' => 1, 'member' => 7, 'language' => 'en', 'currency' => 'EUR', 'subscription' => 1,
            'createdAt' => $this->at('2026-02-01 00:00'), 'lastname' => 'Muster', 'billingStreet' => 'Neue Gasse 2', 'billingCity' => 'Graz', 'billingCountry' => 'AT',
            'email' => 'neu@example.org', 'businessCustomer' => 1,
        ]);
        // A follow-up order of ANOTHER subscription is not the last one of this subscription.
        $this->connection->insert('tl_consho_order', ['id' => 96, 'orderNumber' => 'WEB-00096', 'shop' => 1, 'subscription' => 2, 'createdAt' => $this->at('2026-02-20 00:00'), 'billingCity' => 'Linz']);

        $this->biller()->billDue(1, $this->at('2026-02-28 11:00'));

        [$draft] = $this->created[0];
        self::assertSame(['Graz', 'Neue Gasse 2', 'neu@example.org', 'en'], [$draft->billing->city, $draft->billing->street, $draft->billing->email, $draft->language]);
    }

    /**
     * Abo-Nachlese, P74: every new invoice takes the billing address currently
     * on file in the account. Salutation, language, VAT id and
     * delivery country stay those of the last order.
     */
    public function testDieRechnungsadresseKommtAusDemKonto(): void
    {
        $this->connection->update('tl_consho_order', ['salutation' => 'Frau'], ['id' => 90]);

        $this->biller($this->members('Kontogasse 5'))->billDue(1, $this->at('2026-02-28 11:00'));

        [$draft] = $this->created[0];
        self::assertSame(
            ['Muster KG', 'Frau', 'Johanna', 'Kontogasse 5', '8010', 'Graz', 'AT', 'konto@example.org'],
            [$draft->billing->company, $draft->billing->salutation, $draft->billing->firstname, $draft->billing->street, $draft->billing->postal, $draft->billing->city, $draft->billing->country, $draft->billing->email],
        );
        self::assertSame(['DE123456789', 'de', 'DE'], [$draft->customerVatId, $draft->language, $draft->shipping->country]);
    }

    /**
     * Abo-Nachlese: an e-mail address changed in the account but not confirmed
     * gets no invoice — that of the last order stays,
     * the postal address still comes from the account.
     */
    public function testEineUnbestaetigteKontoEMailBekommtKeineRechnung(): void
    {
        $this->biller($this->members('Kontogasse 5', confirmed: 'alt@example.org'))->billDue(1, $this->at('2026-02-28 11:00'));

        [$draft] = $this->created[0];
        self::assertSame(['Kontogasse 5', 'kunde@example.org'], [$draft->billing->street, $draft->billing->email]);
    }

    /**
     * Abo-Nachlese: a different country in the account — the previous
     * address stays (tax country), the log says so, the finding shows it.
     */
    public function testEinAnderesLandImKontoLaesstDieBisherigeAnschrift(): void
    {
        $this->biller($this->members('Hauptstr. 9', 'DE'))->billDue(1, $this->at('2026-02-28 11:00'));

        [$draft] = $this->created[0];
        self::assertSame(['Hauptstraße 1', 'Wien', 'AT', 'kunde@example.org'], [$draft->billing->street, $draft->billing->city, $draft->billing->country, $draft->billing->email]);
        self::assertStringContainsString('another country (DE instead of AT)', implode("\n", $this->warnings));
    }

    /**
     * DL4: a service subscription is taxable in the shop's country —
     * a move changes nothing about that. The new address from the account applies,
     * no log, and the follow-up line stays a service (OrderFactory sets the tax country
     * from it, see OrderFactoryTest).
     */
    public function testEinDienstleistungsAboNimmtDieNeueAnschriftAuchAusDemAusland(): void
    {
        $this->connection->update('tl_consho_subscription', ['kind' => 'service'], ['id' => 1]);

        $this->biller($this->members('Hauptstr. 9', 'DE'))->billDue(1, $this->at('2026-02-28 11:00'));

        [$draft, $lines] = $this->created[0];
        self::assertSame(['Hauptstr. 9', 'DE', 'AT'], [$draft->billing->street, $draft->billing->country, $draft->shopCountry]);
        self::assertSame('service', $lines[0]->kind);
        self::assertSame([], $this->warnings);
    }

    /**
     * Block R (R3): the follow-up order reads "tax in the country of destination"
     * from the shop as it stands today — not as at the purchase. Above the threshold
     * every later sale is taxable in the country of destination.
     */
    public function testDieFolgebestellungLiestDenSchalterAmShopNeu(): void
    {
        $this->biller()->billDue(1, $this->at('2026-02-28 11:00'));
        $this->connection->update('tl_consho_shop', ['taxAtDestination' => 1], ['id' => 1]);
        $this->biller()->billDue(1, $this->at('2026-03-31 11:00'));

        self::assertSame([false, true], array_map(static fn (array $created): bool => $created[0]->taxAtDestination, $this->created));
    }

    public function testEinUnvollstaendigesKontoLaesstDieLetzteBestellung(): void
    {
        // As with many real accounts: name and e-mail in the account, no street.
        $this->biller($this->members(''))->billDue(1, $this->at('2026-02-28 11:00'));

        [$draft] = $this->created[0];
        self::assertSame(['Hauptstraße 1', 'Wien', 'kunde@example.org'], [$draft->billing->street, $draft->billing->city, $draft->billing->email]);
    }

    /**
     * Abo-5 (P36): a suspended subscription keeps being billed — otherwise
     * all missed periods would arrive at once with the payment. The suspended
     * entitlement gets the new end and stays suspended.
     */
    public function testEinRuhendesAboWirdWeiterAbgerechnet(): void
    {
        $this->connection->update('tl_consho_subscription', ['state' => 'suspended'], ['id' => 1]);
        $this->connection->update('tl_consho_entitlement', ['suspendedAt' => $this->at('2026-02-20 00:00')], ['id' => 30]);

        self::assertNotNull($this->biller()->billDue(1, $this->at('2026-02-28 11:00')));
        self::assertSame('suspended', $this->connection->fetchOne('SELECT state FROM tl_consho_subscription WHERE id = 1'), 'der Biller weckt nicht');
        self::assertSame('2026-03-31 10:15', $this->local($this->connection->fetchOne('SELECT validUntil FROM tl_consho_entitlement WHERE id = 30')));
        self::assertNotNull($this->connection->fetchOne('SELECT suspendedAt FROM tl_consho_entitlement WHERE id = 30'));
    }

    /**
     * Abo-6 (P46): open-ended is billed monthly beyond the minimum term,
     * the boundaries counted from the start.
     */
    public function testUnbefristetGehtUeberDieMindestlaufzeitHinaus(): void
    {
        $this->connection->update('tl_consho_subscription', [
            'phase' => 'indefinite',
            'periodFrom' => $this->at('2026-12-31 10:15'), 'periodUntil' => $this->at('2027-01-31 10:15'), 'nextBillingAt' => $this->at('2027-01-31 10:15'),
        ], ['id' => 1]);

        $billed = $this->biller()->billDue(1, $this->at('2027-01-31 11:00'));

        self::assertSame(['2027-01-31 10:15', '2027-02-28 10:15'], [$this->local($billed?->periodFrom), $this->local($billed?->periodUntil)]);
    }

    /**
     * P49: a known end stops billing — in open-ended too.
     */
    public function testEinBekanntesEndeStopptDieAbrechnung(): void
    {
        $this->connection->update('tl_consho_subscription', [
            'phase' => 'indefinite', 'endsAt' => $this->at('2027-01-31 10:15'),
            'periodUntil' => $this->at('2027-01-31 10:15'), 'nextBillingAt' => $this->at('2027-01-31 10:15'),
        ], ['id' => 1]);

        self::assertNull($this->biller()->billDue(1, $this->at('2027-01-31 11:00')));
    }

    /**
     * P45: one payment per term (interval 0) — after the renewal by
     * the same term, the whole new term is billed.
     */
    public function testEineZahlungJeLaufzeitNachDerVerlaengerung(): void
    {
        $this->connection->update('tl_consho_subscription', [
            'billingInterval' => 0, 'renewal' => 'sameTerm',
            'periodUntil' => $this->at('2027-01-31 10:15'), 'nextBillingAt' => $this->at('2027-01-31 10:15'),
            'minimumEndsAt' => $this->at('2028-01-31 10:15'),
        ], ['id' => 1]);

        $billed = $this->biller()->billDue(1, $this->at('2027-01-31 11:00'));

        self::assertSame(['2027-01-31 10:15', '2028-01-31 10:15'], [$this->local($billed?->periodFrom), $this->local($billed?->periodUntil)]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function notDue(): iterable
    {
        yield 'noch nicht fällig' => [[], '2026-02-28 10:14'];
        // P28: the end of the minimum term is Abo-6.
        yield 'Ende der Mindestlaufzeit' => [['nextBillingAt' => '2027-01-31 10:15', 'periodUntil' => '2027-01-31 10:15'], '2027-02-01 00:00'];
        yield 'nicht aktiv' => [['state' => 'cancelled'], '2026-02-28 11:00'];
        // As the recorder writes it: next billing = end of the term.
        // Only a renewal by the same term makes it due (Abo-6, P45).
        yield 'eine Zahlung für die ganze Laufzeit' => [['billingInterval' => 0, 'nextBillingAt' => '2027-01-31 10:15', 'periodUntil' => '2027-01-31 10:15'], '2027-02-01 00:00'];
    }

    /**
     * @param array<string, mixed> $change
     *
     * @dataProvider notDue
     */
    public function testNichtFaelligHeisstNichts(array $change, string $now): void
    {
        if ([] !== $change) {
            // Points in time are given in the provider as Vienna local time.
            $change = array_map(fn (mixed $v): mixed => \is_string($v) && str_contains($v, ':') ? $this->at($v) : $v, $change);
            $this->connection->update('tl_consho_subscription', $change, ['id' => 1]);
        }

        self::assertNull($this->biller()->billDue(1, $this->at($now)));
        self::assertSame([], $this->created);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_delivery'));
    }

    public function testZweimalHintereinanderEineBestellung(): void
    {
        $biller = $this->biller();

        self::assertNotNull($biller->billDue(1, $this->at('2026-02-28 11:00')));
        // Being due is re-read under the lock: the period has moved on.
        self::assertNull($biller->billDue(1, $this->at('2026-02-28 11:00')));
        self::assertCount(1, $this->created);
    }

    public function testOhneRechnungsartKeineFolgebestellung(): void
    {
        $this->connection->update('tl_consho_shop', ['paymentMethods' => serialize(['2'])], ['id' => 1]);

        self::assertNull($this->biller()->billDue(1, $this->at('2026-02-28 11:00')));
        // Not only the finding, the log says so as well.
        self::assertStringContainsString('WEB-00090-1 was not billed', implode("\n", $this->warnings));
        self::assertSame([], $this->created);
        self::assertSame('2026-02-28 10:15', $this->local($this->subscription()['nextBillingAt']), 'Periode unverändert');
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function failures(): iterable
    {
        yield 'Bestellfabrik wirft' => [true, 'reported'];
        yield 'Lieferung nicht gemeldet' => [false, 'tooMuch'];
    }

    /**
     * @dataProvider failures
     */
    public function testEinFehlerRolltDiePeriodeZurueck(bool $factoryThrows, string $outcome): void
    {
        $this->factoryThrows = $factoryThrows;
        $this->reportOutcome = $outcome;

        try {
            $this->biller()->billDue(1, $this->at('2026-02-28 11:00'));
            self::fail('The failure was swallowed.');
        } catch (\RuntimeException) {
        }

        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame([], $this->archived, 'nichts archiviert, was zurückgerollt wurde');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_order WHERE subscription = 1'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_delivery'));
        self::assertSame('2026-02-28 10:15', $this->local($this->subscription()['nextBillingAt']));
        self::assertSame('2026-02-28 10:15', $this->local($this->connection->fetchOne('SELECT validUntil FROM tl_consho_entitlement WHERE id = 30')));
    }

    private function biller(MemberCheckoutDefaults|null $members = null): SubscriptionBiller
    {
        $orders = $this->createMock(OrderFactoryInterface::class);
        $orders->method('create')->willReturnCallback(function (OrderDraft $draft, array $lines, int $timestamp): int {
            if ($this->factoryThrows) {
                throw new \RuntimeException('number range gone');
            }

            $this->created[] = [$draft, $lines, $timestamp];
            $id = 90 + \count($this->created);
            $this->connection->insert('tl_consho_order', ['id' => $id, 'orderNumber' => 'WEB-000'.$id, 'shop' => $draft->shopId, 'subscription' => $draft->subscriptionId, 'createdAt' => $timestamp]);
            $this->connection->insert('tl_consho_order_item', ['id' => 100 + $id, 'pid' => $id, 'lineType' => 'product', 'product' => $lines[0]->productId, 'quantity' => $lines[0]->quantity, 'kind' => 'digital']);

            return $id;
        });

        $deliveries = $this->createMock(DeliveryReported::class);
        $deliveries->method('report')->willReturnCallback(function (int $deliveryId, int $now, bool $mail): DeliveryReport {
            $this->reported[] = [$deliveryId, $now, $mail];

            // Inside the biller's transaction the report hands the invoice back.
            return 'reported' === $this->reportOutcome ? DeliveryReport::reported(false, 55) : DeliveryReport::tooMuch(['E-Book']);
        });

        $archiver = $this->createStub(DocumentArchiverInterface::class);
        $archiver->method('archive')->willReturnCallback(function (int $id): void {
            $this->archived[] = [$id, $this->connection->getTransactionNestingLevel()];
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

        return new SubscriptionBiller(
            $this->connection,
            $lock,
            $orders,
            new SubscriptionBillingMethod(new DbalPaymentMethodRepository($this->connection)),
            $deliveries,
            new SubscriptionCalendar(self::ZONE),
            $archiver,
            new class(function (string $line): void { $this->warnings[] = $line; }) extends AbstractLogger {
                public function __construct(private readonly \Closure $write)
                {
                }

                public function log($level, \Stringable|string $message, array $context = []): void
                {
                    ($this->write)((string) $message);
                }
            },
            $members,
        );
    }

    /**
     * Account 7 with an address; an empty `street` means: incomplete.
     */
    private function members(string $street, string $country = 'AT', string $confirmed = 'konto@example.org'): MemberCheckoutDefaults
    {
        $this->connection->executeStatement("CREATE TABLE tl_member (id INTEGER PRIMARY KEY, company TEXT NOT NULL DEFAULT '', firstname TEXT NOT NULL DEFAULT '', lastname TEXT NOT NULL DEFAULT '', street TEXT NOT NULL DEFAULT '', postal TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', consho_confirmedEmail TEXT NOT NULL DEFAULT '')");
        $this->connection->insert('tl_member', [
            'id' => 7, 'company' => 'Muster KG', 'firstname' => 'Johanna', 'lastname' => 'Muster', 'street' => $street,
            'postal' => '8010', 'city' => 'Graz', 'country' => $country, 'email' => 'konto@example.org', 'consho_confirmedEmail' => $confirmed,
        ]);

        $countries = $this->createStub(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE']);
        $countries->method('getCountries')->willReturn(['AT' => 'Österreich', 'DE' => 'Deutschland']);

        return new MemberCheckoutDefaults($this->connection, new AddressNormalizer(new CountryProvider($countries), new PhoneNormalizer()));
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

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }

    private function local(mixed $timestamp): string
    {
        return (new \DateTimeImmutable('@'.(int) $timestamp))->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d H:i');
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRecorder;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRenewalRefused;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRuleGoneAtPurchase;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRules;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionWithoutAccount;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-3: Beim Kauf entsteht je Abo-Zeile ein Abo, aus der EINGEFRORENEN
 * Bestellzeile (A9, A10, P16–P19).
 *
 * Bestellung 90 „WEB-00090", gekauft am 31.01.2026 10:15 Wien, Konto 7:
 * Zeile 1 Einmalkauf, Zeile 2 Monatsabo (Regel 1) Menge 2, Zeile 3 Jahresabo
 * mit einer Zahlung (Regel 2). Die Tabellen kommen aus den DCAs.
 */
class SubscriptionRecorderTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    private Connection $connection;

    private int $purchase;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_order_item', 'tl_consho_subscription_rule', 'tl_consho_subscription'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->purchase = (new \DateTimeImmutable('2026-01-31 10:15', new \DateTimeZone(self::ZONE)))->getTimestamp();

        $this->connection->insert('tl_consho_subscription_rule', [
            'id' => 1, 'title' => 'Monatsabo', 'termLength' => 1, 'termUnit' => 'year', 'billingInterval' => 1,
            'renewal' => 'indefinite', 'noticeDays' => 14, 'reminderDays' => 30, 'graceDays' => 14, 'pauseMonths' => 2,
        ]);
        $this->connection->insert('tl_consho_subscription_rule', [
            'id' => 2, 'title' => 'Jahresabo', 'termLength' => 12, 'termUnit' => 'month', 'billingInterval' => 0,
            'renewal' => 'ends', 'noticeDays' => 0, 'reminderDays' => 20, 'graceDays' => 7,
        ]);

        $this->order(90, 'WEB-00090', member: 7);
        $this->line(1, 90, sorting: 128, product: 11, rule: 0);
        $this->line(2, 90, sorting: 256, product: 12, rule: 1, quantity: '2.0000', unitPrice: '4.1667', terms: 'Monatsabo, 12 Monate', priceSource: 'reverseCharge');
        $this->line(3, 90, sorting: 384, product: 13, rule: 2, unitPrice: '40.0000', terms: 'Jahresabo, eine Zahlung', kind: 'service');
    }

    public function testJeAboZeileEinAboMitNummerAusDerBestellung(): void
    {
        $ids = $this->recorder()->record(90);

        $rows = $this->connection->fetchAllAssociative('SELECT id, subscriptionNumber, orderItem, firstOrder, product, quantity FROM tl_consho_subscription ORDER BY id');

        self::assertSame(array_map(static fn (array $row): int => (int) $row['id'], $rows), $ids);
        // P19 = B: die Einmalkauf-Zeile zählt nicht mit.
        self::assertSame(['WEB-00090-1', 'WEB-00090-2'], array_column($rows, 'subscriptionNumber'));
        self::assertSame([2, 3], array_map('intval', array_column($rows, 'orderItem')));
        self::assertSame([90, 90], array_map('intval', array_column($rows, 'firstOrder')));
        self::assertSame([12, 13], array_map('intval', array_column($rows, 'product')));
        // A10: Menge = Lizenzen.
        self::assertSame([2.0, 1.0], array_map('floatval', array_column($rows, 'quantity')));
    }

    public function testDieReihenfolgeFolgtDerSortierungNichtDerId(): void
    {
        $this->connection->update('tl_consho_order_item', ['sorting' => 512], ['id' => 2]);

        $this->recorder()->record(90);

        self::assertSame(
            ['WEB-00090-1' => 3, 'WEB-00090-2' => 2],
            array_map('intval', $this->connection->fetchAllKeyValue('SELECT subscriptionNumber, orderItem FROM tl_consho_subscription')),
        );
    }

    public function testDieBedingungenSindEingefroren(): void
    {
        $this->recorder()->record(90);

        // Die Regel ändert sich nach dem Kauf — das Abo nicht (A9).
        $this->connection->update('tl_consho_subscription_rule', ['title' => 'Neu', 'noticeDays' => 99, 'pauseMonths' => 6], ['id' => 1]);

        $monthly = $this->subscription(2);

        self::assertSame(1, (int) $monthly['rule']);
        self::assertSame('Monatsabo', $monthly['ruleTitle']);
        self::assertSame([1, 'year', 1, 'indefinite', 14, 30, 14], [
            (int) $monthly['termLength'], $monthly['termUnit'], (int) $monthly['billingInterval'], $monthly['renewal'],
            (int) $monthly['noticeDays'], (int) $monthly['reminderDays'], (int) $monthly['graceDays'],
        ]);
        self::assertSame(2, (int) $monthly['pauseMonths'], 'die Pause wie beim Kauf (P102, A9)');
        self::assertSame([0, 0, 0], [(int) $monthly['pausedMonths'], (int) $monthly['pauseFrom'], (int) $monthly['pauseUntil']]);
        self::assertSame(4.1667, (float) $monthly['unitNet']);
        self::assertSame(3, (int) $monthly['taxClass']);
        self::assertSame('EUR', $monthly['currency']);
        self::assertSame(1, (int) $monthly['reverseCharge']);
        self::assertSame(1, (int) $monthly['businessCustomer']);
        self::assertSame(3, (int) $monthly['shop']);
        self::assertSame(7, (int) $monthly['member']);
        self::assertSame('digital', $monthly['kind']);
        self::assertSame('Monatsabo, 12 Monate', $monthly['terms']);
        self::assertSame(['minimum', 'active', null], [$monthly['phase'], $monthly['state'], $monthly['endsAt']]);

        $yearly = $this->subscription(3);

        // Gegenprobe: jede Zeile trägt ihre eigenen Werte.
        self::assertSame(0, (int) $yearly['reverseCharge']);
        self::assertSame('service', $yearly['kind']);
        self::assertSame('Jahresabo', $yearly['ruleTitle']);
    }

    public function testPeriodenVomKaufAus(): void
    {
        $this->recorder()->record(90);

        $monthly = $this->subscription(2);

        // P16a: Start ist der Kaufklick. P17: Anker am 31., geklemmt.
        self::assertSame('2026-01-31 10:15', $this->local((int) $monthly['startedAt']));
        self::assertSame('2026-01-31 10:15', $this->local((int) $monthly['periodFrom']));
        self::assertSame('2026-02-28 10:15', $this->local((int) $monthly['periodUntil']));
        self::assertSame('2026-02-28 10:15', $this->local((int) $monthly['nextBillingAt']));
        self::assertSame('2027-01-31 10:15', $this->local((int) $monthly['minimumEndsAt']));

        // Eine Zahlung: die Periode ist die ganze Laufzeit.
        $yearly = $this->subscription(3);

        self::assertSame('2027-01-31 10:15', $this->local((int) $yearly['periodUntil']));
        self::assertSame('2027-01-31 10:15', $this->local((int) $yearly['nextBillingAt']));
        self::assertSame('2027-01-31 10:15', $this->local((int) $yearly['minimumEndsAt']));
    }

    public function testFehltDieRegelBeimKaufGibtEsKeinAbo(): void
    {
        $this->connection->delete('tl_consho_subscription_rule', ['id' => 2]);

        $this->expectException(SubscriptionRuleGoneAtPurchase::class);

        $this->recorder()->record(90);
    }

    public function testOhneKontoKeinAbo(): void
    {
        $this->connection->update('tl_consho_order', ['member' => 0], ['id' => 90]);

        $this->expectException(SubscriptionWithoutAccount::class);

        $this->recorder()->record(90);
    }

    public function testEinGastkaufOhneAboZeileBrauchtKeinKonto(): void
    {
        $this->order(91, 'WEB-00091', member: 0);
        $this->line(4, 91, sorting: 128, product: 11, rule: 0);

        self::assertSame([], $this->recorder()->record(91));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_subscription'));
    }

    /**
     * Abo-7b (P68, P69): WEB-00080-1 ist zum 20.02. gekündigt; die
     * Verlängerung beginnt dort, nicht beim Kauf am 31.01. — keine doppelte
     * Zahlung, keine Lücke.
     */
    public function testDieVerlaengerungBeginntAmEndeDesAltenAbos(): void
    {
        $oldEnd = $this->at('2026-02-20 08:00');
        $this->old(['endsAt' => $oldEnd]);
        $this->connection->update('tl_consho_order_item', ['renews' => 50], ['id' => 2]);

        $this->recorder()->record(90);

        $new = $this->subscription(2);
        self::assertSame('2026-02-20 08:00', $this->local((int) $new['startedAt']));
        self::assertSame('2026-03-20 08:00', $this->local((int) $new['periodUntil']));
        self::assertSame('2027-02-20 08:00', $this->local((int) $new['minimumEndsAt']));
        self::assertSame(50, (int) $new['predecessor']);

        self::assertSame((int) $new['id'], (int) $this->connection->fetchOne('SELECT renewedBy FROM tl_consho_subscription WHERE id = 50'));
        self::assertSame(0, (int) $this->subscription(3)['predecessor'], 'die andere Zeile verlängert nichts');
    }

    /**
     * P68 mit P55: außerordentlich zum 10.02. gekündigt, die Periode bis 20.02.
     * ist schon bezahlt — die Verlängerung beginnt am 20.02., nicht am 10.02.,
     * sonst zahlte der Kunde zehn Tage doppelt (und die Lizenz würde kürzer).
     */
    public function testDieVerlaengerungBeginntNachDerBezahltenPeriode(): void
    {
        $this->old([
            'endsAt' => $this->at('2026-02-10 00:00'), 'periodUntil' => $this->at('2026-02-20 08:00'),
            'startedAt' => $this->at('2025-12-20 08:00'), 'billingInterval' => 1, 'termLength' => 1, 'termUnit' => 'year',
        ]);
        $this->connection->update('tl_consho_order_item', ['renews' => 50], ['id' => 2]);

        $this->recorder()->record(90);

        self::assertSame('2026-02-20 08:00', $this->local((int) $this->subscription(2)['startedAt']));
    }

    public function testEinBeendetesAboVerlaengertAbDemKauf(): void
    {
        $this->old(['state' => 'ended', 'endsAt' => $this->at('2026-01-10 08:00')]);
        $this->connection->update('tl_consho_order_item', ['renews' => 50], ['id' => 2]);

        $this->recorder()->record(90);

        self::assertSame('2026-01-31 10:15', $this->local((int) $this->subscription(2)['startedAt']));
    }

    public function testEndetOhneKuendigungZaehltDasEndeDerLaufzeit(): void
    {
        $this->old(['renewal' => 'ends', 'minimumEndsAt' => $this->at('2026-03-01 09:00')]);
        $this->connection->update('tl_consho_order_item', ['renews' => 50], ['id' => 2]);

        $this->recorder()->record(90);

        self::assertSame('2026-03-01 09:00', $this->local((int) $this->subscription(2)['startedAt']));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nichtVerlaengerbar(): iterable
    {
        yield 'schon verlängert' => [['endsAt' => 1, 'renewedBy' => 49]];
        yield 'fremdes Konto' => [['endsAt' => 1, 'member' => 8]];
        yield 'anderer Artikel' => [['endsAt' => 1, 'product' => 99]];
        yield 'anderer Shop' => [['endsAt' => 1, 'shop' => 4]];
        yield 'ruht' => [['endsAt' => 1, 'state' => 'suspended']];
        yield 'pausiert' => [['endsAt' => 1, 'state' => 'paused']];
        yield 'läuft weiter' => [['renewal' => 'indefinite']];
    }

    /**
     * @dataProvider nichtVerlaengerbar
     *
     * @param array<string, mixed> $values
     */
    public function testNichtVerlaengerbarLaesstDenKaufScheitern(array $values): void
    {
        $this->old($values);
        $this->connection->update('tl_consho_order_item', ['renews' => 50], ['id' => 2]);

        $this->expectException(SubscriptionRenewalRefused::class);

        $this->recorder()->record(90);
    }

    /**
     * WEB-00080-1: Konto 7, Artikel 12 (wie Zeile 2), Shop 3, Monatsabo.
     *
     * @param array<string, mixed> $values
     */
    private function old(array $values): void
    {
        $this->connection->insert('tl_consho_subscription', [
            'id' => 50, 'subscriptionNumber' => 'WEB-00080-1', 'member' => 7, 'product' => 12, 'shop' => 3,
            'renewal' => 'indefinite', 'state' => 'active', 'minimumEndsAt' => $this->at('2026-06-01 00:00'),
            ...$values,
        ]);
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }

    private function recorder(): SubscriptionRecorder
    {
        return new SubscriptionRecorder($this->connection, new SubscriptionRules($this->connection), new SubscriptionCalendar(self::ZONE));
    }

    private function order(int $id, string $number, int $member): void
    {
        $this->connection->insert('tl_consho_order', [
            'id' => $id, 'orderNumber' => $number, 'shop' => 3, 'member' => $member, 'currency' => 'EUR',
            'businessCustomer' => 1, 'createdAt' => $this->purchase,
        ]);
    }

    private function line(
        int $id,
        int $order,
        int $sorting,
        int $product,
        int $rule,
        string $quantity = '1.0000',
        string $unitPrice = '10.0000',
        string $terms = '',
        string $priceSource = 'list',
        string $kind = 'digital',
    ): void {
        $this->connection->insert('tl_consho_order_item', [
            'id' => $id, 'pid' => $order, 'sorting' => $sorting, 'lineType' => 'product', 'product' => $product,
            'quantity' => $quantity, 'unitPrice' => $unitPrice, 'taxClass' => 3, 'priceSource' => $priceSource,
            'subscriptionRule' => $rule, 'subscriptionTerms' => $terms, 'kind' => $kind,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(int $orderItem): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE orderItem = ?', [$orderItem]);
        self::assertIsArray($row);

        return $row;
    }

    private function local(int $timestamp): string
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d H:i');
    }
}

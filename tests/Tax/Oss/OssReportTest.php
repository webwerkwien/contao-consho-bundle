<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Tax\Oss;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\TaxReportBackendController;
use Webwerkwien\ContaoConshoBundle\Tax\Oss\OssEntry;
use Webwerkwien\ContaoConshoBundle\Tax\Oss\OssLedger;
use Webwerkwien\ContaoConshoBundle\Tax\Oss\OssReport;
use Webwerkwien\ContaoConshoBundle\Tax\Oss\OssThresholdIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block R (O2, R5–R7, R14): what the threshold counts, what the return
 * contains, and when a sale falls into which quarter.
 *
 * Shop 1 (AT, ATU12345678) and shop 2 (AT, the same VAT ID written
 * differently) are one business; shop 3 has no VAT ID and stands alone.
 */
class OssReportTest extends TestCase
{
    private Connection $connection;

    private int $orders = 0;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_shop', 'tl_consho_order', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_document', 'tl_consho_withdrawal'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'tstamp' => 1, 'title' => 'Wien', 'country' => 'AT', 'vatId' => 'ATU12345678', 'taxAtDestination' => 1]);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'tstamp' => 1, 'title' => 'Berlin', 'country' => 'AT', 'vatId' => 'atu 1234 5678', 'taxAtDestination' => 1]);
        $this->connection->insert('tl_consho_shop', ['id' => 3, 'tstamp' => 1, 'title' => 'Klein', 'country' => 'AT', 'vatId' => '', 'taxAtDestination' => 0]);
    }

    public function testShopsMitDerselbenUidSindEinUnternehmen(): void
    {
        $businesses = $this->report()->businesses();

        self::assertSame(['ATU12345678', 'shop-3'], array_map(static fn ($b): string => $b->key, $businesses));
        self::assertSame([1, 2], $businesses[0]->shopIds());
        self::assertSame('Klein', $businesses[1]->label());
        self::assertTrue($businesses[0]->taxesAtDestination());
    }

    /**
     * R14: Prepayment in March, shipping in April — the sale belongs in the
     * first quarter (Art. 65). "On account": shipping in April, payment in May
     * — in the second (Art. 63).
     */
    public function testDasQuartalIstDasFruehereVonZahlungUndLieferung(): void
    {
        $this->order(1, paidAt: $this->at('2026-03-30'), deliveredAt: $this->at('2026-04-02'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $this->order(1, paidAt: $this->at('2026-05-10'), deliveredAt: $this->at('2026-04-20'), items: [$this->goods('FR', 'FR', '20', '50.00')]);
        // Neither paid nor delivered: no sale yet.
        $this->order(1, items: [$this->goods('IT', 'IT', '22', '70.00')]);

        $q1 = $this->report()->quarter($this->business(), 2026, 1);
        $q2 = $this->report()->quarter($this->business(), 2026, 2);

        self::assertSame([['country' => 'DE', 'rate' => '19.0000', 'currency' => 'EUR', 'net' => '100.00', 'tax' => '19.00']], $q1['a']);
        self::assertSame([['country' => 'FR', 'rate' => '20.0000', 'currency' => 'EUR', 'net' => '50.00', 'tax' => '10.00']], $q2['a']);
        self::assertSame([], $q1['b']);

        $entry = $q1['entries'][0];
        self::assertSame([$this->at('2026-03-30'), $this->at('2026-03-30'), $this->at('2026-04-02')], [$entry->supplyAt, $entry->paidAt, $entry->deliveredAt]);
    }

    /**
     * O2: What counts and what does not. The return only contains what was
     * taxed in the customer's country.
     */
    public function testWasZaehltUndWasGemeldetWird(): void
    {
        $paid = $this->at('2026-02-01');

        $this->order(1, paidAt: $paid, items: [
            $this->goods('DE', 'DE', '19', '100.00'),
            // Shipping follows the goods.
            ['lineType' => 'shipping', 'kind' => '', 'taxCountry' => 'DE', 'destinationCountry' => 'DE', 'taxRate' => '19.0000', 'net' => '5.00', 'priceSource' => 'list'],
            // Service: in the shop's country, does not count.
            $this->goods('AT', 'DE', '20', '40.00', kind: 'service'),
        ]);
        // Switch off: taxed in the shop's country — counts, is not reported.
        $this->order(1, paidAt: $paid, items: [$this->goods('AT', 'DE', '20', '30.00')]);
        // Reverse charge, not taxable, domestic, third country: none of these.
        $this->order(1, paidAt: $paid, items: [$this->goods('DE', 'DE', '0', '200.00', source: 'reverseCharge')]);
        $this->order(1, paidAt: $paid, items: [$this->goods('', 'DE', '0', '25.00', kind: 'voucher', source: 'notTaxable')]);
        $this->order(1, paidAt: $paid, items: [$this->goods('AT', 'AT', '20', '300.00')]);
        $this->order(1, paidAt: $paid, items: [$this->goods('CH', 'CH', '0', '400.00')]);
        // Shop 2, same VAT ID: counts too.
        $this->order(2, paidAt: $paid, items: [$this->goods('NL', 'NL', '21', '10.00')]);

        self::assertSame(['EUR' => '145.00'], $this->report()->threshold($this->business(), 2026));

        $a = $this->report()->quarter($this->business(), 2026, 1)['a'];
        self::assertSame(['DE:19.0000:105.00', 'NL:21.0000:10.00'], array_map(static fn (array $row): string => $row['country'].':'.$row['rate'].':'.$row['net'], $a));
    }

    /**
     * Art. 369g Abs. 4: A credit note in the third quarter about a sale of the
     * first corrects the first — part B, not part A.
     */
    public function testEineSpaetereGutschriftIstEineBerichtigungDesUrsprungsquartals(): void
    {
        $order = $this->order(1, paidAt: $this->at('2026-02-01'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $this->creditNote($order, $this->at('2026-08-15'), [
            ['lineType' => 'product', 'kind' => 'physical', 'taxCountry' => 'DE', 'taxRate' => '19.0000', 'taxAmount' => '-3.8000', 'lineTotal' => '-23.8000', 'priceSource' => 'list', 'productName' => 'Tee'],
        ]);

        $q3 = $this->report()->quarter($this->business(), 2026, 3);

        self::assertSame([], $q3['a']);
        self::assertSame([['country' => 'DE', 'period' => '2026-Q1', 'currency' => 'EUR', 'net' => '-20.00', 'tax' => '-3.80']], $q3['b']);
        self::assertSame(['EUR' => '80.00'], $this->report()->threshold($this->business(), 2026));
    }

    /**
     * Cancellation after prepayment in the same quarter: sale and correction
     * cancel out in part A. A cancellation before any payment is not a sale.
     */
    public function testEinStornoNimmtDenUmsatzZurueck(): void
    {
        $this->order(1, paidAt: $this->at('2026-04-02'), cancelledAt: $this->at('2026-04-05'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $this->order(1, cancelledAt: $this->at('2026-04-05'), items: [$this->goods('DE', 'DE', '19', '60.00')]);

        $q2 = $this->report()->quarter($this->business(), 2026, 2);

        self::assertSame([['country' => 'DE', 'rate' => '19.0000', 'currency' => 'EUR', 'net' => '0.00', 'tax' => '0.00']], $q2['a']);
        self::assertSame([false, true], array_map(static fn (OssEntry $e): bool => $e->correction, $q2['entries']));
        self::assertSame([], $this->report()->threshold($this->business(), 2025));
    }

    /**
     * R5: Only euro counts against the threshold; another currency stands
     * beside it, not converted.
     */
    public function testFremdwaehrungZaehltNichtGegenDieSchwelle(): void
    {
        $this->order(1, paidAt: $this->at('2026-02-01'), items: [$this->goods('DE', 'DE', '19', '8000.00')]);
        $this->order(1, paidAt: $this->at('2026-02-01'), currency: 'SEK', items: [$this->goods('SE', 'SE', '25', '50000.00')]);

        $sums = $this->report()->threshold($this->business(), 2026);

        self::assertSame(['EUR' => '8000.00', 'SEK' => '50000.00'], $sums);
        self::assertSame(80, OssReport::percentOf($sums));
    }

    /**
     * R6: Notice from 80 % and from 100 %, only as long as the switch is off,
     * for the previous year too.
     */
    public function testDerBefundMeldetAb80UndAb100Prozent(): void
    {
        $this->order(3, paidAt: $this->at('2026-02-01'), items: [$this->goods('AT', 'DE', '20', '7999.99')]);
        self::assertSame([], $this->findings());

        $this->order(3, paidAt: $this->at('2026-02-02'), items: [$this->goods('AT', 'DE', '20', '0.01')]);
        self::assertSame([[ConfigurationFinding::OssThresholdNear, ['business' => 'Klein', 'year' => '2026']]], $this->findings());

        $this->order(3, paidAt: $this->at('2025-06-01'), items: [$this->goods('AT', 'FR', '20', '10000.00')]);
        self::assertSame([[ConfigurationFinding::OssThresholdReached, ['business' => 'Klein', 'year' => '2025']]], $this->findings(), 'das höhere Jahr reicht');

        // Shops 1 and 2 already tax in the country of destination: no finding.
        $this->order(1, paidAt: $this->at('2026-02-01'), items: [$this->goods('DE', 'DE', '19', '20000.00')]);
        self::assertCount(1, $this->findings());
    }

    /**
     * R: a full withdrawal issues a CANCELLATION, not a credit note — it
     * corrects too. The cancellation of a reissue (change of payment method),
     * on the other hand, does not: no withdrawal names it.
     */
    public function testDerVolleWiderrufBerichtigtDerStornoEinerNeuausstellungNicht(): void
    {
        $withdrawn = $this->order(1, paidAt: $this->at('2026-02-01'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $this->creditNote($withdrawn, $this->at('2026-03-10'), $this->wholeTea(), 'cancellation');

        $reissued = $this->order(1, paidAt: $this->at('2026-02-01'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $this->creditNote($reissued, $this->at('2026-03-10'), $this->wholeTea(), 'cancellation', withdrawal: false);

        $q1 = $this->report()->quarter($this->business(), 2026, 1);

        self::assertSame([['country' => 'DE', 'rate' => '19.0000', 'currency' => 'EUR', 'net' => '100.00', 'tax' => '19.00']], $q1['a']);
        self::assertSame(['EUR' => '100.00'], $this->report()->threshold($this->business(), 2026));
    }

    /**
     * R: partial withdrawal, then the status "cancelled" — the cancellation
     * only takes back what had not been taken back yet.
     */
    public function testNachDerGutschriftNimmtDerStornoNurDenRest(): void
    {
        $order = $this->order(1, paidAt: $this->at('2026-02-01'), cancelledAt: $this->at('2026-03-20'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $this->creditNote($order, $this->at('2026-03-10'), [
            ['lineType' => 'product', 'kind' => 'physical', 'taxCountry' => 'DE', 'taxRate' => '19.0000', 'taxAmount' => '-3.8000', 'lineTotal' => '-23.8000', 'priceSource' => 'list', 'productName' => 'Tee'],
        ]);

        // 100 − 20 (credit note) − 80 (rest at the cancellation) — before, −100 − 20.
        self::assertSame(['EUR' => '0.00'], $this->report()->threshold($this->business(), 2026));
        self::assertSame([['country' => 'DE', 'rate' => '19.0000', 'currency' => 'EUR', 'net' => '0.00', 'tax' => '0.00']], $this->report()->quarter($this->business(), 2026, 1)['a']);
    }

    /**
     * R: first "cancelled", then the withdrawal refunded — the cancellation
     * has already taken everything back, the credit note takes nothing more.
     * At the same moment (B5, limit): the credit note counts, the
     * cancellation leaves it out — in sum everything once.
     */
    public function testNachDemStornoNimmtDieGutschriftNichtsMehr(): void
    {
        $tea = [['lineType' => 'product', 'kind' => 'physical', 'taxCountry' => 'DE', 'taxRate' => '19.0000', 'taxAmount' => '-3.8000', 'lineTotal' => '-23.8000', 'priceSource' => 'list', 'productName' => 'Tee']];

        $after = $this->order(1, paidAt: $this->at('2026-02-01'), cancelledAt: $this->at('2026-03-01'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $this->creditNote($after, $this->at('2026-03-15'), $tea);
        self::assertSame(['EUR' => '0.00'], $this->report()->threshold($this->business(), 2026), 'vorher −20,00');

        $same = $this->order(1, paidAt: $this->at('2026-02-01'), cancelledAt: $this->at('2026-03-20'), items: [$this->goods('DE', 'DE', '19', '50.00')]);
        $this->creditNote($same, $this->at('2026-03-20'), $tea);
        self::assertSame(['EUR' => '0.00'], $this->report()->threshold($this->business(), 2026), 'am selben Zeitpunkt einmal');
    }

    /**
     * R: a credit note before the sale (partial withdrawal before it was
     * delivered and paid in full) belongs in the quarter of the sale.
     */
    public function testEineGutschriftVorDemUmsatzGehoertInDessenQuartal(): void
    {
        $order = $this->order(1, deliveredAt: $this->at('2026-04-10'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $this->creditNote($order, $this->at('2026-03-25'), [
            ['lineType' => 'product', 'kind' => 'physical', 'taxCountry' => 'DE', 'taxRate' => '19.0000', 'taxAmount' => '-3.8000', 'lineTotal' => '-23.8000', 'priceSource' => 'list', 'productName' => 'Tee'],
        ]);

        self::assertSame([], $this->report()->quarter($this->business(), 2026, 1)['a']);
        self::assertSame([['country' => 'DE', 'rate' => '19.0000', 'currency' => 'EUR', 'net' => '80.00', 'tax' => '15.20']], $this->report()->quarter($this->business(), 2026, 2)['a']);
    }

    /**
     * R: for each exclusion a case in which only that exclusion applies.
     */
    public function testJederAusschlussGreiftFuerSich(): void
    {
        // Cancelled before it was ever a sale — and paid after all: no correction.
        $this->order(1, paidAt: $this->at('2026-02-10'), cancelledAt: $this->at('2026-02-05'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $q1 = $this->report()->quarter($this->business(), 2026, 1);
        self::assertSame([false], array_map(static fn (OssEntry $e): bool => $e->correction, $q1['entries']));

        // A shop outside the EU knows no delivery threshold.
        $this->connection->insert('tl_consho_shop', ['id' => 4, 'tstamp' => 1, 'title' => 'Zürich', 'country' => 'CH', 'vatId' => 'CHE123456789']);
        $this->order(4, paidAt: $this->at('2026-02-01'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $zurich = $this->report()->business('CHE123456789');
        self::assertNotNull($zurich);
        self::assertSame([], $this->report()->threshold($zurich, 2026));
    }
    /**
     * R: the start page counts at most once per hour — a new sale only
     * changes the finding when the entry expires.
     */
    public function testDerBefundZaehltAusDemCache(): void
    {
        $cache = new \Symfony\Component\Cache\Adapter\ArrayAdapter();
        $source = new OssThresholdIssueSource($this->report(), $cache, $this->at('2026-09-01'));

        $this->order(3, paidAt: $this->at('2026-02-01'), items: [$this->goods('AT', 'DE', '20', '8000.00')]);
        self::assertSame(ConfigurationFinding::OssThresholdNear, $this->finding($source));

        $this->order(3, paidAt: $this->at('2026-02-02'), items: [$this->goods('AT', 'DE', '20', '3000.00')]);
        self::assertSame(ConfigurationFinding::OssThresholdNear, $this->finding($source), 'noch aus dem Cache');

        $cache->clear();
        self::assertSame(ConfigurationFinding::OssThresholdReached, $this->finding($source));
    }

    /**
     * R: the hour really does expire.
     */
    public function testDerCacheEintragLaeuftNachEinerStundeAb(): void
    {
        // Starts now: the item computes its expiry from the real time.
        $clock = new \Symfony\Component\Clock\MockClock();
        $source = new OssThresholdIssueSource($this->report(), new \Symfony\Component\Cache\Adapter\ArrayAdapter(clock: $clock), $this->at('2026-09-01'));

        $this->order(3, paidAt: $this->at('2026-02-01'), items: [$this->goods('AT', 'DE', '20', '8000.00')]);
        self::assertSame(ConfigurationFinding::OssThresholdNear, $this->finding($source));

        $this->order(3, paidAt: $this->at('2026-02-02'), items: [$this->goods('AT', 'DE', '20', '3000.00')]);
        $clock->sleep(3599);
        self::assertSame(ConfigurationFinding::OssThresholdNear, $this->finding($source));

        $clock->sleep(2);
        self::assertSame(ConfigurationFinding::OssThresholdReached, $this->finding($source));
    }

    public function testDieCsvSchreibtDezimalkommaUndSchuetztText(): void
    {
        $order = $this->order(1, paidAt: $this->at('2026-02-01'), items: [$this->goods('DE', 'DE', '19', '100.00', name: '=HYPERLINK("x")')]);
        $this->creditNote($order, $this->at('2026-08-15'), [
            ['lineType' => 'product', 'kind' => 'physical', 'taxCountry' => 'DE', 'taxRate' => '19.0000', 'taxAmount' => '-3.8000', 'lineTotal' => '-23.8000', 'priceSource' => 'list', 'productName' => 'Tee'],
        ]);
        $q1 = $this->report()->quarter($this->business(), 2026, 1);
        $q3 = $this->report()->quarter($this->business(), 2026, 3);

        $summary = TaxReportBackendController::csv(TaxReportBackendController::summaryRows($q3, []));
        self::assertStringStartsWith("\u{FEFF}Teil;Mitgliedstaat;Satz;", $summary);
        self::assertStringContainsString("\nB;DE;;2026-Q1;EUR;-20,00;-3,80\n", $summary, 'eine negative Zahl bleibt eine Zahl');

        $records = TaxReportBackendController::csv(TaxReportBackendController::recordRows($q1['entries'], []));
        self::assertStringContainsString(";2026-02-01;2026-02-01;2026-02-01;;DE;DE;19,0000;EUR;100,0000;19,0000;\"'=HYPERLINK(\"\"x\"\")\"\n", $records);
    }

    /**
     * @param list<array<string, string>> $items
     */
    private function order(int $shop, int|null $paidAt = null, int|null $deliveredAt = null, int|null $cancelledAt = null, string $currency = 'EUR', array $items = []): int
    {
        $id = ++$this->orders;
        $this->connection->insert('tl_consho_order', [
            'id' => $id, 'tstamp' => 1, 'shop' => $shop, 'orderNumber' => \sprintf('WEB-%05d', $id), 'currency' => $currency,
            'paymentSettledAt' => $paidAt, 'cancelledAt' => $cancelledAt,
        ]);

        if (null !== $deliveredAt) {
            $this->connection->insert('tl_consho_delivery', ['pid' => $id, 'tstamp' => 1, 'shippedAt' => $deliveredAt, 'reportedAt' => $deliveredAt]);
        }

        foreach ($items as $item) {
            $tax = bcdiv(bcmul($item['net'], $item['taxRate'], 6), '100', 4);
            $this->connection->insert('tl_consho_order_item', [
                'pid' => $id, 'tstamp' => 1, 'lineType' => $item['lineType'], 'kind' => $item['kind'], 'productName' => $item['name'] ?? 'Tee',
                'taxCountry' => $item['taxCountry'], 'destinationCountry' => $item['destinationCountry'], 'taxRate' => $item['taxRate'],
                'taxAmount' => $tax, 'lineTotal' => bcadd($item['net'], $tax, 4), 'priceSource' => $item['priceSource'],
            ]);
        }

        return $id;
    }

    /**
     * @return array<string, string>
     */
    private function goods(string $taxCountry, string $destination, string $rate, string $net, string $kind = 'physical', string $source = 'list', string $name = 'Tee'): array
    {
        return ['lineType' => 'product', 'kind' => $kind, 'taxCountry' => $taxCountry, 'destinationCountry' => $destination, 'taxRate' => $rate.'.0000', 'net' => $net, 'priceSource' => $source, 'name' => $name];
    }

    /**
     * @param list<array<string, string>> $lines
     */
    private function creditNote(int $orderId, int $issuedAt, array $lines, string $type = 'creditNote', bool $withdrawal = true): void
    {
        $this->connection->insert('tl_consho_document', [
            'pid' => $orderId, 'tstamp' => 1, 'shop' => 1, 'type' => $type, 'documentNumber' => 'GS-0000'.$orderId, 'issuedAt' => $issuedAt,
            'payload' => json_encode(['items' => $lines], JSON_THROW_ON_ERROR),
        ]);

        // The withdrawal that names it as its refund document (WithdrawalProcessing).
        if ($withdrawal) {
            $this->connection->insert('tl_consho_withdrawal', ['pid' => $orderId, 'tstamp' => 1, 'number' => 'W'.$orderId, 'refundDocument' => (int) $this->connection->lastInsertId()]);
        }
    }

    /**
     * @return list<array<string, string>> the order's 100,00 € tea at 19 % DE, taken back whole
     */
    private function wholeTea(): array
    {
        return [['lineType' => 'product', 'kind' => 'physical', 'taxCountry' => 'DE', 'taxRate' => '19.0000', 'taxAmount' => '-19.0000', 'lineTotal' => '-119.0000', 'priceSource' => 'list', 'productName' => 'Tee']];
    }

    /**
     * @return list<array{ConfigurationFinding, array<string, string>}>
     *
     * @phpstan-impure
     */
    private function findings(): array
    {
        return array_map(
            static fn ($issue): array => [$issue->finding, $issue->context],
            (new OssThresholdIssueSource($this->report(), now: $this->at('2026-09-01')))->issues(),
        );
    }

    /**
     * The first finding, asked anew on every call: the cache in between is
     * what these tests are about, so no two calls may be taken for one.
     */
    private function finding(OssThresholdIssueSource $source): ?ConfigurationFinding
    {
        return $source->issues()[0]->finding ?? null;
    }

    private function report(): OssReport
    {
        return new OssReport($this->connection, new OssLedger($this->connection));
    }

    private function business(): \Webwerkwien\ContaoConshoBundle\Tax\Oss\OssBusiness
    {
        $business = $this->report()->business('ATU12345678');
        self::assertNotNull($business);

        return $business;
    }

    private function at(string $date): int
    {
        return (int) strtotime($date.' 12:00');
    }
}

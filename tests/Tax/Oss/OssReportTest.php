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
 * Block R (O2, R5–R7, R14): was die Schwelle zählt, was die Meldung enthält,
 * und wann ein Umsatz in welches Quartal fällt.
 *
 * Shop 1 (AT, ATU12345678) und Shop 2 (AT, dieselbe UID anders geschrieben)
 * sind ein Unternehmen; Shop 3 hat keine UID und steht für sich.
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
     * R14: Vorkasse im März, Versand im April — der Umsatz gehört ins erste
     * Quartal (Art. 65). „Auf Rechnung": Versand im April, Zahlung im Mai —
     * ins zweite (Art. 63).
     */
    public function testDasQuartalIstDasFruehereVonZahlungUndLieferung(): void
    {
        $this->order(1, paidAt: $this->at('2026-03-30'), deliveredAt: $this->at('2026-04-02'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $this->order(1, paidAt: $this->at('2026-05-10'), deliveredAt: $this->at('2026-04-20'), items: [$this->goods('FR', 'FR', '20', '50.00')]);
        // Weder bezahlt noch geliefert: noch kein Umsatz.
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
     * O2: Was zählt und was nicht. Die Meldung enthält nur, was im Land des
     * Kunden versteuert wurde.
     */
    public function testWasZaehltUndWasGemeldetWird(): void
    {
        $paid = $this->at('2026-02-01');

        $this->order(1, paidAt: $paid, items: [
            $this->goods('DE', 'DE', '19', '100.00'),
            // Versand folgt der Ware.
            ['lineType' => 'shipping', 'kind' => '', 'taxCountry' => 'DE', 'destinationCountry' => 'DE', 'taxRate' => '19.0000', 'net' => '5.00', 'priceSource' => 'list'],
            // Dienstleistung: im Shopland, zählt nicht.
            $this->goods('AT', 'DE', '20', '40.00', kind: 'service'),
        ]);
        // Schalter aus: im Shopland versteuert — zählt, wird nicht gemeldet.
        $this->order(1, paidAt: $paid, items: [$this->goods('AT', 'DE', '20', '30.00')]);
        // Reverse Charge, nicht steuerbar, Inland, Drittland: nichts davon.
        $this->order(1, paidAt: $paid, items: [$this->goods('DE', 'DE', '0', '200.00', source: 'reverseCharge')]);
        $this->order(1, paidAt: $paid, items: [$this->goods('', 'DE', '0', '25.00', kind: 'voucher', source: 'notTaxable')]);
        $this->order(1, paidAt: $paid, items: [$this->goods('AT', 'AT', '20', '300.00')]);
        $this->order(1, paidAt: $paid, items: [$this->goods('CH', 'CH', '0', '400.00')]);
        // Shop 2, gleiche UID: zählt mit.
        $this->order(2, paidAt: $paid, items: [$this->goods('NL', 'NL', '21', '10.00')]);

        self::assertSame(['EUR' => '145.00'], $this->report()->threshold($this->business(), 2026));

        $a = $this->report()->quarter($this->business(), 2026, 1)['a'];
        self::assertSame(['DE:19.0000:105.00', 'NL:21.0000:10.00'], array_map(static fn (array $row): string => $row['country'].':'.$row['rate'].':'.$row['net'], $a));
    }

    /**
     * Art. 369g Abs. 4: Eine Gutschrift im dritten Quartal über einen Umsatz
     * des ersten berichtigt das erste — Teil B, nicht Teil A.
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
     * Storno nach der Vorkasse im selben Quartal: Umsatz und Berichtigung
     * heben sich in Teil A auf. Ein Storno vor jeder Zahlung ist kein Umsatz.
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
     * R5: Nur Euro zählt gegen die Schwelle; eine andere Währung steht
     * daneben, nicht umgerechnet.
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
     * R6: Hinweis ab 80 % und ab 100 %, nur solange der Schalter aus ist,
     * auch für das Vorjahr.
     */
    public function testDerBefundMeldetAb80UndAb100Prozent(): void
    {
        $this->order(3, paidAt: $this->at('2026-02-01'), items: [$this->goods('AT', 'DE', '20', '7999.99')]);
        self::assertSame([], $this->findings());

        $this->order(3, paidAt: $this->at('2026-02-02'), items: [$this->goods('AT', 'DE', '20', '0.01')]);
        self::assertSame([[ConfigurationFinding::OssThresholdNear, ['business' => 'Klein', 'year' => '2026']]], $this->findings());

        $this->order(3, paidAt: $this->at('2025-06-01'), items: [$this->goods('AT', 'FR', '20', '10000.00')]);
        self::assertSame([[ConfigurationFinding::OssThresholdReached, ['business' => 'Klein', 'year' => '2025']]], $this->findings(), 'das höhere Jahr reicht');

        // Shop 1 und 2 versteuern schon im Bestimmungsland: kein Befund.
        $this->order(1, paidAt: $this->at('2026-02-01'), items: [$this->goods('DE', 'DE', '19', '20000.00')]);
        self::assertCount(1, $this->findings());
    }

    /**
     * Review R, F1: ein voller Widerruf stellt einen STORNO aus, keine
     * Gutschrift — auch er berichtigt. Der Storno einer Neuausstellung
     * (Zahlungsartwechsel) dagegen nicht: ihn nennt kein Widerruf.
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
     * Review R, F2: Teilwiderruf, danach Status „storniert" — der Storno
     * nimmt nur zurück, was noch nicht zurück war.
     */
    public function testNachDerGutschriftNimmtDerStornoNurDenRest(): void
    {
        $order = $this->order(1, paidAt: $this->at('2026-02-01'), cancelledAt: $this->at('2026-03-20'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $this->creditNote($order, $this->at('2026-03-10'), [
            ['lineType' => 'product', 'kind' => 'physical', 'taxCountry' => 'DE', 'taxRate' => '19.0000', 'taxAmount' => '-3.8000', 'lineTotal' => '-23.8000', 'priceSource' => 'list', 'productName' => 'Tee'],
        ]);

        // 100 − 20 (Gutschrift) − 80 (Rest beim Storno) — vorher −100 − 20.
        self::assertSame(['EUR' => '0.00'], $this->report()->threshold($this->business(), 2026));
        self::assertSame([['country' => 'DE', 'rate' => '19.0000', 'currency' => 'EUR', 'net' => '0.00', 'tax' => '0.00']], $this->report()->quarter($this->business(), 2026, 1)['a']);
    }

    /**
     * Zweites Review R, B1: erst „storniert", danach der Widerruf erstattet —
     * der Storno hat schon alles zurückgenommen, die Gutschrift nimmt nichts
     * mehr. Am selben Zeitpunkt (B5, Grenze): die Gutschrift zählt, der
     * Storno lässt sie aus — in Summe einmal alles.
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
     * Review R, F11: eine Gutschrift vor dem Umsatz (Teilwiderruf, bevor
     * geliefert und ganz bezahlt war) gehört in das Quartal des Umsatzes.
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
     * Review R, F9 (Regel 43): je Ausschluss ein Fall, in dem nur er greift.
     */
    public function testJederAusschlussGreiftFuerSich(): void
    {
        // Storniert, bevor es je ein Umsatz war — und danach doch bezahlt: keine Berichtigung.
        $this->order(1, paidAt: $this->at('2026-02-10'), cancelledAt: $this->at('2026-02-05'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $q1 = $this->report()->quarter($this->business(), 2026, 1);
        self::assertSame([false], array_map(static fn (OssEntry $e): bool => $e->correction, $q1['entries']));

        // Ein Shop außerhalb der EU kennt keine Lieferschwelle.
        $this->connection->insert('tl_consho_shop', ['id' => 4, 'tstamp' => 1, 'title' => 'Zürich', 'country' => 'CH', 'vatId' => 'CHE123456789']);
        $this->order(4, paidAt: $this->at('2026-02-01'), items: [$this->goods('DE', 'DE', '19', '100.00')]);
        $zurich = $this->report()->business('CHE123456789');
        self::assertNotNull($zurich);
        self::assertSame([], $this->report()->threshold($zurich, 2026));
    }
    /**
     * Review R, F8: die Startseite zählt höchstens einmal je Stunde — ein
     * neuer Verkauf ändert den Befund erst, wenn der Eintrag abläuft.
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
     * Zweites Review R, B5: die Stunde läuft wirklich ab.
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

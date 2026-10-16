<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusIssueSource;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * D-7, M3 und M4 — mit dem echten OrderBalanceReader: Die SQL-Vorauswahl darf
 * nur eingrenzen, entscheiden muss der Saldo.
 */
final class OrderStatusIssueSourceTest extends TestCase
{
    private const NEW = 1;
    private const PAID = 2;
    private const DONE = 3;
    private const SHIPPED = 4;
    private const CANCELLED = 5;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        DcaSchema::create($this->connection, 'tl_consho_order');
        // From the DCA (acceptance WG-7, Prüfungsnotiz Punkt 56), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien']);
        $this->connection->insert('tl_consho_order_status', ['id' => self::NEW, 'pid' => 1, 'title' => 'Neu']);
        $this->connection->insert('tl_consho_order_status', ['id' => self::PAID, 'pid' => 1, 'title' => 'Bezahlt', 'isPaid' => 1]);
        $this->connection->insert('tl_consho_order_status', ['id' => self::DONE, 'pid' => 1, 'title' => 'Abgeschlossen', 'isPaid' => 1, 'isCompleted' => 1]);
        $this->connection->insert('tl_consho_order_status', ['id' => self::SHIPPED, 'pid' => 1, 'title' => 'Versandt', 'isShipped' => 1]);
        $this->connection->insert('tl_consho_order_status', ['id' => self::CANCELLED, 'pid' => 1, 'title' => 'Storniert', 'isCancelled' => 1]);
    }

    public function testEineErledigteBestellungIstKeinBefund(): void
    {
        $this->order(1, self::DONE, paid: ['20'], shipped: true);
        $this->order(2, self::PAID, paid: ['20']);
        $this->order(3, self::NEW);

        self::assertSame([], $this->issues());
    }

    public function testWeb00044AbgeschlossenAberUeberzahlt(): void
    {
        $this->order(44, self::DONE, paid: ['25'], shipped: true);

        $issue = $this->only(ConfigurationFinding::StatusContradictsBalance);
        self::assertSame(['shop' => 'Wien', 'count' => '1', 'numbers' => 'WEB-44'], $issue->context);
        self::assertFalse($issue->finding->isWarning(), 'ein Hinweis: der Betreiber entscheidet');
    }

    public function testBezahltUndDanachErstattet(): void
    {
        $this->order(5, self::PAID, paid: ['20', '-20']);

        self::assertSame('WEB-5', $this->only(ConfigurationFinding::StatusContradictsBalance)->context['numbers']);
    }

    public function testVersandtAberNichtGemeldet(): void
    {
        $this->order(6, self::SHIPPED, paid: ['20']);

        self::assertSame('WEB-6', $this->only(ConfigurationFinding::StatusContradictsBalance)->context['numbers']);
    }

    public function testStorniertObwohlVersandt(): void
    {
        $this->order(7, self::CANCELLED, shipped: true);

        self::assertSame('WEB-7', $this->only(ConfigurationFinding::StatusContradictsBalance)->context['numbers']);
    }

    /**
     * Die Vorauswahl hält eine storniert-und-erstattete Bestellung für einen
     * Kandidaten (Zahlungen ≠ Summe); der Saldo sagt: nichts offen.
     */
    public function testDieVorauswahlEntscheidetNicht(): void
    {
        $this->order(8, self::DONE, paid: ['20', '-20'], cancelledAt: 1);

        self::assertSame([], $this->issues());
    }

    public function testFuenfNummernUndDerRest(): void
    {
        for ($i = 11; $i <= 17; ++$i) {
            $this->order($i, self::PAID);
        }

        $issue = $this->only(ConfigurationFinding::StatusContradictsBalance);
        self::assertSame('7', $issue->context['count']);
        self::assertSame('WEB-11, WEB-12, WEB-13, WEB-14, WEB-15, …', $issue->context['numbers']);
    }

    /**
     * M4: nur der stornierende Zielstatus, in jedem der drei Felder. „Nach
     * Zahlung → versandt" ist kein Befund: bei Nachnahme trifft es zu.
     */
    public function testEinStornierenderStatusAlsAutomatischesZiel(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['statusFields']['statusAfterDelivery'] = 'Status nach vollständiger Lieferung';
        $this->connection->update('tl_consho_shop', ['statusAfterPayment' => self::SHIPPED, 'statusAfterDelivery' => self::CANCELLED, 'statusWhenSettled' => self::DONE], ['id' => 1]);

        try {
            $issue = $this->only(ConfigurationFinding::AutomaticStatusCancels);
        } finally {
            unset($GLOBALS['TL_LANG']['CONSHO']['statusFields']['statusAfterDelivery']);
        }

        self::assertSame(['shop' => 'Wien', 'field' => 'Status nach vollständiger Lieferung', 'status' => 'Storniert'], $issue->context);
        self::assertTrue($issue->finding->isWarning());
    }

    /**
     * Fable-Review D-7, S1: „wenn alles erledigt" → storniert kann greifen
     * (OrderStatusAdvancerTest) — dort ist die Einstellung kein Fehler.
     */
    public function testWennAllesErledigtIstDarfStornieren(): void
    {
        $this->connection->update('tl_consho_shop', ['statusWhenSettled' => self::CANCELLED], ['id' => 1]);

        self::assertSame([], $this->issues());
    }

    /**
     * Fable-Review D-7, K2: Der Hinweis gehört zum Shop der Bestellung, auch
     * wenn ihr Status (fälschlich) einem anderen Shop gehört.
     */
    public function testGruppiertNachDemShopDerBestellung(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Graz']);
        $this->connection->insert('tl_consho_order_status', ['id' => 20, 'pid' => 2, 'title' => 'Bezahlt (Graz)', 'isPaid' => 1]);
        $this->order(21, 20);

        self::assertSame('Wien', $this->only(ConfigurationFinding::StatusContradictsBalance)->context['shop']);
    }

    /**
     * Fable-Review D-7, S2: Contao fragt die Hinweise auf jeder Backend-Seite
     * ab. Die Liste kommt für ein paar Minuten aus dem Cache.
     */
    public function testDasErgebnisKommtAusDemCache(): void
    {
        $cache = new ArrayAdapter();
        $this->order(44, self::DONE, paid: ['25'], shipped: true);
        $source = new OrderStatusIssueSource($this->connection, $this->reader(), $cache);

        $before = $source->issues();
        self::assertCount(1, $before);

        $this->connection->insert('tl_consho_payment', ['pid' => 44, 'amount' => '-5']);

        $after = $source->issues();
        self::assertSame($before[0]->context, $after[0]->context ?? null, 'noch aus dem Cache');
        self::assertCount(0, (new OrderStatusIssueSource($this->connection, $this->reader()))->issues(), 'ohne Cache frisch gelesen');
    }

    public function testEinStatusEinesAnderenShopsZaehltNicht(): void
    {
        $this->connection->insert('tl_consho_order_status', ['id' => 9, 'pid' => 2, 'title' => 'Fremd storniert', 'isCancelled' => 1]);
        $this->connection->update('tl_consho_shop', ['statusWhenSettled' => 9], ['id' => 1]);

        self::assertSame([], $this->issues());
    }

    /**
     * @param list<string> $paid
     */
    private function order(int $id, int $status, array $paid = [], bool $shipped = false, int|null $cancelledAt = null): void
    {
        $this->connection->insert('tl_consho_order', ['id' => $id, 'shop' => 1, 'orderNumber' => 'WEB-'.$id, 'grandTotal' => '20.0000', 'orderStatus' => $status, 'cancelledAt' => $cancelledAt]);
        $this->connection->insert('tl_consho_order_item', ['id' => $id * 10, 'pid' => $id, 'quantity' => '1.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => $id * 10 + 1, 'pid' => $id, 'quantity' => '1.0000', 'lineType' => 'shipping']);

        foreach ($paid as $amount) {
            $this->connection->insert('tl_consho_payment', ['pid' => $id, 'amount' => $amount]);
        }

        if ($shipped) {
            $this->connection->insert('tl_consho_delivery', ['id' => $id, 'pid' => $id, 'reportedAt' => 1]);
            $this->connection->insert('tl_consho_delivery_item', ['pid' => $id, 'orderItem' => $id * 10, 'quantity' => '1.0000']);
        }
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new OrderStatusIssueSource($this->connection, $this->reader()))->issues();
    }

    private function reader(): OrderBalanceReader
    {
        return new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator());
    }

    private function only(ConfigurationFinding $finding): ConfigurationIssue
    {
        $issues = $this->issues();
        self::assertCount(1, $issues);
        self::assertSame($finding, $issues[0]->finding);

        return $issues[0];
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OverdueInvoiceIssueSource;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * E-2, Z5: Eine Rechnung über dem Zahlungsziel und nicht bezahlt ist ein
 * Hinweis auf der Startseite — keine Mahnung, kein Statuswechsel.
 */
class OverdueInvoiceIssueSourceTest extends TestCase
{
    private const NOW = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_order');
        // From the DCA (acceptance WG-7, Prüfungsnotiz Punkt 56), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien']);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Graz']);
    }

    public function testUeberDemZielUndOffenIstEinHinweis(): void
    {
        $this->order(1, dueAt: self::NOW - 1);

        $issues = $this->source()->overdue(self::NOW);

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::InvoiceOverdue, $issues[0]->finding);
        self::assertFalse($issues[0]->finding->isWarning(), 'ein Hinweis, keine Warnung');
        self::assertSame(['shop' => 'Wien', 'count' => '1', 'numbers' => 'WEB-1'], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
    }

    /**
     * „Zahlbar bis" schließt den Tag ein: Bis zur letzten Sekunde ist nichts
     * überfällig.
     */
    public function testAmLetztenTagNochNicht(): void
    {
        $this->order(1, dueAt: self::NOW);

        self::assertSame([], $this->source()->overdue(self::NOW));
    }

    public function testBezahltTeilbezahltStorniertOhneZiel(): void
    {
        $this->order(1, dueAt: self::NOW - 1, paid: ['20']);
        $this->order(2, dueAt: self::NOW - 1, paid: ['5']);
        $this->order(3, dueAt: self::NOW - 1, cancelledAt: self::NOW - 100);
        $this->order(4, dueAt: 0);

        $issues = $this->source()->overdue(self::NOW);

        self::assertCount(1, $issues);
        self::assertSame('WEB-2', $issues[0]->context['numbers'], 'teilbezahlt ist noch offen');
    }

    /**
     * Ein angenommener Widerruf senkt die Forderung. Die blanke Summe sähe
     * „20 offen", der Saldo weiß es besser — deshalb entscheidet er.
     */
    public function testEinWiderrufSenktDieForderung(): void
    {
        $this->order(1, dueAt: self::NOW - 1);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => 1, 'state' => 'accepted', 'number' => 'WEB-1-W1']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 10, 'quantity' => '1.0000', 'acceptedQuantity' => '1.0000']);

        self::assertSame([], $this->source()->overdue(self::NOW));
    }

    public function testJeShopEinHinweisMitHoechstensFuenfNummern(): void
    {
        foreach (range(1, 6) as $id) {
            $this->order($id, dueAt: self::NOW - 1);
        }

        $this->order(7, dueAt: self::NOW - 1, shop: 2);

        $issues = $this->source()->overdue(self::NOW);

        self::assertCount(2, $issues);
        self::assertSame(['shop' => 'Wien', 'count' => '6', 'numbers' => 'WEB-1, WEB-2, WEB-3, WEB-4, WEB-5, …'], $issues[0]->context);
        self::assertSame('Graz', $issues[1]->context['shop']);
    }

    /**
     * @param list<string> $paid
     */
    private function order(int $id, int $dueAt, array $paid = [], int|null $cancelledAt = null, int $shop = 1): void
    {
        $this->connection->insert('tl_consho_order', ['id' => $id, 'shop' => $shop, 'orderNumber' => 'WEB-'.$id, 'grandTotal' => '20.0000', 'cancelledAt' => $cancelledAt, 'paymentDueAt' => $dueAt]);
        $this->connection->insert('tl_consho_order_item', ['id' => $id * 10, 'pid' => $id, 'quantity' => '1.0000', 'lineTotal' => '20.0000']);

        foreach ($paid as $amount) {
            $this->connection->insert('tl_consho_payment', ['pid' => $id, 'amount' => $amount]);
        }
    }

    private function source(): OverdueInvoiceIssueSource
    {
        return new OverdueInvoiceIssueSource($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));
    }
}

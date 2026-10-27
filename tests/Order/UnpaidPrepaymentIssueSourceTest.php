<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\UnpaidPrepaymentIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * E15: unpaid prepayment ties up stock. No automatic cancellation, but a
 * notice after 14 days.
 */
class UnpaidPrepaymentIssueSourceTest extends TestCase
{
    private const NOW = 1_790_000_000;

    private const LIMIT = self::NOW - UnpaidPrepaymentIssueSource::DAYS * 86400;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_shop', 'tl_consho_order', 'tl_consho_payment', 'tl_consho_payment_method', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien']);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Graz']);
        $this->connection->insert('tl_consho_payment_method', ['id' => 5, 'title' => 'Vorkasse', 'type' => 'prepayment']);
        $this->connection->insert('tl_consho_payment_method', ['id' => 6, 'title' => 'Auf Rechnung', 'type' => 'invoice']);
    }

    public function testVorkasseUeberVierzehnTageUnbezahltIstEinHinweis(): void
    {
        $this->order(1, createdAt: self::LIMIT - 1);

        $issues = $this->source()->unpaid(self::NOW);

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::PrepaymentUnpaid, $issues[0]->finding);
        self::assertFalse($issues[0]->finding->isWarning(), 'ein Hinweis, keine Warnung');
        self::assertSame(['shop' => 'Wien', 'days' => '14', 'count' => '1', 'numbers' => 'WEB-1'], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
    }

    public function testGenauAufDerFristNochNicht(): void
    {
        $this->order(1, createdAt: self::LIMIT);

        self::assertSame([], $this->source()->unpaid(self::NOW));
    }

    public function testBezahltStorniertUndAndereZahlungsartenZaehlenNicht(): void
    {
        $this->order(1, createdAt: self::LIMIT - 1, paid: ['20']);
        $this->order(2, createdAt: self::LIMIT - 1, paid: ['5']);
        $this->order(3, createdAt: self::LIMIT - 1, cancelledAt: self::NOW - 100);
        $this->order(4, createdAt: self::LIMIT - 1, method: 6);

        $issues = $this->source()->unpaid(self::NOW);

        self::assertCount(1, $issues);
        self::assertSame('WEB-2', $issues[0]->context['numbers'], 'teilbezahlt ist noch offen; Rechnungen nennt InvoiceOverdue');
    }

    public function testJeShopEinHinweisMitHoechstensFuenfNummern(): void
    {
        foreach (range(1, 6) as $id) {
            $this->order($id, createdAt: self::LIMIT - 1);
        }

        $this->order(7, createdAt: self::LIMIT - 1, shop: 2);

        $issues = $this->source()->unpaid(self::NOW);

        self::assertCount(2, $issues);
        self::assertSame('6', $issues[0]->context['count']);
        self::assertSame('WEB-1, WEB-2, WEB-3, WEB-4, WEB-5, …', $issues[0]->context['numbers']);
        self::assertSame('Graz', $issues[1]->context['shop']);
    }

    /**
     * @param list<string> $paid
     */
    private function order(int $id, int $createdAt, array $paid = [], int|null $cancelledAt = null, int $shop = 1, int $method = 5): void
    {
        $this->connection->insert('tl_consho_order', ['id' => $id, 'shop' => $shop, 'orderNumber' => 'WEB-'.$id, 'grandTotal' => '20.0000', 'cancelledAt' => $cancelledAt, 'createdAt' => $createdAt, 'paymentMethod' => $method]);
        $this->connection->insert('tl_consho_order_item', ['id' => $id * 10, 'pid' => $id, 'quantity' => '1.0000', 'lineTotal' => '20.0000']);

        foreach ($paid as $amount) {
            $this->connection->insert('tl_consho_payment', ['pid' => $id, 'amount' => $amount]);
        }
    }

    private function source(): UnpaidPrepaymentIssueSource
    {
        return new UnpaidPrepaymentIssueSource($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));
    }
}

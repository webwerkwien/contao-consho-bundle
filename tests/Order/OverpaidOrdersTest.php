<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OverpaidOrderIssueSource;
use Webwerkwien\ContaoConshoBundle\Order\OverpaidOrders;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * Block Geldflüsse, 3.4 (G1, G2): an order holding money that has to go back
 * — cancelled and paid, or paid more than it claims.
 */
final class OverpaidOrdersTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_shop', 'tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_provider_event'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien']);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Graz']);
        $this->connection->insert('tl_consho_order_status', ['id' => 9, 'isCancelled' => 1]);
    }

    public function testStorniertUndBezahltIstZuVielBezahlt(): void
    {
        $this->order(1, paid: ['20.0000'], fields: ['cancelledAt' => 100]);
        $this->order(2, paid: ['20.0000'], fields: ['orderStatus' => 9]);
        $this->order(3, fields: ['cancelledAt' => 100]);

        self::assertSame([
            1 => ['shop' => 1, 'number' => 'WEB-1', 'excess' => '20.0000'],
            2 => ['shop' => 1, 'number' => 'WEB-2', 'excess' => '20.0000'],
        ], $this->orders()->all(), 'auch über den Status storniert; unbezahlt storniert ist erledigt');
    }

    public function testUeberzahlungAbEinemCent(): void
    {
        $this->order(1, paid: ['20.0000', '0.0100']);
        $this->order(2, paid: ['20.0000', '0.0050']);
        $this->order(3, paid: ['20.0000']);

        self::assertSame([1], array_keys($this->orders()->all()));
        self::assertSame('0.0100', $this->orders()->all()[1]['excess']);
    }

    public function testZurueckgezahltOderKorrigiertIstErledigt(): void
    {
        $this->order(1, paid: ['20.0000'], fields: ['cancelledAt' => 100]);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '-20.0000', 'method' => 'refund', 'refundOf' => 1, 'payoutState' => 'manual']);
        $this->order(2, paid: ['20.0000', '5.0000']);
        $this->connection->insert('tl_consho_payment', ['pid' => 2, 'amount' => '-5.0000', 'method' => 'correction', 'refundOf' => 22]);
        $this->order(3, paid: ['20.0000'], fields: ['cancelledAt' => 100]);
        $this->connection->insert('tl_consho_payment', ['pid' => 3, 'amount' => '-8.0000', 'method' => 'refund', 'refundOf' => 31, 'payoutState' => 'manual']);

        self::assertSame([3 => '12.0000'], array_map(static fn (array $o): string => $o['excess'], $this->orders()->all()), 'teilweise zurück: der Rest bleibt');
    }

    public function testOffeneFremdeErstattungIstSchonZurueck(): void
    {
        $this->order(1, paid: ['20.0000'], fields: ['cancelledAt' => 100]);
        $this->order(2, paid: ['20.0000'], fields: ['cancelledAt' => 100]);
        $this->connection->insert('tl_consho_provider_event', ['pid' => 1, 'payment' => 11, 'kind' => 'refund', 'state' => 'open', 'amount' => '20.0000', 'reference' => 're_1']);
        $this->connection->insert('tl_consho_provider_event', ['pid' => 2, 'payment' => 21, 'kind' => 'refund', 'state' => 'open', 'amount' => '5.0000', 'reference' => 're_2']);

        self::assertSame([2 => '15.0000'], array_map(static fn (array $o): string => $o['excess'], $this->orders()->all()));
    }

    /**
     * An accepted withdrawal lowers the claim at once; until it is refunded
     * the tile "Erstattungen" counts it (G1).
     */
    public function testAngenommenerWiderrufZaehltDortNichtHier(): void
    {
        $this->order(1, paid: ['20.0000']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => 1, 'state' => 'accepted', 'number' => 'WEB-1-W1']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 10, 'quantity' => '1.0000', 'acceptedQuantity' => '1.0000']);

        self::assertSame([], $this->orders()->all());
    }

    public function testAufgehobeneRueckbuchungHatIhrenEigenenBefund(): void
    {
        $this->order(1, paid: ['20.0000', '20.0000']);
        $this->connection->insert('tl_consho_provider_event', ['pid' => 1, 'payment' => 11, 'kind' => 'chargeback', 'state' => 'settled', 'reversedAt' => 100, 'amount' => '20.0000', 'reference' => 'chb_1']);
        $this->order(2, paid: ['20.0000', '20.0000']);

        self::assertSame([2], array_keys($this->orders()->all()));
    }

    public function testBefundJeShopAlsWarnung(): void
    {
        foreach (range(1, 6) as $id) {
            $this->order($id, paid: ['20.0000'], fields: ['cancelledAt' => 100]);
        }

        $this->order(7, paid: ['30.0000'], shop: 2);

        $issues = (new OverpaidOrderIssueSource($this->connection, $this->orders()))->overpaid();

        self::assertCount(2, $issues);
        self::assertSame(ConfigurationFinding::OrderOverpaid, $issues[0]->finding);
        self::assertTrue($issues[0]->finding->isWarning());
        self::assertSame(['consho_order'], $issues[0]->finding->fixedIn());
        self::assertSame(['shop' => 'Wien', 'count' => '6', 'numbers' => 'WEB-1, WEB-2, WEB-3, WEB-4, WEB-5, …'], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
        self::assertSame(['shop' => 'Graz', 'count' => '1', 'numbers' => 'WEB-7'], $issues[1]->context);
    }

    /**
     * @param list<string>          $paid  payment ids are $id * 10 + 1, 2, …
     * @param array<string, mixed> $fields
     */
    private function order(int $id, array $paid = [], array $fields = [], int $shop = 1): void
    {
        $this->connection->insert('tl_consho_order', ['id' => $id, 'shop' => $shop, 'orderNumber' => 'WEB-'.$id, 'grandTotal' => '20.0000', ...$fields]);
        $this->connection->insert('tl_consho_order_item', ['id' => $id * 10, 'pid' => $id, 'quantity' => '1.0000', 'lineTotal' => '20.0000']);

        foreach ($paid as $i => $amount) {
            $this->connection->insert('tl_consho_payment', ['id' => $id * 10 + $i + 1, 'pid' => $id, 'amount' => $amount, 'method' => 'Überweisung']);
        }
    }

    private function orders(): OverpaidOrders
    {
        return new OverpaidOrders($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));
    }
}

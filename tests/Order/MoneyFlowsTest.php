<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\MoneyFlows;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * Block Geldflüsse, 3.6: the movements of an order and its balance.
 */
final class MoneyFlowsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_provider_event'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'WEB-1', 'grandTotal' => '50.0000']);
        $this->connection->insert('tl_consho_payment', ['id' => 1, 'pid' => 1, 'amount' => '30.0000', 'method' => 'Karte', 'receivedAt' => 100]);
        $this->connection->insert('tl_consho_payment', ['id' => 2, 'pid' => 1, 'amount' => '20.0000', 'method' => 'Gift voucher', 'reference' => 'voucher:1', 'receivedAt' => 100]);
    }

    /**
     * A chargeback and a foreign refund once recorded carry the event's ID:
     * the event explains the row and is no movement of its own.
     */
    public function testEinEreignisMitZeileIstKeineEigeneBewegung(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 3, 'pid' => 1, 'amount' => '-30.0000', 'method' => 'chargeback', 'refundOf' => 1, 'payoutReference' => 'chb_1', 'receivedAt' => 200]);
        $this->connection->insert('tl_consho_payment', ['id' => 4, 'pid' => 1, 'amount' => '30.0000', 'method' => 'chargebackReversal', 'refundOf' => 1, 'payoutReference' => 'chb_1', 'receivedAt' => 300]);
        $this->connection->insert('tl_consho_payment', ['id' => 5, 'pid' => 1, 'amount' => '-4.0000', 'method' => 'refund', 'refundOf' => 1, 'payoutState' => 'done', 'payoutReference' => 're_1', 'receivedAt' => 400]);
        $this->connection->insert('tl_consho_provider_event', ['id' => 1, 'pid' => 1, 'payment' => 1, 'kind' => 'chargeback', 'reference' => 'chb_1', 'amount' => '30.0000', 'state' => 'open', 'reversedAt' => 300, 'occurredAt' => 200]);
        $this->connection->insert('tl_consho_provider_event', ['id' => 2, 'pid' => 1, 'payment' => 1, 'kind' => 'refund', 'reference' => 're_1', 'amount' => '4.0000', 'state' => 'recorded', 'occurredAt' => 400]);

        $flows = $this->flows()->of(1);

        self::assertNotNull($flows);
        self::assertSame(['payment', 'payment', 'chargeback', 'chargebackReversal', 'refund'], array_column($flows['movements'], 'kind'));
        self::assertSame(1, $flows['movements'][2]['event']['id']);
        self::assertSame('0.0000', $flows['balance']['chargedBack'], 'aufgehoben');
        self::assertSame('4.0000', $flows['balance']['refunded']);
        self::assertSame('0.0000', $flows['balance']['outstanding'], 'a refund is deliberate: nothing is demanded');
    }

    public function testEineOffeneFremdeErstattungIstEineBewegungUndZaehltAlsZurueck(): void
    {
        $this->connection->update('tl_consho_order', ['cancelledAt' => 500], ['id' => 1]);
        $this->connection->insert('tl_consho_payment', ['id' => 3, 'pid' => 1, 'amount' => '-20.0000', 'method' => 'Gift voucher', 'reference' => 'voucher:1', 'receivedAt' => 500]);
        $this->connection->insert('tl_consho_provider_event', ['id' => 1, 'pid' => 1, 'payment' => 1, 'kind' => 'refund', 'reference' => 're_x', 'amount' => '10.0000', 'state' => 'open', 'occurredAt' => 600]);

        $flows = $this->flows()->of(1);

        self::assertNotNull($flows);
        self::assertTrue($flows['cancelled']);
        self::assertSame(['payment', 'payment', 'refund', 'foreignRefund'], array_column($flows['movements'], 'kind'));
        self::assertSame('-10.0000', $flows['movements'][3]['amount']);
        self::assertSame(1, $flows['movements'][3]['refundOf']);
        self::assertSame('20.0000', $flows['balance']['refunded'], 'die Gutscheinzahlung zurück auf den Gutschein');
        self::assertSame('10.0000', $flows['balance']['outside']);
        self::assertSame('20.0000', $flows['balance']['tooMuch'], '30 auf eine stornierte Bestellung, 10 schon beim Anbieter zurück');
        self::assertSame('0.0000', $flows['balance']['outstanding']);
    }

    public function testEineUnbekannteBestellungHatKeine(): void
    {
        self::assertNull($this->flows()->of(99));
    }

    private function flows(): MoneyFlows
    {
        return new MoneyFlows($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\OrderLabelListener;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * The payment signal of the order list names the state, not the colour.
 * An order that was cancelled and refunded read "Payment: open", because
 * "refunded" is grey like "open".
 */
final class OrderLabelListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        unset($GLOBALS['TL_LANG']);
        include __DIR__.'/../../../contao/languages/de/tl_consho_order.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testStorniertUndErstattetHeisstErstattet(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'WEB-1', 'grandTotal' => '29.3000', 'cancelledAt' => 1]);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '19.0000']);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '-19.0000']);

        $signals = $this->listener()(['id' => 1, 'orderNumber' => 'WEB-1', 'grandTotal' => '29.3000', 'currency' => 'EUR'], '')[0];

        self::assertStringContainsString('title="Zahlung: erstattet"', $signals);
        self::assertStringNotContainsString('Zahlung: offen', $signals);
    }

    public function testJederZahlungszustandHatEinenText(): void
    {
        foreach (PaymentState::cases() as $state) {
            self::assertArrayHasKey($state->value, $GLOBALS['TL_LANG']['tl_consho_order']['signals']['payment'], $state->name);
        }
    }

    private function listener(): OrderLabelListener
    {
        return new OrderLabelListener($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));
    }
}

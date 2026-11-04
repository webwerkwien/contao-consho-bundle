<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\MoneyFlowsButtonListener;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OverpaidOrders;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\UnassignedRefunds;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * "Geldflüsse" in every row: red while something waits for the operator,
 * plain otherwise — never greyed, every order has its page.
 */
final class MoneyFlowsButtonListenerTest extends TestCase
{
    private const ICON = 'sync.svg';
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_provider_event'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        foreach (range(1, 9) as $id) {
            $this->connection->insert('tl_consho_order', ['id' => $id, 'orderNumber' => 'WEB-'.$id, 'grandTotal' => '10.0000']);
        }
    }

    public function testRotWoEtwasWartet(): void
    {
        $this->event(1, 'refund', 're_1', 'open');
        $this->event(2, 'chargeback', 'chb_2', 'open');
        $this->event(3, 'refund', 're_3', 'recorded');
        $this->event(3, 'chargeback', 'chb_3', 'settled');
        // Reversed by the bank: nothing left to do.
        $this->event(4, 'chargeback', 'chb_4', 'open', self::NOW);

        $this->payment(5, '-5.0000', 'failed', self::NOW);
        // Booked a day ago and never sent: the cron does not run.
        $this->payment(6, '-5.0000', 'requested', self::NOW - 86401);
        $this->payment(7, '-5.0000', 'sending', self::NOW - 3601, 'hangs');
        $this->payment(8, '-5.0000', 'sending', self::NOW, 'just sent');
        // Paid 15 on a claim of 10.
        $this->payment(9, '15.0000', '', self::NOW);
        // A refund without its payment (J3); a voucher's does not count.
        $this->connection->insert('tl_consho_order', ['id' => 10, 'orderNumber' => 'WEB-10', 'grandTotal' => '10.0000']);
        $this->connection->insert('tl_consho_order', ['id' => 11, 'orderNumber' => 'WEB-11', 'grandTotal' => '10.0000']);
        $this->connection->insert('tl_consho_payment', ['pid' => 10, 'amount' => '10.0000', 'method' => 'Karte', 'receivedAt' => 1]);
        $this->connection->insert('tl_consho_payment', ['pid' => 10, 'amount' => '-4.0000', 'method' => 'refund', 'payoutState' => 'manual', 'receivedAt' => 2]);
        $this->connection->insert('tl_consho_payment', ['pid' => 11, 'amount' => '10.0000', 'method' => 'Gift voucher', 'reference' => 'voucher:1', 'receivedAt' => 1]);
        $this->connection->insert('tl_consho_payment', ['pid' => 11, 'amount' => '-4.0000', 'method' => 'Gift voucher', 'reference' => 'voucher:1', 'receivedAt' => 2]);
        // Not yet waiting (the start page's thresholds): a request the hourly
        // cron still sends, a refund pending at the provider for two days.
        $this->connection->insert('tl_consho_order', ['id' => 12, 'orderNumber' => 'WEB-12', 'grandTotal' => '10.0000']);
        $this->connection->insert('tl_consho_order', ['id' => 13, 'orderNumber' => 'WEB-13', 'grandTotal' => '10.0000']);
        $this->payment(12, '-5.0000', 'requested', self::NOW - 600);
        $this->payment(13, '-5.0000', 'requested', self::NOW - 2 * 86400);
        $this->connection->update('tl_consho_payment', ['payoutReference' => 're_13'], ['pid' => 13]);

        $listener = new MoneyFlowsButtonListener(
            $this->connection,
            new OverpaidOrders($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator())),
            new UnassignedRefunds($this->connection),
            static fn (): int => self::NOW,
        );
        $icons = [];

        foreach (range(1, 13) as $orderId) {
            $operation = $this->operation($orderId);
            $listener($operation);
            $icons[$orderId] = $operation['icon'];
        }

        $open = MoneyFlowsButtonListener::ICON_OPEN;

        self::assertSame([1 => $open, 2 => $open, 3 => self::ICON, 4 => self::ICON, 5 => $open, 6 => $open, 7 => $open, 8 => self::ICON, 9 => $open, 10 => $open, 11 => self::ICON, 12 => self::ICON, 13 => self::ICON], $icons);
    }

    public function testDasSymbolGibtEsUndDieDcaNenntEs(): void
    {
        self::assertFileExists(__DIR__.'/../../../public/icons/money-flows--open.svg');

        $dca = file_get_contents(__DIR__.'/../../../contao/dca/tl_consho_order.php') ?: '';
        self::assertStringContainsString("'moneyFlows' => ['href' => 'key=moneyFlows', 'icon' => '".self::ICON."'", $dca);
        self::assertStringNotContainsString("'payments' =>", $dca, 'replaced by Geldflüsse');
        self::assertStringNotContainsString("'followUps' =>", $dca, 'replaced by Geldflüsse');
    }

    private function event(int $orderId, string $kind, string $reference, string $state, int $reversedAt = 0): void
    {
        $this->connection->insert('tl_consho_provider_event', ['pid' => $orderId, 'kind' => $kind, 'reference' => $reference, 'amount' => '1', 'state' => $state, 'reversedAt' => $reversedAt]);
    }

    private function payment(int $orderId, string $amount, string $payoutState, int $tstamp, string $reference = ''): void
    {
        // A refund names its payment here: one without would turn red of its own (J3).
        $this->connection->insert('tl_consho_payment', ['pid' => $orderId, 'amount' => $amount, 'method' => 'refund', 'refundOf' => str_starts_with($amount, '-') ? 99 : 0, 'payoutState' => $payoutState, 'tstamp' => $tstamp, 'reference' => $reference, 'receivedAt' => $tstamp]);
    }

    private function operation(int $orderId): DataContainerOperation
    {
        return new DataContainerOperation('moneyFlows', ['href' => 'key=moneyFlows', 'icon' => self::ICON, 'label' => ['Geldflüsse', '%s']], ['id' => $orderId], $this->createStub(DataContainer::class));
    }
}

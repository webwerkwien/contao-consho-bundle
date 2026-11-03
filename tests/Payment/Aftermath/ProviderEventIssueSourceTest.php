<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment\Aftermath;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Payment\Aftermath\ProviderEventIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Spec 9, P5: chargebacks and foreign refunds on the start page, one finding
 * per event, gone by themselves once settled.
 */
final class ProviderEventIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_shop', 'tl_consho_order', 'tl_consho_order_item', 'tl_consho_payment', 'tl_consho_provider_event', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_order_status'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $this->connection->insert('tl_consho_order', ['id' => 1, 'shop' => 1, 'orderNumber' => 'B-1', 'grandTotal' => '50.0000', 'currency' => 'EUR']);
        // A shop that was deleted.
        $this->connection->insert('tl_consho_order', ['id' => 2, 'shop' => 9, 'orderNumber' => 'B-2', 'grandTotal' => '30.0000', 'currency' => 'EUR']);
        $this->connection->insert('tl_consho_payment', ['id' => 10, 'pid' => 1, 'amount' => '50.0000', 'method' => 'Karte']);
        $this->connection->insert('tl_consho_payment', ['id' => 20, 'pid' => 2, 'amount' => '30.0000', 'method' => 'Karte']);
    }

    public function testNothingWithoutEvents(): void
    {
        self::assertSame([], $this->issues());
    }

    public function testAStandingChargebackOnAnOrderThatOwes(): void
    {
        $this->chargeback(1, 'chb_1', '50.0000', 'MD06 Return of funds requested');

        self::assertEquals([
            new ConfigurationIssue(ConfigurationFinding::PaymentChargedBack, ['number' => 'B-1', 'reason' => 'MD06 Return of funds requested', 'reference' => 'chb_1'], 1),
        ], $this->issues());
        self::assertFalse(ConfigurationFinding::PaymentChargedBack->isWarning(), 'a note, so it can be acknowledged');
    }

    public function testWithoutAReasonADashIsShown(): void
    {
        $this->chargeback(2, 'chb_2', '30.0000', '');

        self::assertEquals([
            new ConfigurationIssue(ConfigurationFinding::PaymentChargedBack, ['number' => 'B-2', 'reason' => '—', 'reference' => 'chb_2'], 0),
        ], $this->issues());
    }

    /**
     * Paid again, then the bank reversed the chargeback: the customer paid
     * twice — a warning until the money goes back.
     */
    public function testReversedAfterPayingAgainTheCustomerIsOwedMoney(): void
    {
        $this->event(1, 'chargeback', 'chb_1', '50.0000', '', 'settled', 1_800_000_000);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '-50.0000', 'method' => 'chargeback', 'refundOf' => 10, 'payoutReference' => 'chb_1']);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '50.0000', 'method' => 'Karte']);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '50.0000', 'method' => 'chargebackReversal', 'payoutReference' => 'chb_1']);

        self::assertEquals([
            new ConfigurationIssue(ConfigurationFinding::ChargebackReversedAfterPayment, ['number' => 'B-1', 'reference' => 'chb_1'], 1),
        ], $this->issues());
        self::assertTrue(ConfigurationFinding::ChargebackReversedAfterPayment->isWarning());
        self::assertSame(['consho_order'], ConfigurationFinding::ChargebackReversedAfterPayment->fixedIn());

        // Paid back: gone.
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '-50.0000', 'method' => 'refund', 'refundOf' => 10]);
        self::assertSame([], $this->issues());
    }

    /**
     * "Jetzt bezahlen" opened while the chargeback stood, paid after the
     * reversal: never `settled`, overpaid all the same.
     */
    public function testPaidAgainAfterTheReversalTheCustomerIsOwedMoney(): void
    {
        $this->chargeback(1, 'chb_1', '50.0000', '', reversedAt: 1_800_000_500);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '50.0000', 'method' => 'chargebackReversal', 'refundOf' => 10, 'payoutReference' => 'chb_1']);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '50.0000', 'method' => 'Karte']);

        self::assertEquals([
            new ConfigurationIssue(ConfigurationFinding::ChargebackReversedAfterPayment, ['number' => 'B-1', 'reference' => 'chb_1'], 1),
        ], $this->issues());
    }

    public function testReversedWithoutPayingAgainNothingIsOwed(): void
    {
        $this->chargeback(1, 'chb_1', '50.0000', '', 1_800_000_000);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '50.0000', 'method' => 'chargebackReversal', 'payoutReference' => 'chb_1']);

        self::assertSame([], $this->issues());
    }

    public function testPaidAgainTheFindingIsGone(): void
    {
        $this->chargeback(1, 'chb_1', '50.0000', '');
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '50.0000', 'method' => 'Karte']);

        self::assertSame([], $this->issues());
    }

    public function testAReversedChargebackIsGone(): void
    {
        $this->chargeback(1, 'chb_1', '50.0000', '', reversedAt: 1_800_000_500);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '50.0000', 'method' => 'chargebackReversal', 'refundOf' => 10, 'payoutReference' => 'chb_1']);

        self::assertSame([], $this->issues());
    }

    public function testTwoChargebacksAreTwoFindings(): void
    {
        $this->chargeback(1, 'chb_1', '20.0000', '');
        $this->chargeback(1, 'chb_2', '10.0000', '');

        $issues = $this->issues();

        self::assertCount(2, $issues);
        self::assertNotSame($issues[0]->fingerprint(), $issues[1]->fingerprint());
    }

    public function testAnOpenForeignRefundIsAWarningUntilResolved(): void
    {
        $this->event(1, 'refund', 're_x', '20.0000', '', 'open');
        $this->event(1, 'refund', 're_y', '5.0000', '', 'recorded');
        $this->event(1, 'refund', 're_z', '5.0000', '', 'assigned');

        self::assertEquals([
            new ConfigurationIssue(ConfigurationFinding::RefundOutsideConsho, ['number' => 'B-1', 'reference' => 're_x'], 1),
        ], $this->issues());
        self::assertTrue(ConfigurationFinding::RefundOutsideConsho->isWarning());
    }

    public function testBothAreFixedInTheOrders(): void
    {
        self::assertSame(['consho_order'], ConfigurationFinding::PaymentChargedBack->fixedIn());
        self::assertSame(['consho_order'], ConfigurationFinding::RefundOutsideConsho->fixedIn());
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new ProviderEventIssueSource($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator())))->issues();
    }

    private function chargeback(int $orderId, string $reference, string $amount, string $reason, int $reversedAt = 0): void
    {
        $this->event($orderId, 'chargeback', $reference, $amount, $reason, 'open', $reversedAt);
        $this->connection->insert('tl_consho_payment', ['pid' => $orderId, 'amount' => '-'.$amount, 'method' => 'chargeback', 'refundOf' => 10 * $orderId, 'payoutReference' => $reference]);
    }

    private function event(int $orderId, string $kind, string $reference, string $amount, string $reason, string $state, int $reversedAt = 0): void
    {
        $this->connection->insert('tl_consho_provider_event', [
            'pid' => $orderId,
            'payment' => 10 * $orderId,
            'kind' => $kind,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => 'EUR',
            'reason' => $reason,
            'state' => $state,
            'reversedAt' => $reversedAt,
        ]);
    }
}

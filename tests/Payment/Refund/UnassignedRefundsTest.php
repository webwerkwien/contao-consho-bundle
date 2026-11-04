<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment\Refund;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\UnassignedRefundIssueSource;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\UnassignedRefunds;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block Geldflüsse, J3: a refund that names no payment — found, and tied to
 * a payment whose rest still covers it.
 */
final class UnassignedRefundsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_shop', 'tl_consho_order', 'tl_consho_payment', 'tl_consho_payment_attempt', 'tl_consho_provider_event'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Laden']);
        $this->connection->insert('tl_consho_order', ['id' => 1, 'shop' => 1, 'orderNumber' => 'WEB-1', 'grandTotal' => '50.0000']);
        $this->connection->insert('tl_consho_payment', ['id' => 1, 'pid' => 1, 'amount' => '30.0000', 'method' => 'Karte', 'receivedAt' => 100]);
        $this->connection->insert('tl_consho_payment', ['id' => 2, 'pid' => 1, 'amount' => '5.0000', 'method' => 'Überweisung', 'receivedAt' => 110]);
        $this->connection->insert('tl_consho_payment', ['id' => 3, 'pid' => 1, 'amount' => '15.0000', 'method' => 'Gift voucher', 'reference' => 'voucher:1', 'receivedAt' => 100]);
        // Without its payment: 8 back by hand.
        $this->connection->insert('tl_consho_payment', ['id' => 4, 'pid' => 1, 'amount' => '-8.0000', 'method' => 'refund', 'payoutState' => 'manual', 'receivedAt' => 200]);
        // Not counted: a voucher's refund, a correction, a refund with its payment.
        $this->connection->insert('tl_consho_payment', ['id' => 5, 'pid' => 1, 'amount' => '-5.0000', 'method' => 'Gift voucher', 'reference' => 'voucher:1', 'receivedAt' => 200]);
        $this->connection->insert('tl_consho_payment', ['id' => 6, 'pid' => 1, 'amount' => '-1.0000', 'method' => 'correction', 'refundOf' => 1, 'reference' => 'Tippfehler', 'receivedAt' => 210]);
        $this->connection->insert('tl_consho_payment', ['id' => 7, 'pid' => 1, 'amount' => '-2.0000', 'method' => 'refund', 'refundOf' => 1, 'payoutState' => 'manual', 'receivedAt' => 220]);
    }

    public function testNurDieRueckzahlungOhneZahlung(): void
    {
        self::assertSame([4 => ['order' => 1, 'shop' => 1, 'number' => 'WEB-1']], (new UnassignedRefunds($this->connection))->all());

        $issues = (new UnassignedRefundIssueSource($this->connection, new UnassignedRefunds($this->connection)))->issues();
        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::RefundWithoutPayment, $issues[0]->finding);
        self::assertSame(['shop' => 'Laden', 'count' => '1', 'numbers' => 'WEB-1'], $issues[0]->context);
    }

    public function testZuordenbarIstNurEineZahlungDieDenBetragNochHat(): void
    {
        // 30 − 1 − 2 = 27 left of the card; 5 of the transfer is less than 8; the voucher never.
        self::assertSame([1 => '27.0000'], $this->payout()->assignablePayments(4));
        self::assertSame([], $this->payout()->assignablePayments(7), 'already has its payment');
        self::assertSame([], $this->payout()->assignablePayments(5), 'a voucher refund');
    }

    public function testZuordnenBindetDieZeileUndDerBefundGehtWeg(): void
    {
        self::assertFalse($this->payout()->assignToPayment(1, 4, 2), 'too little left');
        self::assertFalse($this->payout()->assignToPayment(2, 4, 1), 'another order');
        self::assertTrue($this->payout()->assignToPayment(1, 4, 1));

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT refundOf FROM tl_consho_payment WHERE id = 4'));
        self::assertTrue(BigDecimal::of((string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE id = 4'))->isEqualTo('-8'), 'no amount changes');
        self::assertSame([], (new UnassignedRefunds($this->connection))->all());
        self::assertSame('19.0000', $this->payout()->remainder(1, 1), 'the card has 8 less left');
        self::assertFalse($this->payout()->assignToPayment(1, 4, 1), 'only once');
    }

    /**
     * With every order lockable, only the row's own order holds: neither the
     * row nor the payment may belong to another one.
     */
    public function testNurZeileUndZahlungDerselbenBestellung(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 2, 'shop' => 1, 'orderNumber' => 'WEB-2', 'grandTotal' => '50.0000']);
        $this->connection->insert('tl_consho_payment', ['id' => 8, 'pid' => 2, 'amount' => '50.0000', 'method' => 'Karte', 'receivedAt' => 100]);
        $payout = $this->payout(true);

        self::assertFalse($payout->assignToPayment(2, 4, 8), 'the row is of order 1');
        self::assertFalse($payout->assignToPayment(1, 4, 8), 'the payment is of order 2');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT refundOf FROM tl_consho_payment WHERE id = 4'));
        self::assertTrue($payout->assignToPayment(1, 4, 1));
    }

    private function payout(bool $anyOrder = false): RefundPayout
    {
        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $orders->method('lockOrder')->willReturnCallback(static fn (int $orderId): array|null => $anyOrder || 1 === $orderId ? ['id' => (string) $orderId] : null);

        $methods = new class implements PaymentMethodLookupInterface {
            public function byId(int $methodId): PaymentMethod|null
            {
                return null;
            }
        };

        return new RefundPayout($this->connection, $orders, $methods, new PaymentHandlers(new ServiceLocator([])));
    }
}

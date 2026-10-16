<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentDeferral;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquencyRun;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * E8 (Michael, 2026-10-08): Stundung über das Zahlungsziel statt einer
 * erfassten Scheinzahlung — nur später, nur offen, und das Abo wird sofort
 * neu bewertet.
 */
final class PaymentDeferralTest extends TestCase
{
    private const NOW = 1_790_000_000;

    private Connection $connection;

    /** @var list<int> */
    private array $reassessed = [];

    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_order', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testVerschiebtAufDasEndeDesGewaehltenTagesUndBewertetDasAboNeu(): void
    {
        $this->order(1, dueAt: (new \DateTimeImmutable('2026-10-01 23:59:59'))->getTimestamp());

        self::assertNull($this->deferral()->defer(1, '2026-10-31', self::NOW));

        self::assertSame((new \DateTimeImmutable('2026-10-31 23:59:59'))->getTimestamp(), (int) $this->connection->fetchOne('SELECT paymentDueAt FROM tl_consho_order WHERE id = 1'));
        self::assertSame([1], $this->reassessed, 'ein ruhendes Abo läuft sofort wieder, nicht erst mit dem Cron');
    }

    public function testNurEinSpaetererTag(): void
    {
        $due = (new \DateTimeImmutable('2026-10-01 23:59:59'))->getTimestamp();
        $this->order(1, dueAt: $due);

        self::assertSame('notLater', $this->deferral()->defer(1, '2026-10-01', self::NOW));
        self::assertSame('notLater', $this->deferral()->defer(1, '2026-09-20', self::NOW));
        self::assertSame('invalidDate', $this->deferral()->defer(1, '2026-02-30', self::NOW));
        self::assertSame('invalidDate', $this->deferral()->defer(1, '', self::NOW));
        self::assertSame($due, (int) $this->connection->fetchOne('SELECT paymentDueAt FROM tl_consho_order WHERE id = 1'));
        self::assertSame([], $this->reassessed);
    }

    public function testNurOffeneBestellungenMitZiel(): void
    {
        $due = (new \DateTimeImmutable('2026-10-01 23:59:59'))->getTimestamp();
        $this->order(1, dueAt: $due, paid: '20');
        $this->order(2, dueAt: $due, cancelledAt: self::NOW);
        $this->order(3, dueAt: 0);
        $this->order(4, dueAt: $due, paid: '5');

        self::assertSame('notDeferrable', $this->deferral()->defer(1, '2026-10-31', self::NOW), 'bezahlt');
        self::assertSame('notDeferrable', $this->deferral()->defer(2, '2026-10-31', self::NOW), 'storniert');
        self::assertSame('notDeferrable', $this->deferral()->defer(3, '2026-10-31', self::NOW), 'Vorkasse, ohne Ziel');
        self::assertSame('notDeferrable', $this->deferral()->defer(99, '2026-10-31', self::NOW), 'gibt es nicht');
        self::assertNull($this->deferral()->defer(4, '2026-10-31', self::NOW), 'teilbezahlt ist noch offen');
    }

    private function order(int $id, int $dueAt, string|null $paid = null, int|null $cancelledAt = null): void
    {
        $this->connection->insert('tl_consho_order', ['id' => $id, 'orderNumber' => 'WEB-'.$id, 'grandTotal' => '20.0000', 'paymentDueAt' => $dueAt, 'cancelledAt' => $cancelledAt]);
        $this->connection->insert('tl_consho_order_item', ['id' => $id * 10, 'pid' => $id, 'quantity' => '1.0000', 'lineTotal' => '20.0000']);

        if (null !== $paid) {
            $this->connection->insert('tl_consho_payment', ['pid' => $id, 'amount' => $paid]);
        }
    }

    private function deferral(): PaymentDeferral
    {
        $run = $this->createMock(SubscriptionDelinquencyRun::class);
        $run->method('afterPayment')->willReturnCallback(function (int $orderId): array {
            $this->reassessed[] = $orderId;

            return [];
        });

        return new PaymentDeferral($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()), $run);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderStatusRepository;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OrderEvent;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusWriterInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\StatusFlags;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The automatic status change (Spec D 5.3).
 */
final class OrderStatusAdvancerTest extends TestCase
{
    private const ORDER = 7;

    private const NEW = 1;

    private const PAID = 2;

    private const SHIPPED = 3;

    private const DONE = 4;

    private const LOCKED = 5;

    private const FOREIGN = 9;

    private Connection $connection;

    /** @var list<int> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        // From the DCA (WG-7), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        foreach ([
            [self::NEW, 'Neu', []],
            [self::PAID, 'Bezahlt', ['isPaid' => 1]],
            [self::SHIPPED, 'Versendet', ['isShipped' => 1]],
            [self::DONE, 'Abgeschlossen', ['isCompleted' => 1]],
            [self::LOCKED, 'Gesperrt', ['locksChanges' => 1]],
        ] as [$id, $title, $flags]) {
            $this->connection->insert('tl_consho_order_status', ['id' => $id, 'pid' => 1, 'title' => $title, ...$flags]);
        }

        $this->connection->insert('tl_consho_order_status', ['id' => self::FOREIGN, 'pid' => 2, 'title' => 'Bezahlt (Shop 2)', 'isPaid' => 1]);

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'statusAfterPayment' => self::PAID, 'statusAfterDelivery' => self::SHIPPED, 'statusWhenSettled' => self::DONE]);
        $this->connection->insert('tl_consho_order', ['id' => self::ORDER, 'shop' => 1, 'grandTotal' => '50', 'orderStatus' => self::NEW]);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'quantity' => '1']);
    }

    public function testNachDerZahlungKommtDerStatusFuerDieZahlung(): void
    {
        $this->pay();

        self::assertSame(self::PAID, $this->advance(OrderEvent::PaymentSettled)?->id);
        self::assertSame([self::PAID], $this->written);
    }

    public function testSindZahlungUndLieferungErledigtHatAbgeschlossenVorrang(): void
    {
        $this->pay();
        $this->ship();

        self::assertSame(self::DONE, $this->advance(OrderEvent::DeliveryReported)?->id);
    }

    public function testNachDerLieferungOhneZahlungKommtDerLieferstatus(): void
    {
        $this->ship();

        self::assertSame(self::SHIPPED, $this->advance(OrderEvent::DeliveryReported)?->id);
    }

    /**
     * A status "when everything is done" without isCompleted demands nothing
     * through its flags — the condition of the shop field applies anyway.
     */
    public function testAllesErledigtOhneFlagWartetTrotzdemAufBeideSalden(): void
    {
        $this->connection->update('tl_consho_shop', ['statusWhenSettled' => self::NEW, 'statusAfterPayment' => 0], ['id' => 1]);
        $this->connection->update('tl_consho_order', ['orderStatus' => 0], ['id' => self::ORDER]);
        $this->pay();

        self::assertNull($this->advance(OrderEvent::PaymentSettled));
    }

    /**
     * The same status in two fields — after the payment the condition of the
     * payment field applies, not that of "everything done".
     */
    public function testDerselbeStatusInZweiFeldernFolgtDemFeldDesEreignisses(): void
    {
        $this->connection->update('tl_consho_shop', ['statusAfterPayment' => self::PAID, 'statusWhenSettled' => self::PAID], ['id' => 1]);
        $this->pay();

        self::assertSame(self::PAID, $this->advance(OrderEvent::PaymentSettled)?->id);
    }

    /**
     * A status with isCompleted or isCancelled locks the order like
     * locksChanges.
     */
    public function testAusAbgeschlossenWirdNichtGewechselt(): void
    {
        $this->connection->update('tl_consho_order', ['orderStatus' => self::DONE], ['id' => self::ORDER]);
        $this->pay();

        self::assertNull($this->advance(OrderEvent::PaymentSettled));
    }

    public function testAusEinemSperrendenStatusWirdNichtGewechselt(): void
    {
        $this->connection->update('tl_consho_order', ['orderStatus' => self::LOCKED], ['id' => self::ORDER]);
        $this->pay();

        self::assertNull($this->advance(OrderEvent::PaymentSettled));
        self::assertSame([], $this->written);
    }

    public function testDerStatusEinesAnderenShopsWirdNieGesetzt(): void
    {
        $this->connection->update('tl_consho_shop', ['statusAfterPayment' => self::FOREIGN, 'statusWhenSettled' => 0], ['id' => 1]);
        $this->pay();

        self::assertNull($this->advance(OrderEvent::PaymentSettled));
    }

    /**
     * D-7: "When everything is done" → a cancelling status CAN apply —
     * everything withdrawn before shipping and fully refunded: claim 0, paid 0,
     * nothing shipped. That is why OrderStatusIssueSource (M4) warns only for
     * "after payment" and "after delivery".
     */
    public function testVollErstattetOhneVersandSetztEinenStornierendenStatusWennAllesErledigtIst(): void
    {
        $this->connection->insert('tl_consho_order_status', ['id' => 6, 'pid' => 1, 'title' => 'Storniert', 'isCancelled' => 1]);
        $this->connection->update('tl_consho_shop', ['statusWhenSettled' => 6], ['id' => 1]);
        $this->connection->update('tl_consho_order', ['orderStatus' => self::PAID], ['id' => self::ORDER]);
        $this->connection->update('tl_consho_order_item', ['lineTotal' => '50'], ['id' => 1]);
        $this->pay();
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '-50']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => self::ORDER, 'state' => 'accepted', 'refundedAt' => 1, 'refundAmount' => '50']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'acceptedQuantity' => '1']);

        self::assertSame(6, $this->advance(OrderEvent::WithdrawalRefunded)?->id);
    }

    public function testOhneEinstellungPassiertNichts(): void
    {
        $this->connection->update('tl_consho_shop', ['statusAfterPayment' => 0, 'statusAfterDelivery' => 0, 'statusWhenSettled' => 0], ['id' => 1]);
        $this->pay();

        self::assertNull($this->advance(OrderEvent::PaymentSettled));
    }

    private function advance(OrderEvent $event): StatusFlags|null
    {
        $writer = new class implements OrderStatusWriterInterface {
            /** @var list<int> */
            public array $written = [];

            public function write(int $orderId, StatusFlags $status, int $now): void
            {
                $this->written[] = $status->id;
            }
        };

        $advancer = new OrderStatusAdvancer(
            $this->connection,
            new DbalOrderStatusRepository($this->connection),
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            $writer,
        );

        $result = $advancer->advance(self::ORDER, $event, 1_790_000_000);
        $this->written = $writer->written;

        return $result;
    }

    private function pay(): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '50']);
    }

    private function ship(): void
    {
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => self::ORDER, 'reportedAt' => 1_790_000_000]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1']);
    }
}

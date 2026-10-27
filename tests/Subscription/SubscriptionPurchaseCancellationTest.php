<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalance;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquency;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPurchaseCancellation;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-7b: a cancelled renewal returns the licence.
 *
 * Old subscription 40 (order 3, line 7, paid until NOW + 10 days), renewed
 * by subscription 44 from order 5 (line 10); entitlement 70 has already been
 * taken over and runs until NOW + 40 days. With the real delinquency check —
 * it decides whether the returned licence is suspended.
 */
final class SubscriptionPurchaseCancellationTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const DAY = 86_400;

    private Connection $connection;

    /** @var array<int, string> what each order still owes, by id */
    private array $outstanding = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_subscription', 'tl_consho_entitlement'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 3, 'orderNumber' => 'WEB-00003', 'grandTotal' => '12.0000', 'paymentDueAt' => self::NOW - 60 * self::DAY]);
        $this->connection->insert('tl_consho_order', ['id' => 5, 'orderNumber' => 'WEB-00005', 'grandTotal' => '12.0000', 'paymentDueAt' => self::NOW - 30 * self::DAY]);
        $this->outstanding = [3 => '0', 5 => '12.0000'];

        $this->connection->insert('tl_consho_subscription', ['id' => 40, 'subscriptionNumber' => 'WEB-00003-1', 'firstOrder' => 3, 'orderItem' => 7, 'periodUntil' => self::NOW + 10 * self::DAY, 'endsAt' => self::NOW + 10 * self::DAY, 'renewedBy' => 44, 'state' => 'active', 'graceDays' => 14]);
        $this->connection->insert('tl_consho_subscription', ['id' => 44, 'subscriptionNumber' => 'WEB-00005-1', 'firstOrder' => 5, 'orderItem' => 10, 'periodUntil' => self::NOW + 40 * self::DAY, 'predecessor' => 40, 'state' => 'active', 'graceDays' => 14]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 70, 'pid' => 5, 'orderItem' => 10, 'subscription' => 44, 'validUntil' => self::NOW + 40 * self::DAY]);
    }

    public function testDieLizenzGehtZurueckUndDasAlteAboIstWiederVerlaengerbar(): void
    {
        $this->rollback()->afterCancellation(5, self::NOW);

        $entitlement = $this->connection->fetchAssociative('SELECT pid, orderItem, subscription, validUntil FROM tl_consho_entitlement WHERE id = 70');
        self::assertSame([3, 7, 40, self::NOW + 10 * self::DAY], array_map('intval', array_values($entitlement ?: [])));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT renewedBy FROM tl_consho_subscription WHERE id = 40'));
        self::assertSame(['ended', self::NOW], $this->stateAndEnd(44), 'das neue Abo rechnet nichts mehr ab');
        self::assertSame(['active', self::NOW + 10 * self::DAY], $this->stateAndEnd(40), 'das alte läuft wie gekündigt weiter');
    }

    /**
     * The licence taken over was suspended for the unpaid
     * renewal 5. Cancelled, 5 no longer counts — the licence is free.
     */
    public function testEineFuerDieStornierteRechnungRuhendeLizenzWirdFrei(): void
    {
        $this->connection->update('tl_consho_entitlement', ['suspendedAt' => self::NOW - self::DAY], ['id' => 70]);
        $this->connection->update('tl_consho_subscription', ['state' => 'suspended'], ['id' => 44]);
        $this->connection->update('tl_consho_order', ['cancelledAt' => self::NOW], ['id' => 5]);

        $this->rollback()->afterCancellation(5, self::NOW);

        self::assertNull($this->connection->fetchOne('SELECT suspendedAt FROM tl_consho_entitlement WHERE id = 70'));
    }

    public function testEineEigeneOffeneRechnungDesAltenAbosLaesstSieRuhen(): void
    {
        $this->connection->update('tl_consho_entitlement', ['suspendedAt' => self::NOW - self::DAY], ['id' => 70]);
        $this->connection->update('tl_consho_order', ['cancelledAt' => self::NOW], ['id' => 5]);
        $this->outstanding[3] = '12.0000';

        $this->rollback()->afterCancellation(5, self::NOW);

        self::assertNotNull($this->connection->fetchOne('SELECT suspendedAt FROM tl_consho_entitlement WHERE id = 70'));
        self::assertSame('suspended', $this->stateAndEnd(40)[0]);
    }

    /**
     * Abo-Nachlese, P72: an ordinary subscription (no predecessor) ends with the
     * cancellation of its purchase order — otherwise follow-up orders
     * "on invoice" would keep being created for something never bought.
     */
    public function testEinGewoehnlichesAboEndetMitDerStornierung(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 8, 'orderNumber' => 'WEB-00008', 'grandTotal' => '12.0000']);
        $this->connection->insert('tl_consho_subscription', ['id' => 50, 'subscriptionNumber' => 'WEB-00008-1', 'firstOrder' => 8, 'state' => 'active', 'mandate' => 'cus_1|pm_2', 'mandateMethod' => 8]);
        $this->connection->insert('tl_consho_subscription', ['id' => 51, 'subscriptionNumber' => 'WEB-00008-2', 'firstOrder' => 8, 'state' => 'suspended']);

        $this->rollback()->afterCancellation(8, self::NOW);

        self::assertSame(['ended', self::NOW], $this->stateAndEnd(50));
        // The mandate goes with the contract (Abo-4b, P98).
        self::assertSame('', $this->connection->fetchOne('SELECT mandate FROM tl_consho_subscription WHERE id = 50'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT mandateMethod FROM tl_consho_subscription WHERE id = 50'));
        self::assertSame(['ended', self::NOW], $this->stateAndEnd(51), 'auch ruhend');
        self::assertSame(['active', 0], $this->stateAndEnd(44), 'ein anderes Abo bleibt');
    }

    /**
     * Abo-Nachlese: purchase 3 of the already renewed subscription 40 is
     * cancelled — 40 ends (P72), the licence stays with 44, which holds it.
     */
    public function testDerKaufEinesVerlaengertenAbosBeendetEsDieLizenzBleibt(): void
    {
        $this->rollback()->afterCancellation(3, self::NOW);

        self::assertSame(['ended', self::NOW], $this->stateAndEnd(40));
        self::assertSame(44, (int) $this->connection->fetchOne('SELECT subscription FROM tl_consho_entitlement WHERE id = 70'));
        self::assertSame(44, (int) $this->connection->fetchOne('SELECT renewedBy FROM tl_consho_subscription WHERE id = 40'));
        self::assertSame(['active', 0], $this->stateAndEnd(44));
    }

    /**
     * Abo-Nachlese: a follow-up order is not the purchase.
     */
    public function testDieStornierungEinerFolgebestellungBeendetNichts(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 9, 'orderNumber' => 'WEB-00009', 'subscription' => 44, 'grandTotal' => '1.2000']);

        $this->rollback()->afterCancellation(9, self::NOW);

        self::assertSame(['active', 0], $this->stateAndEnd(44));
        self::assertSame(['active', self::NOW + 10 * self::DAY], $this->stateAndEnd(40));
    }

    public function testVorDerFreigabeNurDieSperreZurueck(): void
    {
        // Prepayment, never paid: the entitlement still hangs on the old subscription.
        $this->connection->update('tl_consho_entitlement', ['pid' => 3, 'orderItem' => 7, 'subscription' => 40, 'validUntil' => self::NOW + 10 * self::DAY], ['id' => 70]);

        $this->rollback()->afterCancellation(5, self::NOW);

        self::assertSame(40, (int) $this->connection->fetchOne('SELECT subscription FROM tl_consho_entitlement WHERE id = 70'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT renewedBy FROM tl_consho_subscription WHERE id = 40'));
    }

    /**
     * 44 has itself already been renewed by 48, which holds the
     * licence — roll nothing back, otherwise two paths would lead to it.
     */
    public function testEineSelbstVerlaengerteVerlaengerungBleibtStehen(): void
    {
        $this->connection->update('tl_consho_subscription', ['renewedBy' => 48], ['id' => 44]);
        $this->connection->update('tl_consho_entitlement', ['subscription' => 48], ['id' => 70]);

        $this->rollback()->afterCancellation(5, self::NOW);

        self::assertSame(44, (int) $this->connection->fetchOne('SELECT renewedBy FROM tl_consho_subscription WHERE id = 40'));
        self::assertSame(48, (int) $this->connection->fetchOne('SELECT subscription FROM tl_consho_entitlement WHERE id = 70'));
        // The subscription itself still ends (Abo-Nachlese).
        self::assertSame(['ended', self::NOW], $this->stateAndEnd(44));
    }

    /**
     * Abo-8, P78: an accepted withdrawal of the purchase order ends the
     * subscription of its line immediately and blocks its entitlement. A second subscription
     * of the same order, whose line is not withdrawn, continues.
     */
    public function testDerWiderrufBeendetDasAboDerZeileUndSperrtDieLizenz(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 8, 'orderNumber' => 'WEB-00008', 'grandTotal' => '12.0000']);
        $this->connection->insert('tl_consho_subscription', ['id' => 50, 'subscriptionNumber' => 'WEB-00008-1', 'firstOrder' => 8, 'orderItem' => 20, 'state' => 'active']);
        $this->connection->insert('tl_consho_subscription', ['id' => 51, 'subscriptionNumber' => 'WEB-00008-2', 'firstOrder' => 8, 'orderItem' => 21, 'state' => 'active']);
        $this->connection->insert('tl_consho_entitlement', ['id' => 80, 'pid' => 8, 'orderItem' => 20, 'subscription' => 50]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 81, 'pid' => 8, 'orderItem' => 21, 'subscription' => 51]);

        $this->rollback()->afterWithdrawal(8, [20], self::NOW);

        self::assertSame(['ended', self::NOW], $this->stateAndEnd(50));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT revoked FROM tl_consho_entitlement WHERE id = 80'));
        self::assertSame(['active', 0], $this->stateAndEnd(51), 'die andere Zeile ist nicht widerrufen');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT revoked FROM tl_consho_entitlement WHERE id = 81'));
    }

    /**
     * Abo-8, P78: the withdrawal of a renewal returns the licence to the old subscription
     * like the cancellation — it is not blocked, the old period
     * is paid.
     */
    public function testDerWiderrufEinerVerlaengerungGibtDieLizenzZurueck(): void
    {
        $this->rollback()->afterWithdrawal(5, [10], self::NOW);

        self::assertSame(['ended', self::NOW], $this->stateAndEnd(44));
        self::assertSame([40, 0], array_map('intval', array_values($this->connection->fetchAssociative('SELECT subscription, revoked FROM tl_consho_entitlement WHERE id = 70') ?: [])));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT renewedBy FROM tl_consho_subscription WHERE id = 40'));
    }

    public function testEinWiderrufOhneZeilenBeendetNichts(): void
    {
        $this->rollback()->afterWithdrawal(5, [], self::NOW);

        self::assertSame(['active', 0], $this->stateAndEnd(44));
    }

    private function rollback(): SubscriptionPurchaseCancellation
    {
        $lock = new class($this->connection) implements SubscriptionLockInterface {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function lock(int $subscriptionId): array|null
            {
                $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = ?', [$subscriptionId]);

                return false === $row ? null : $row;
            }
        };

        $balances = $this->createStub(OrderBalanceReaderInterface::class);
        $balances->method('of')->willReturnCallback(fn (int $orderId): OrderBalance => new OrderBalance(
            $this->outstanding[$orderId] ?? '0',
            '0',
            PaymentState::Open,
            DeliveryState::Complete,
            [],
            true,
        ));

        return new SubscriptionPurchaseCancellation($this->connection, new SubscriptionDelinquency($this->connection, $lock, $balances, new SubscriptionCalendar('Europe/Vienna')));
    }

    /**
     * @return array{string, int}
     */
    private function stateAndEnd(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT state, endsAt FROM tl_consho_subscription WHERE id = ?', [$id]);

        return [(string) ($row['state'] ?? ''), (int) ($row['endsAt'] ?? 0)];
    }
}

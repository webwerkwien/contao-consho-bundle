<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Digital;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Digital\PurchaseDelinquency;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * Nachlese E5a: A one-off purchase "on account" whose digital goods have
 * already been released and which stays open past due date and grace
 * period — the entitlements are suspended, with the payment they revive.
 * Like Abo-5, only without a subscription.
 *
 * Order 7: € 30, due on day 0, grace period of the payment method 10 days.
 */
final class PurchaseDelinquencyTest extends TestCase
{
    private const DAY = 86400;

    private const DUE = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_order', 'tl_consho_order_status', 'tl_consho_order_item', 'tl_consho_payment', 'tl_consho_delivery', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        // Withdrawals and the entitlements (DigitalSchema, through it).
        WithdrawalSchema::create($this->connection);

        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7', 'grandTotal' => '30.0000', 'paymentMethod' => 3, 'paymentDueAt' => self::DUE]);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'E-Book', 'quantity' => '1.0000', 'lineTotal' => '30.0000']);
        $this->connection->insert('tl_consho_entitlement', ['id' => 11, 'pid' => 7, 'orderItem' => 1]);
        // The entitlement of a subscription in the same order belongs to the subscription (Abo-5).
        $this->connection->insert('tl_consho_entitlement', ['id' => 12, 'pid' => 7, 'orderItem' => 1, 'subscription' => 4]);
    }

    /**
     * E5 mail: the order is counted, not the line. An entitlement released
     * late is suspended along with it, without the order being "suspended"
     * anew — otherwise the mail would go out a second time.
     */
    public function testEineSpaeteFreigabeIstKeineNeueRuhe(): void
    {
        $now = self::DUE + 11 * self::DAY;
        self::assertTrue($this->delinquency()->reassess(7, $now));

        $this->connection->insert('tl_consho_entitlement', ['id' => 13, 'pid' => 7, 'orderItem' => 1]);

        self::assertNull($this->delinquency()->reassess(7, $now + 60));
        self::assertSame([$now, null, $now + 60], $this->suspended(), 'die neue ruht trotzdem');
    }

    /**
     * A release only joins a suspension — it neither starts one nor lifts
     * one; that is left to the run with its mail.
     */
    public function testJoinRestNurWennDieBestellungSchonRuht(): void
    {
        $now = self::DUE + 11 * self::DAY;

        $this->delinquency()->joinRest(7, $now);
        self::assertSame([null, null], $this->suspended(), 'überfällig, aber ruht noch nicht: der Lauf entscheidet');

        $this->delinquency()->reassess(7, $now);
        $this->connection->insert('tl_consho_entitlement', ['id' => 13, 'pid' => 7, 'orderItem' => 1]);
        $this->delinquency()->joinRest(7, $now + 60);

        self::assertSame([$now, null, $now + 60], $this->suspended());
    }

    public function testInnerhalbDerNachfristRuhtNichts(): void
    {
        self::assertNull($this->delinquency()->reassess(7, self::DUE + 9 * self::DAY));
        self::assertSame([null, null], $this->suspended());
    }

    public function testNachDerNachfristRuhtNurDerEinmalkauf(): void
    {
        $now = self::DUE + 11 * self::DAY;

        self::assertTrue($this->delinquency()->reassess(7, $now));
        self::assertSame([$now, null], $this->suspended(), 'die Abo-Berechtigung bleibt der Abo-Prüfung');
        self::assertNull($this->delinquency()->reassess(7, $now + 60), 'einmal');
    }

    public function testMitDerZahlungLebtSieWiederAuf(): void
    {
        $this->delinquency()->reassess(7, self::DUE + 11 * self::DAY);
        $this->connection->insert('tl_consho_payment', ['pid' => 7, 'amount' => '30.0000']);

        self::assertFalse($this->delinquency()->reassess(7, self::DUE + 12 * self::DAY));
        self::assertSame([null, null], $this->suspended());
    }

    public function testEinStornoGibtSieFrei(): void
    {
        $this->delinquency()->reassess(7, self::DUE + 11 * self::DAY);
        $this->connection->update('tl_consho_order', ['cancelledAt' => self::DUE + 12 * self::DAY], ['id' => 7]);

        self::assertFalse($this->delinquency()->reassess(7, self::DUE + 12 * self::DAY));
    }

    public function testEineFolgebestellungGehoertDemAbo(): void
    {
        $this->connection->update('tl_consho_order', ['subscription' => 4], ['id' => 7]);

        self::assertNull($this->delinquency()->reassess(7, self::DUE + 30 * self::DAY));
    }

    public function testOhneNachfristDerZahlungsartVierzehnTage(): void
    {
        $delinquency = $this->delinquency(new PaymentMethod(3, 'invoice', 'Auf Rechnung', []));

        self::assertNull($delinquency->reassess(7, self::DUE + 13 * self::DAY));
        self::assertTrue($delinquency->reassess(7, self::DUE + 15 * self::DAY));
    }

    public function testDerLaufFindetOffeneUndRuhende(): void
    {
        self::assertSame([7 => true], $this->delinquency()->run(self::DUE + 11 * self::DAY));
        self::assertSame([], $this->delinquency()->run(self::DUE + 11 * self::DAY + 60));

        $this->connection->insert('tl_consho_payment', ['pid' => 7, 'amount' => '30.0000']);

        self::assertSame([7 => false], $this->delinquency()->run(self::DUE + 12 * self::DAY), 'die ruhende kommt zurück, obwohl nichts mehr offen ist');
    }

    /**
     * @return list<int|null>
     */
    private function suspended(): array
    {
        return array_map(
            static fn (mixed $value): int|null => null === $value ? null : (int) $value,
            $this->connection->fetchFirstColumn('SELECT suspendedAt FROM tl_consho_entitlement ORDER BY id'),
        );
    }

    private function delinquency(PaymentMethod|null $method = null): PurchaseDelinquency
    {
        $methods = $this->createStub(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturn($method ?? new PaymentMethod(3, 'invoice', 'Auf Rechnung', ['graceDays' => '10']));

        return new PurchaseDelinquency(
            $this->connection,
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            $methods,
            new SubscriptionCalendar('UTC'),
        );
    }
}

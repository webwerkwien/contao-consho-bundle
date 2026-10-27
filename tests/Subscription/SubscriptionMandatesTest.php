<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionMandates;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-4b, P93: the mandate goes to the subscriptions of the order — those it created,
 * and for a follow-up order to the one it bills. Tables from
 * the DCAs.
 */
final class SubscriptionMandatesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_order_item', 'tl_consho_subscription'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $c = $this->connection;
        $c->insert('tl_consho_order', ['id' => 90, 'orderNumber' => 'WEB-00090']);
        $c->insert('tl_consho_order', ['id' => 91, 'orderNumber' => 'WEB-00091', 'subscription' => 2]);
        $c->insert('tl_consho_order', ['id' => 92, 'orderNumber' => 'WEB-00092']);
        $c->insert('tl_consho_order_item', ['id' => 1, 'pid' => 90, 'productName' => 'Mitgliedschaft', 'lineTotal' => '1.2000']);
        $c->insert('tl_consho_order_item', ['id' => 2, 'pid' => 90, 'productName' => 'Jahreslizenz', 'lineTotal' => '120.0000']);

        $c->insert('tl_consho_subscription', ['id' => 1, 'subscriptionNumber' => 'WEB-00090-1', 'firstOrder' => 90, 'orderItem' => 1, 'billingInterval' => 1, 'termLength' => 1, 'termUnit' => 'year', 'state' => 'active']);
        $c->insert('tl_consho_subscription', ['id' => 2, 'subscriptionNumber' => 'WEB-00090-2', 'firstOrder' => 90, 'orderItem' => 2, 'billingInterval' => 0, 'termLength' => 2, 'termUnit' => 'year', 'state' => 'active']);
        // A subscription of another order.
        $c->insert('tl_consho_subscription', ['id' => 3, 'subscriptionNumber' => 'WEB-00092-1', 'firstOrder' => 92, 'orderItem' => 0, 'state' => 'active']);
    }

    public function testDieZeilenNennenNummerNamePreisRhythmusUndLaufzeit(): void
    {
        $lines = (new SubscriptionMandates($this->connection))->linesOf(90);

        self::assertSame(
            [['WEB-00090-1', 'Mitgliedschaft', '1.2000', 1, 12], ['WEB-00090-2', 'Jahreslizenz', '120.0000', 0, 24]],
            array_map(static fn ($l): array => [$l->subscriptionNumber, $l->name, $l->amount, $l->intervalMonths, $l->termMonths], $lines),
        );
        self::assertSame([], (new SubscriptionMandates($this->connection))->linesOf(999), 'eine Bestellung ohne Abo');
    }

    public function testDieFolgebestellungNenntIhrAbo(): void
    {
        self::assertSame(['WEB-00090-2'], array_map(static fn ($l): string => $l->subscriptionNumber, (new SubscriptionMandates($this->connection))->linesOf(91)));
    }

    public function testDasMandatKommtAnDieAbosDerBestellungUndNurDorthin(): void
    {
        self::assertSame(2, (new SubscriptionMandates($this->connection))->record(90, 7, 'cus_1|pm_2', 500));

        self::assertSame(
            [['1', 'cus_1|pm_2', 7, 500], ['2', 'cus_1|pm_2', 7, 500], ['3', '', 0, 0]],
            array_map(
                static fn (array $r): array => [(string) $r['id'], $r['mandate'], (int) $r['mandateMethod'], (int) $r['tstamp']],
                $this->connection->fetchAllAssociative('SELECT id, mandate, mandateMethod, tstamp FROM tl_consho_subscription ORDER BY id'),
            ),
        );
    }

    public function testEinBeendetesAboNimmtKeinesUndEineFolgebestellungNurIhres(): void
    {
        $this->connection->update('tl_consho_subscription', ['state' => 'ended'], ['id' => 1]);

        self::assertSame(1, (new SubscriptionMandates($this->connection))->record(91, 8, 'neu', 600));
        self::assertSame(['', 'neu'], $this->connection->fetchFirstColumn('SELECT mandate FROM tl_consho_subscription WHERE id IN (1, 2) ORDER BY id'));
        self::assertSame(0, (new SubscriptionMandates($this->connection))->record(90, 8, '', 600), 'ohne Mandat nichts');
    }

    /**
     * Truncated, a mandate charges nothing — so not at all, and said so.
     */
    public function testEinZuLangesMandatWirdVerweigertNichtGekuerzt(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('mandate of 256 characters'));

        self::assertSame(0, (new SubscriptionMandates($this->connection, $logger))->record(90, 7, str_repeat('x', 256), 500));
        self::assertSame(['', '', ''], $this->connection->fetchFirstColumn('SELECT mandate FROM tl_consho_subscription ORDER BY id'));
        self::assertSame(2, (new SubscriptionMandates($this->connection))->record(90, 7, str_repeat('ä', 255), 500), '255 Zeichen, nicht Bytes');
    }
}

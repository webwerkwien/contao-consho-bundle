<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Withdrawal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Withdrawal\ValueCompensation;

/**
 * Abo-8, P79: the proposal for the compensation for value of a withdrawn
 * service — line amount × days used / days of the period.
 *
 * Order 7, subscription line 1 (€ 30.00 gross, service), released on
 * day 0, period 30 days, withdrawal on day 6 + one hour: 7 days begun.
 */
final class ValueCompensationTest extends TestCase
{
    private const DAY = 86_400;

    private const START = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_order_item', 'tl_consho_subscription', 'tl_consho_entitlement', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item', 'tl_consho_delivery', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-00007', 'currency' => 'EUR', 'digitalConsentAt' => self::START - 60]);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Fitness', 'quantity' => '1.0000', 'lineTotal' => '30.0000', 'kind' => 'service', 'subscriptionRule' => 3]);
        $this->connection->insert('tl_consho_subscription', ['id' => 40, 'subscriptionNumber' => 'WEB-00007-1', 'firstOrder' => 7, 'orderItem' => 1, 'startedAt' => self::START, 'periodFrom' => self::START, 'periodUntil' => self::START + 30 * self::DAY]);
        $this->connection->insert('tl_consho_entitlement', ['pid' => 7, 'orderItem' => 1, 'subscription' => 40, 'validFrom' => self::START]);
        // The release is reported as a delivery of the line (DigitalRelease).
        $this->connection->insert('tl_consho_delivery', ['id' => 3, 'pid' => 7, 'shippedAt' => self::START, 'reportedAt' => self::START]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 3, 'orderItem' => 1, 'quantity' => '1']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 9, 'pid' => 7, 'number' => 'WEB-00007-W1', 'receivedAt' => self::START + 6 * self::DAY + 3600, 'state' => 'accepted']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 9, 'orderItem' => 1, 'quantity' => '1', 'acceptedQuantity' => '1']);
    }

    public function testZeilenbetragMalGenutzteTageDurchPeriode(): void
    {
        $proposal = (new ValueCompensation($this->connection))->proposal(9);

        // 30.00 × 7 / 30
        self::assertSame('7.00', $proposal['amount']);
        self::assertSame([['item' => 1, 'name' => 'Fitness', 'gross' => '30.00', 'days' => 7, 'periodDays' => 30, 'amount' => '7.00', 'reason' => '']], $proposal['lines']);
    }

    public function testOhneZustimmungKeinWertersatz(): void
    {
        $this->connection->update('tl_consho_order', ['digitalConsentAt' => null], ['id' => 7]);

        $proposal = (new ValueCompensation($this->connection))->proposal(9);

        self::assertSame('0.00', $proposal['amount']);
        self::assertSame('noConsent', $proposal['lines'][0]['reason']);
    }

    public function testOhneFreigabeKeinWertersatz(): void
    {
        $this->connection->delete('tl_consho_delivery', ['pid' => 7]);

        $proposal = (new ValueCompensation($this->connection))->proposal(9);

        self::assertSame('0.00', $proposal['amount']);
        self::assertSame('notReleased', $proposal['lines'][0]['reason']);
    }

    /**
     * § 357a Abs. 3 BGB: no compensation for value for digital content.
     */
    public function testDigitalerInhaltOhneWertersatz(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'digital'], ['id' => 1]);

        self::assertSame(['amount' => '0.00', 'lines' => []], (new ValueCompensation($this->connection))->proposal(9));
    }

    /**
     * A renewal only begins at the end of the old subscription's paid period:
     * until then nothing of it has been performed, even if the licence taken
     * over carries an older release date.
     */
    public function testEineSpaeterBeginnendeVerlaengerungIstNochUngenutzt(): void
    {
        $this->connection->update('tl_consho_subscription', ['startedAt' => self::START + 10 * self::DAY, 'periodFrom' => self::START + 10 * self::DAY, 'periodUntil' => self::START + 40 * self::DAY], ['id' => 40]);

        $proposal = (new ValueCompensation($this->connection))->proposal(9);

        self::assertSame('0.00', $proposal['amount']);
        self::assertSame(0, $proposal['lines'][0]['days']);
    }

    /**
     * Abo-8: After the acceptance of a withdrawn renewal the entitlement hangs
     * on the old order again (P78). The form then computes again — and must
     * say the same as at the acceptance.
     */
    public function testNachDerRueckgabeDerLizenzBleibtDieRechnung(): void
    {
        $this->connection->update('tl_consho_entitlement', ['pid' => 3, 'orderItem' => 99, 'subscription' => 39], ['pid' => 7]);

        self::assertSame('7.00', (new ValueCompensation($this->connection))->proposal(9)['amount']);
    }

    public function testNieMehrAlsDiePeriode(): void
    {
        $this->connection->update('tl_consho_withdrawal', ['receivedAt' => self::START + 45 * self::DAY], ['id' => 9]);

        self::assertSame('30.00', (new ValueCompensation($this->connection))->proposal(9)['amount']);
    }

    public function testOhneAboKeinePeriode(): void
    {
        $this->connection->delete('tl_consho_subscription', ['id' => 40]);

        $proposal = (new ValueCompensation($this->connection))->proposal(9);

        self::assertSame('0.00', $proposal['amount']);
        self::assertSame('noPeriod', $proposal['lines'][0]['reason']);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionVatIdIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Reverse-charge subscriptions whose last follow-up order found the VAT ID
 * invalid at VIES, and was taxed.
 */
final class SubscriptionVatIdIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_subscription');
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Webshop']);
    }

    public function testNurWoDieLetzteAbrechnungDieUidUngueltigFand(): void
    {
        // 1: valid at the purchase and since → nothing.
        $this->case(1, ['valid', 'valid']);
        // 2: invalid at the last billing → finding.
        $this->case(2, ['valid', 'invalid']);
        // 3: invalid once, valid again since → nothing.
        $this->case(3, ['valid', 'invalid', 'valid']);
        // 4: like 2, but ended → nothing.
        $this->case(4, ['valid', 'invalid'], 'ended');
        // 5: like 2, but bought without reverse charge → nothing to have lost.
        $this->case(5, ['valid', 'invalid'], reverseCharge: 0);
        // 6: VIES unreachable last time → no finding, the log says it.
        $this->case(6, ['valid', 'unavailable']);
        // 7: paused, invalid → finding.
        $this->case(7, ['valid', 'invalid'], 'paused');

        $issues = (new SubscriptionVatIdIssueSource($this->connection))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::SubscriptionVatIdInvalid, $issues[0]->finding);
        self::assertSame(['shop' => 'Webshop', 'numbers' => 'WEB-2-1, WEB-7-1'], $issues[0]->context);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    /**
     * @param list<string> $statuses the purchase first, then follow-ups, oldest first
     */
    private function case(int $id, array $statuses, string $state = 'active', int $reverseCharge = 1): void
    {
        foreach ($statuses as $i => $status) {
            $this->connection->insert('tl_consho_order', [
                'id' => $id * 10 + $i, 'orderNumber' => \sprintf('WEB-%d-%d', $id, $i), 'vatIdStatus' => $status,
                'subscription' => 0 === $i ? 0 : $id, 'createdAt' => 1_000 + $i,
            ]);
        }

        $this->connection->insert('tl_consho_subscription', [
            'id' => $id, 'subscriptionNumber' => \sprintf('WEB-%d-1', $id), 'shop' => 1,
            'firstOrder' => $id * 10, 'state' => $state, 'reverseCharge' => $reverseCharge,
        ]);
    }
}

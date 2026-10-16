<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cancellation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cancellation\DecisionNotSentIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * E10: eine Entscheidung, deren Mail nicht hinausging, ist eine Warnung.
 */
final class DecisionNotSentIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_shop', 'tl_consho_subscription', 'tl_consho_cancellation'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien']);
        $this->connection->insert('tl_consho_subscription', ['id' => 1, 'shop' => 1, 'subscriptionNumber' => 'WEB-1-1']);
    }

    public function testNurEntschiedenUndNichtGesendet(): void
    {
        $this->cancellation(1, 'WEB-1-1-K1', decidedAt: 100, sentAt: null);
        $this->cancellation(2, 'WEB-1-1-K2', decidedAt: 100, sentAt: 200);
        $this->cancellation(3, 'WEB-1-1-K3', decidedAt: 100, sentAt: 0);
        $this->cancellation(4, 'WEB-1-1-K4', decidedAt: null, sentAt: null);

        $issues = (new DecisionNotSentIssueSource($this->connection))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::DecisionNotSent, $issues[0]->finding);
        self::assertTrue($issues[0]->finding->isWarning());
        self::assertSame(['shop' => 'Wien', 'count' => '1', 'numbers' => 'WEB-1-1-K1'], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
    }

    private function cancellation(int $id, string $number, int|null $decidedAt, int|null $sentAt): void
    {
        $this->connection->insert('tl_consho_cancellation', ['id' => $id, 'pid' => 1, 'number' => $number, 'kind' => 'extraordinary', 'decidedAt' => $decidedAt, 'decisionSentAt' => $sentAt]);
    }
}

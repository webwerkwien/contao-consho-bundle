<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cancellation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cancellation\WaitingCancellationIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-7: waiting extraordinary cancellations as a
 * finding per shop.
 */
final class WaitingCancellationIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_subscription');
        DcaSchema::create($this->connection, 'tl_consho_cancellation');
        DcaSchema::create($this->connection, 'tl_consho_shop');

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Webshop']);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Zweiter']);
        $this->connection->insert('tl_consho_subscription', ['id' => 1, 'subscriptionNumber' => 'WEB-1-1', 'shop' => 1]);
        $this->connection->insert('tl_consho_subscription', ['id' => 2, 'subscriptionNumber' => 'WEB-2-1', 'shop' => 2]);
    }

    public function testNurWartendeAusserordentliche(): void
    {
        $received = (new \DateTimeImmutable('2026-09-30 12:00'))->getTimestamp();

        // Waiting (2 in shop 1, one of them older), decided, ordinary, another shop.
        $this->cancellation(1, 'WEB-1-1-K2', 'extraordinary', null, $received + 3600);
        $this->cancellation(1, 'WEB-1-1-K1', 'extraordinary', null, $received);
        $this->cancellation(1, 'WEB-1-1-K3', 'extraordinary', $received, $received);
        $this->cancellation(1, 'WEB-1-1-K4', 'ordinary', null, $received);
        $this->cancellation(2, 'WEB-2-1-K1', 'extraordinary', null, $received);

        $issues = (new WaitingCancellationIssueSource($this->connection))->issues();

        self::assertCount(2, $issues);
        self::assertSame(ConfigurationFinding::CancellationsWaiting, $issues[0]->finding);
        self::assertSame(['shop' => 'Webshop', 'count' => '2', 'numbers' => 'WEB-1-1-K1, WEB-1-1-K2', 'since' => '30.09.2026'], $issues[0]->context);
        self::assertSame('Zweiter', $issues[1]->context['shop']);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    public function testEinBeendetesAboWartetNicht(): void
    {
        // Abo-Nachlese: ended by cancelling the purchase (P72).
        $this->cancellation(1, 'WEB-1-1-K1', 'extraordinary', null, 1);
        $this->connection->update('tl_consho_subscription', ['state' => 'ended'], ['id' => 1]);

        self::assertSame([], (new WaitingCancellationIssueSource($this->connection))->issues());
    }

    public function testOhneWartendeNichts(): void
    {
        $this->cancellation(1, 'WEB-1-1-K1', 'ordinary', null, 1);

        self::assertSame([], (new WaitingCancellationIssueSource($this->connection))->issues());
    }

    private function cancellation(int $pid, string $number, string $kind, int|null $decidedAt, int $receivedAt): void
    {
        $this->connection->insert('tl_consho_cancellation', ['pid' => $pid, 'number' => $number, 'kind' => $kind, 'decidedAt' => $decidedAt, 'receivedAt' => $receivedAt]);
    }
}

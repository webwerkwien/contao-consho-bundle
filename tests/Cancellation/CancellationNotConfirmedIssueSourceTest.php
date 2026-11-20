<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cancellation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationNotConfirmedIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-9 (P129): a cancellation without its confirmation is a warning while
 * it is not yet effective — from any source.
 */
final class CancellationNotConfirmedIssueSourceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_shop', 'tl_consho_subscription', 'tl_consho_cancellation'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien']);
        $this->connection->insert('tl_consho_subscription', ['id' => 1, 'shop' => 1, 'subscriptionNumber' => 'WEB-1-1', 'state' => 'active']);
    }

    public function testNurUnbestaetigtUndNochNichtWirksam(): void
    {
        $this->cancellation(1, 'WEB-1-1-K1', 'operator', effectiveAt: self::NOW + 86_400, sentAt: null);
        $this->cancellation(2, 'WEB-1-1-K2', 'form', effectiveAt: null, sentAt: null);
        $this->cancellation(3, 'WEB-1-1-K3', 'form', effectiveAt: self::NOW + 86_400, sentAt: 100);
        $this->cancellation(4, 'WEB-1-1-K4', 'account', effectiveAt: self::NOW, sentAt: null);

        $issues = (new CancellationNotConfirmedIssueSource($this->connection, static fn (): int => self::NOW))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::CancellationNotConfirmed, $issues[0]->finding);
        self::assertTrue($issues[0]->finding->isWarning());
        self::assertSame(['shop' => 'Wien', 'count' => '2', 'numbers' => 'WEB-1-1-K1, WEB-1-1-K2'], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
    }

    /**
     * A rejected extraordinary cancellation never gets an end; an ended
     * subscription has nothing left to tell — neither stays a warning.
     */
    public function testAbgelehntOderAboBeendetKeinBefund(): void
    {
        $source = new CancellationNotConfirmedIssueSource($this->connection, static fn (): int => self::NOW);

        $this->cancellation(1, 'WEB-1-1-K1', 'form', effectiveAt: null, sentAt: null, decidedAt: 100);
        $rejected = \count($source->issues());

        $this->cancellation(2, 'WEB-1-1-K2', 'operator', effectiveAt: self::NOW + 86_400, sentAt: null);
        $this->connection->update('tl_consho_subscription', ['state' => 'ended'], ['id' => 1]);
        $ended = \count($source->issues());

        $this->connection->update('tl_consho_subscription', ['state' => 'active'], ['id' => 1]);
        $running = \count($source->issues());

        self::assertSame([0, 0, 1], [$rejected, $ended, $running]);
    }

    public function testAllesBestaetigtKeinBefund(): void
    {
        $this->cancellation(1, 'WEB-1-1-K1', 'operator', effectiveAt: self::NOW + 86_400, sentAt: 100);

        self::assertSame([], (new CancellationNotConfirmedIssueSource($this->connection, static fn (): int => self::NOW))->issues());
    }

    private function cancellation(int $id, string $number, string $source, int|null $effectiveAt, int|null $sentAt, int|null $decidedAt = null): void
    {
        $this->connection->insert('tl_consho_cancellation', ['id' => $id, 'pid' => 1, 'number' => $number, 'source' => $source, 'kind' => null === $decidedAt ? 'ordinary' : 'extraordinary', 'receivedAt' => $id, 'effectiveAt' => $effectiveAt, 'confirmationSentAt' => $sentAt, 'decidedAt' => $decidedAt]);
    }
}

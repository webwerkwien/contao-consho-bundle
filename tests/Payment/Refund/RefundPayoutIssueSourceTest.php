<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment\Refund;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayoutIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block Zahlung Mollie, 4.5: refused and hanging refunds on the start page.
 */
final class RefundPayoutIssueSourceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_shop', 'tl_consho_order', 'tl_consho_payment'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $this->connection->insert('tl_consho_order', ['id' => 1, 'shop' => 1, 'orderNumber' => 'B-1']);
        $this->connection->insert('tl_consho_order', ['id' => 2, 'shop' => 1, 'orderNumber' => 'B-2']);
        // A shop that was deleted: the refund is still owed.
        $this->connection->insert('tl_consho_order', ['id' => 3, 'shop' => 9, 'orderNumber' => 'B-3']);
    }

    public function testOhneOffeneErstattungenNichts(): void
    {
        $this->refund(1, 'done', self::NOW - 30 * 86400);
        $this->refund(1, 'manual', self::NOW - 30 * 86400);
        $this->refund(1, '', self::NOW - 30 * 86400);
        // Pending for a day, sent a minute ago.
        $this->refund(2, 'requested', self::NOW - 86400, 're_1');
        $this->refund(2, 'sending', self::NOW - 60);

        self::assertSame([], $this->issues());
    }

    public function testAbgelehnteJeShopMitBestellnummern(): void
    {
        $this->refund(1, 'failed', self::NOW);
        $this->refund(1, 'failed', self::NOW);
        $this->refund(2, 'failed', self::NOW);
        $this->refund(3, 'failed', self::NOW);

        self::assertEquals([
            new ConfigurationIssue(ConfigurationFinding::RefundPayoutFailed, ['count' => '3', 'numbers' => 'B-1, B-2'], 1),
            new ConfigurationIssue(ConfigurationFinding::RefundPayoutFailed, ['count' => '1', 'numbers' => 'B-3'], 0),
        ], $this->issues());
        self::assertTrue(ConfigurationFinding::RefundPayoutFailed->isWarning());
        self::assertSame(['consho_order'], ConfigurationFinding::RefundPayoutFailed->fixedIn());
    }

    public function testHaengendeOhneAntwortOderEineWocheInBearbeitung(): void
    {
        $this->refund(1, 'sending', self::NOW - RefundPayoutIssueSource::SENDING_STUCK_AFTER - 1);
        $this->refund(2, 'requested', self::NOW - RefundPayoutIssueSource::PENDING_STUCK_AFTER - 1, 're_2');

        self::assertEquals([
            new ConfigurationIssue(ConfigurationFinding::RefundPayoutStuck, ['count' => '2', 'numbers' => 'B-1, B-2'], 1),
        ], $this->issues());
        self::assertTrue(ConfigurationFinding::RefundPayoutStuck->isWarning());
    }

    public function testNichtGesendetHaengtErstWennDerCronEsNichtNachholt(): void
    {
        // Booked and not yet sent: the cron sends it within the hour.
        $this->refund(1, 'requested', self::NOW - 1800);
        self::assertSame([], $this->issues());

        // A day later the cron does not run.
        $this->refund(2, 'requested', self::NOW - RefundPayoutIssueSource::UNSENT_STUCK_AFTER - 1);
        self::assertEquals([
            new ConfigurationIssue(ConfigurationFinding::RefundPayoutStuck, ['count' => '1', 'numbers' => 'B-2'], 1),
        ], $this->issues());
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new RefundPayoutIssueSource($this->connection, static fn (): int => self::NOW))->issues();
    }

    private function refund(int $orderId, string $state, int $at, string $payoutReference = ''): void
    {
        $this->connection->insert('tl_consho_payment', [
            'pid' => $orderId,
            'tstamp' => $at,
            'receivedAt' => $at,
            'amount' => '-10.0000',
            'method' => 'refund',
            'refundOf' => '' === $state ? 0 : 5,
            'payoutState' => $state,
            'payoutReference' => $payoutReference,
        ]);
    }
}

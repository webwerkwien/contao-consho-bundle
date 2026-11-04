<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Migration\RefundRowStateMigration;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Geldflüsse E2: refund rows from before T3 (withdrawal paid back without a
 * provider) had no payout state. They become `manual`, tied to the payment
 * where the order has only one.
 */
final class RefundRowStateMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_payment');
    }

    public function testEineZahlungWirdZugeordnetMehrereNicht(): void
    {
        // Order 1: one payment (and a voucher payment, which does not count).
        $this->row(1, '50.0000', 'tr_1');
        $this->row(1, '10.0000', 'voucher:3');
        $one = $this->row(1, '-20.0000', 'W-1', 'refund');
        // Order 2: two payments — which one was paid back is unknown.
        $this->row(2, '30.0000', 'tr_2');
        $this->row(2, '30.0000', 'VORKASSE');
        $two = $this->row(2, '-10.0000', 'W-2', 'refund');
        // Already in order: untouched.
        $done = $this->row(1, '-5.0000', 'R-1', 'refund', 'requested', 1);

        $migration = new RefundRowStateMigration($this->connection);

        self::assertTrue($migration->shouldRun());
        $migration->run();

        self::assertSame([
            $one => ['manual', 1],
            $two => ['manual', 0],
            $done => ['requested', 1],
        ], $this->states([$one, $two, $done]));
        self::assertFalse($migration->shouldRun());
    }

    public function testOhneAltzeilenLaeuftSieNicht(): void
    {
        $this->row(1, '50.0000', 'tr_1');

        self::assertFalse((new RefundRowStateMigration($this->connection))->shouldRun());
    }

    /**
     * A row that names its payment already keeps it — the migration neither
     * runs for it nor moves it to another payment.
     */
    public function testEineZeileMitZahlungBleibtWieSieIst(): void
    {
        $this->row(1, '50.0000', 'tr_1');
        $this->row(1, '30.0000', 'tr_2');
        $tied = $this->row(1, '-20.0000', 'W-1', 'refund', '', 2);

        $migration = new RefundRowStateMigration($this->connection);
        self::assertFalse($migration->shouldRun());

        $loose = $this->row(1, '-5.0000', 'W-2', 'refund');
        self::assertTrue($migration->shouldRun());
        $migration->run();

        self::assertSame([$tied => ['', 2], $loose => ['manual', 0]], $this->states([$tied, $loose]));
    }

    public function testOhneTabelleLaeuftSieNicht(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertFalse((new RefundRowStateMigration($connection))->shouldRun());
    }

    private function row(int $order, string $amount, string $reference, string $method = 'Karte', string $state = '', int $refundOf = 0): int
    {
        $this->connection->insert('tl_consho_payment', ['pid' => $order, 'amount' => $amount, 'reference' => $reference, 'method' => $method, 'payoutState' => $state, 'refundOf' => $refundOf]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{string, int}>
     */
    private function states(array $ids): array
    {
        $states = [];

        foreach ($ids as $id) {
            $row = $this->connection->fetchAssociative('SELECT payoutState, refundOf FROM tl_consho_payment WHERE id = ?', [$id]) ?: [];
            $states[$id] = [(string) ($row['payoutState'] ?? ''), (int) ($row['refundOf'] ?? -1)];
        }

        return $states;
    }
}

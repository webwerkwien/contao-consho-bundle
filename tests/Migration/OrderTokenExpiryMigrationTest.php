<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Migration\OrderTokenExpiryMigration;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/** D-6, T3: alte Tokens bekommen ein Ablaufdatum, tote Widerrufs-Tokens gehen. */
class OrderTokenExpiryMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // The table as it stands — expiresAt has been there since the table
        // was created (2026-09-09), so this migration meets today's shape.
        DcaSchema::create($this->connection, 'tl_consho_order_token');
    }

    public function testOhneTabelleNichts(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertFalse((new OrderTokenExpiryMigration($connection))->shouldRun());
    }

    public function testGibtJedemTokenEinAblaufdatum(): void
    {
        $this->token('mail', 'viewOrder', 1000, null, 1);
        $this->token('redirect', 'viewOrder', 1000, null, 0);
        $this->token('download', 'download', 1000, 5000, 0);
        $this->token('open', 'viewOrder', 1000, 4600, 0);
        $this->token('old', 'withdrawal', 1000, null, 0);

        $migration = new OrderTokenExpiryMigration($this->connection);
        self::assertTrue($migration->shouldRun());

        $result = $migration->run();

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('1 mailed and 1 redirect', $result->getMessage());
        self::assertSame(
            ['download' => 5000, 'mail' => 1000 + 90 * 86400, 'open' => 4600, 'redirect' => 1000 + 86400],
            array_map('intval', $this->connection->fetchAllKeyValue('SELECT tokenHash, expiresAt FROM tl_consho_order_token ORDER BY tokenHash')),
            'gesetzte Ablaufdaten bleiben, der Widerruf ist weg',
        );
        self::assertFalse($migration->shouldRun(), 'danach nichts mehr zu tun');
    }

    private function token(string $hash, string $purpose, int $createdAt, int|null $expiresAt, int $confirmsEmail): void
    {
        $this->connection->insert('tl_consho_order_token', ['tokenHash' => $hash, 'purpose' => $purpose, 'createdAt' => $createdAt, 'expiresAt' => $expiresAt, 'confirmsEmail' => $confirmsEmail]);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Migration\OrderWithdrawalTermsMigration;

/**
 * Orders placed before the terms were frozen get them from their shop at the
 * update — and the migration runs exactly once.
 */
final class OrderWithdrawalTermsMigrationTest extends TestCase
{
    public function testFuelltBestellungenAusIhremShop(): void
    {
        $connection = $this->connection(true);
        $connection->insert('tl_consho_shop', ['id' => 1, 'customerType' => 'consumer', 'withdrawalDays' => 14, 'withdrawalGraceDays' => 7]);
        $connection->insert('tl_consho_shop', ['id' => 2, 'customerType' => 'business', 'withdrawalDays' => 0, 'withdrawalGraceDays' => 0]);
        $connection->insert('tl_consho_order', ['id' => 10, 'shop' => 1]);
        $connection->insert('tl_consho_order', ['id' => 11, 'shop' => 2]);
        $connection->insert('tl_consho_order', ['id' => 12, 'shop' => 99]);

        $migration = new OrderWithdrawalTermsMigration($connection);

        self::assertTrue($migration->shouldRun());
        $migration->run();

        self::assertSame(['consumer', 14, 7], $this->terms($connection, 10));
        self::assertSame(['business', 0, 0], $this->terms($connection, 11));
        self::assertSame([null, null, null], $this->terms($connection, 12), 'ohne Shop bleibt NULL, der Leser fällt zurück');
        self::assertFalse($migration->shouldRun(), 'danach nie wieder');
    }

    public function testOhneKundentypAmShopGiltVerbraucher(): void
    {
        $connection = $this->connection(false);
        $connection->insert('tl_consho_shop', ['id' => 1, 'withdrawalDays' => 30, 'withdrawalGraceDays' => 2]);
        $connection->insert('tl_consho_order', ['id' => 10, 'shop' => 1]);

        (new OrderWithdrawalTermsMigration($connection))->run();

        self::assertSame(['consumer', 30, 2], $this->terms($connection, 10));
    }

    public function testOhneTabelleLaeuftSieNicht(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertFalse((new OrderWithdrawalTermsMigration($connection))->shouldRun());
    }

    private function connection(bool $shopHasType): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE tl_consho_shop (id INTEGER PRIMARY KEY, '.($shopHasType ? "customerType VARCHAR(16) NOT NULL DEFAULT 'consumer', " : '').'withdrawalDays INTEGER NOT NULL DEFAULT 0, withdrawalGraceDays INTEGER NOT NULL DEFAULT 0)');
        $connection->executeStatement('CREATE TABLE tl_consho_order (id INTEGER PRIMARY KEY, shop INTEGER NOT NULL DEFAULT 0)');

        return $connection;
    }

    /**
     * @return array{string|null, int|null, int|null}
     */
    private function terms(Connection $connection, int $id): array
    {
        $row = $connection->fetchAssociative('SELECT customerType, withdrawalDays, withdrawalGraceDays FROM tl_consho_order WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return [
            $row['customerType'],
            null === $row['withdrawalDays'] ? null : (int) $row['withdrawalDays'],
            null === $row['withdrawalGraceDays'] ? null : (int) $row['withdrawalGraceDays'],
        ];
    }
}

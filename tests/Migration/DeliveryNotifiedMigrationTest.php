<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Migration\DeliveryNotifiedMigration;

/**
 * Deliveries reported before notifiedAt get 0 instead of NULL, so that they
 * do not stay in the warning for ever — and the migration runs exactly once.
 */
final class DeliveryNotifiedMigrationTest extends TestCase
{
    public function testFuegtDieSpalteHinzuUndMarkiertGemeldeteLieferungen(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE tl_consho_delivery (id INTEGER PRIMARY KEY, reportedAt INTEGER DEFAULT NULL)');
        $connection->insert('tl_consho_delivery', ['id' => 1, 'reportedAt' => 100]);
        $connection->insert('tl_consho_delivery', ['id' => 2, 'reportedAt' => null]);

        $migration = new DeliveryNotifiedMigration($connection);

        self::assertTrue($migration->shouldRun());
        $migration->run();

        self::assertSame(0, (int) $connection->fetchOne('SELECT notifiedAt FROM tl_consho_delivery WHERE id = 1'));
        self::assertNull($connection->fetchOne('SELECT notifiedAt FROM tl_consho_delivery WHERE id = 2'), 'Entwurf');
        self::assertFalse($migration->shouldRun(), 'danach nie wieder — sonst verschwände eine echte Warnung');
    }

    public function testOhneTabelleLaeuftSieNicht(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertFalse((new DeliveryNotifiedMigration($connection))->shouldRun());
    }
}

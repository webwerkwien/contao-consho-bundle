<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Migration\DecisionSentMigration;

/**
 * E10: alte Entscheidungen bekommen 0 statt NULL, damit sie nicht für immer
 * in der Warnung stehen — und die Migration läuft genau einmal.
 */
final class DecisionSentMigrationTest extends TestCase
{
    public function testFuegtDieSpalteHinzuUndMarkiertAlteEntscheidungen(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE tl_consho_cancellation (id INTEGER PRIMARY KEY, decidedAt INTEGER DEFAULT NULL)');
        $connection->insert('tl_consho_cancellation', ['id' => 1, 'decidedAt' => 100]);
        $connection->insert('tl_consho_cancellation', ['id' => 2, 'decidedAt' => null]);

        $migration = new DecisionSentMigration($connection);

        self::assertTrue($migration->shouldRun());
        $migration->run();

        self::assertSame(0, (int) $connection->fetchOne('SELECT decisionSentAt FROM tl_consho_cancellation WHERE id = 1'));
        self::assertNull($connection->fetchOne('SELECT decisionSentAt FROM tl_consho_cancellation WHERE id = 2'), 'noch nicht entschieden');
        self::assertFalse($migration->shouldRun(), 'danach nie wieder — sonst verschwände eine echte Warnung');
    }

    public function testOhneTabelleLaeuftSieNicht(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertFalse((new DecisionSentMigration($connection))->shouldRun());
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cron;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cron\OrderTokenCleanupCron;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * D-4 (K4): Abgelaufene Tokens gehen, Tokens ohne Ablauf (aus den Mails)
 * bleiben — sie sind der einzige Weg zur Bestellung.
 */
final class OrderTokenCleanupCronTest extends TestCase
{
    public function testNurAbgelaufeneTokensWerdenGeloescht(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_order_token');
        $connection->insert('tl_consho_order_token', ['id' => 1, 'tokenHash' => 'mail', 'expiresAt' => null]);
        $connection->insert('tl_consho_order_token', ['id' => 2, 'tokenHash' => 'abgelaufen', 'expiresAt' => 999]);
        $connection->insert('tl_consho_order_token', ['id' => 3, 'tokenHash' => 'gilt noch', 'expiresAt' => 2000]);
        $connection->insert('tl_consho_order_token', ['id' => 4, 'tokenHash' => 'genau jetzt', 'expiresAt' => 1000]);

        (new OrderTokenCleanupCron($connection, static fn (): int => 1000))();

        self::assertSame([1, 3, 4], array_map('intval', $connection->fetchFirstColumn('SELECT id FROM tl_consho_order_token ORDER BY id')));
    }
}

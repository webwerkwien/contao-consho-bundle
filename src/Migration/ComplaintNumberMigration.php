<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Turns every complaint note into a complaint (block Reklamation, 3.1): adds
 * `number` and `state`, numbers the complaints of each order by receipt
 * ("SHOP-00073-R1", "-R2" …) and sets "resolved" where `resolvedAt` holds a
 * date, "open" otherwise. Texts and dates stay as they are.
 *
 * ── Why it adds the columns itself ────────────────────────────────────────
 *
 * The schema update would add `number` empty and the unique key (pid, number)
 * in one step — an order with two notes would hold '' twice, and the key
 * could not be created. `contao:migrate` runs pending migrations before the
 * schema update, so the numbers are there when the key comes. The missing
 * column is the one-off criterion: after the first run it exists. A fresh
 * installation never runs this — the schema update creates the table whole.
 */
final class ComplaintNumberMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        return $schema->tablesExist(['tl_consho_complaint'])
            && !\array_key_exists('number', array_change_key_case($schema->listTableColumns('tl_consho_complaint')));
    }

    public function run(): MigrationResult
    {
        // The definitions of the DCA fields; one statement each, as SQLite wants it.
        $this->connection->executeStatement("ALTER TABLE tl_consho_complaint ADD number VARCHAR(80) DEFAULT '' NOT NULL");
        $this->connection->executeStatement("ALTER TABLE tl_consho_complaint ADD state VARCHAR(16) DEFAULT 'open' NOT NULL");

        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.pid, c.resolvedAt, o.orderNumber FROM tl_consho_complaint c
               LEFT JOIN tl_consho_order o ON o.id = c.pid
              ORDER BY c.pid, c.receivedAt, c.id',
        );

        $counts = [];

        foreach ($rows as $row) {
            $pid = (int) $row['pid'];
            $counts[$pid] = ($counts[$pid] ?? 0) + 1;
            // An order that is gone leaves its id, so the number stays unique per pid.
            $base = (string) ($row['orderNumber'] ?? '');

            $this->connection->update('tl_consho_complaint', [
                'number' => \sprintf('%s-R%d', '' === $base ? 'C'.$pid : $base, $counts[$pid]),
                'state' => (int) ($row['resolvedAt'] ?? 0) > 0 ? 'resolved' : 'open',
            ], ['id' => (int) $row['id']]);
        }

        return $this->createResult(true, \sprintf('Numbered %d complaint(s) and set their state.', \count($rows)));
    }
}

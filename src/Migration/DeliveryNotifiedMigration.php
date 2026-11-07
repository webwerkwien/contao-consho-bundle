<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Adds `tl_consho_delivery.notifiedAt` and marks every delivery reported
 * before it with 0 — "nothing recorded", neither a date nor the NULL that
 * means "the mail failed" (DeliveryNotSent).
 *
 * Whether those mails left, nobody knows any more: the report's message said
 * so at the time, the record never did. Without this, every past delivery
 * would join the warning for ever.
 *
 * The one-off criterion is the column itself, as in DecisionSentMigration:
 * it runs exactly while the column is missing, and the schema update that
 * follows finds nothing left to do. A fresh installation never runs it.
 */
final class DeliveryNotifiedMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        return $schema->tablesExist(['tl_consho_delivery'])
            && !\array_key_exists('notifiedat', array_change_key_case($schema->listTableColumns('tl_consho_delivery')));
    }

    public function run(): MigrationResult
    {
        // The definition of the DCA field: integer, unsigned, nullable.
        $this->connection->executeStatement('ALTER TABLE tl_consho_delivery ADD notifiedAt INT UNSIGNED DEFAULT NULL');
        $count = $this->connection->executeStatement('UPDATE tl_consho_delivery SET notifiedAt = 0 WHERE reportedAt IS NOT NULL');

        return $this->createResult(true, \sprintf('Added notifiedAt and marked %d reported delivery(s) from before it as unrecorded.', $count));
    }
}

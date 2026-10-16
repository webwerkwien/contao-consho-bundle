<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Adds `tl_consho_cancellation.decisionSentAt` (E10) and marks every decision
 * from before it with 0 — "nothing recorded", neither a date nor the NULL
 * that means "the mail failed" (DecisionNotSent).
 *
 * Whether those mails left, nobody knows any more: the log said so at the
 * time, the record never did. Without this, every past decision would join
 * the warning for ever.
 *
 * ── The one-off criterion is the column itself ───────────────────────────
 *
 * Unlike ConfirmationBeforeMailMigration, which had to read the run off
 * another table's column, this migration ADDS the column: it runs exactly
 * while the column is missing, and after its first run it is there. The
 * schema update that follows finds nothing left to do. So a later failed
 * mail can never be cleared by it, whatever order the steps of
 * `contao:migrate` run in. A fresh installation never runs it — there is no
 * table yet.
 */
final class DecisionSentMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        return $schema->tablesExist(['tl_consho_cancellation'])
            && !\array_key_exists('decisionsentat', array_change_key_case($schema->listTableColumns('tl_consho_cancellation')));
    }

    public function run(): MigrationResult
    {
        // The definition of the DCA field: integer, unsigned, nullable.
        $this->connection->executeStatement('ALTER TABLE tl_consho_cancellation ADD decisionSentAt INT UNSIGNED DEFAULT NULL');
        $count = $this->connection->executeStatement('UPDATE tl_consho_cancellation SET decisionSentAt = 0 WHERE decidedAt IS NOT NULL');

        return $this->createResult(true, \sprintf('Added decisionSentAt and marked %d decision(s) from before it as unrecorded.', $count));
    }
}

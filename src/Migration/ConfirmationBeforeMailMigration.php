<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Marks the orders placed before Consho sent mail (C-9) as "none due".
 *
 * `confirmationSentAt` has existed since block A and nothing ever wrote it.
 * From C-9 on, NULL means "the confirmation did not go out" and the back end
 * warns about it (ConfirmationNotSent) with "Erneut senden" beside it. Every
 * order from before would join that warning for ever — nothing was ever
 * meant to be sent for them, so the warning would be about nothing. 0 says
 * "no confirmation was due": neither a date nor the NULL that means
 * "failed". Whether the operator sends one anyway is theirs to decide; the
 * button stays.
 *
 * ── The one-off criterion ───────────────────────────────────────────────
 *
 * There is no state of the data that tells an old order from a failed new
 * one; both are NULL. What tells them apart is the SCHEMA: contao:migrate
 * runs its migrations first and the schema update after, so in the first
 * pass of the update to C-9 the column `tl_page.consho_warrantyNotice`,
 * which C-9 adds, does not exist yet. That pass is this migration's only
 * chance, and after it the column is there and it never runs again — which
 * is exactly what must happen, or it would silently clear the warning of
 * every confirmation that failed since.
 *
 * A fresh installation never runs it: its tables do not exist in the first
 * pass either.
 *
 * ⚠️ It relies on `contao:migrate` in one call, migrations → schema →
 * migrations (MigrateCommand). A run with
 * `--migrations-only` that is never followed by the schema step would leave
 * the guard open, and a later run would clear warnings it must keep.
 * docs/rollout.md says so.
 */
final class ConfirmationBeforeMailMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        if (!$schema->tablesExist(['tl_consho_order', 'tl_page'])) {
            return false;
        }

        if (\array_key_exists('consho_warrantynotice', array_change_key_case($schema->listTableColumns('tl_page')))) {
            return false;
        }

        if (!\array_key_exists('confirmationsentat', array_change_key_case($schema->listTableColumns('tl_consho_order')))) {
            return false;
        }

        return false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_order WHERE confirmationSentAt IS NULL');
    }

    public function run(): MigrationResult
    {
        $count = $this->connection->executeStatement('UPDATE tl_consho_order SET confirmationSentAt = 0 WHERE confirmationSentAt IS NULL');

        return $this->createResult(true, \sprintf('Marked %d order(s) from before C-9 as needing no confirmation mail.', $count));
    }
}

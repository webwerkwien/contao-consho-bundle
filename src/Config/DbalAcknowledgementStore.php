<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Config;

use Doctrine\DBAL\Connection;

/**
 * One small read per start page, and a write only when somebody clicks.
 *
 * Rows are never cleaned up when a finding changes: the old fingerprint simply
 * stops matching anything. That leaves a trail of what was judged and when,
 * which is worth more than a tidy table — and the table grows by one row per
 * decision, not per page view.
 */
final class DbalAcknowledgementStore implements AcknowledgementStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function fingerprints(): array
    {
        /** @var list<string> $rows */
        $rows = $this->connection->fetchFirstColumn('SELECT fingerprint FROM tl_consho_acknowledgement');

        return $rows;
    }

    public function acknowledge(ConfigurationIssue $issue, int $userId): void
    {
        // INSERT IGNORE over the unique index: two clicks in two tabs must not
        // turn into an error page for something that is already true.
        $this->connection->executeStatement(
            'INSERT IGNORE INTO tl_consho_acknowledgement (tstamp, finding, fingerprint, author)
             VALUES (:tstamp, :finding, :fingerprint, :author)',
            [
                'tstamp' => time(),
                'finding' => $issue->finding->value,
                'fingerprint' => $issue->fingerprint(),
                'author' => $userId,
            ],
        );
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Doctrine\DBAL\Connection;

/**
 * Removes recorded addresses that are older than a given age.
 *
 * The table grows by the number of products every time a pattern changes, so it
 * needs a way out — but **not an automatic one**. When an old address may stop
 * working is a decision about the shop's visitors and its backlinks, and a
 * built-in retention period is a period that surprises somebody eventually.
 */
final class RedirectPruner
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * How many rows are older than the given moment.
     */
    public function count(int $olderThan): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_consho_url_redirect WHERE createdAt < :cutoff',
            ['cutoff' => $olderThan],
        );
    }

    /**
     * @return int how many rows were removed
     */
    public function prune(int $olderThan): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM tl_consho_url_redirect WHERE createdAt < :cutoff',
            ['cutoff' => $olderThan],
        );
    }
}

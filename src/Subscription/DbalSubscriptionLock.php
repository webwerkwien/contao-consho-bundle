<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;

/**
 * FOR UPDATE written out, the way NumberRangeAllocator and the basket write
 * it (DBAL 4 has no platform method for it any more). Two runs of the cron at
 * once — a slow run overlapping the next hour, or the command beside the cron
 * — would otherwise both find the period due and bill it twice.
 */
final class DbalSubscriptionLock implements SubscriptionLockInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function lock(int $subscriptionId): array|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = ? FOR UPDATE', [$subscriptionId]);

        return false === $row ? null : $row;
    }
}

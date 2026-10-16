<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * Locks one subscription row for its billing (Abo-4). An interface because
 * the lock is `FOR UPDATE`, which SQLite does not have — the biller is tested
 * against a double, the way OrderPlacer is tested against the basket lock.
 */
interface SubscriptionLockInterface
{
    /**
     * Only meaningful inside a transaction.
     *
     * @return array<string, mixed>|null the row, null when it is gone
     */
    public function lock(int $subscriptionId): array|null;
}

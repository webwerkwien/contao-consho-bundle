<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

use Doctrine\DBAL\Connection;

/**
 * Where mail about a subscription goes: its latest order — the purchase or
 * the newest follow-up. Its address is the subscription's address (P58), its
 * language the customer's, its page the one the mail links to.
 */
class SubscriptionContact
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestOrder(int $subscriptionId): array|null
    {
        $order = $this->connection->fetchAssociative(
            'SELECT o.* FROM tl_consho_order o
               JOIN tl_consho_subscription s ON s.id = ?
              WHERE o.id = s.firstOrder OR o.subscription = s.id
              ORDER BY o.createdAt DESC, o.id DESC LIMIT 1',
            [$subscriptionId],
        );

        return false === $order ? null : $order;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * What SubscriptionDelinquency changed on one subscription (Abo-5): it came
 * to rest, or it came back. Nothing changed is null, not an instance.
 */
final class DelinquencyChange
{
    /**
     * @param list<int> $overdueOrders the orders past term and grace, oldest
     *                                 first; empty when it came back
     */
    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $subscriptionNumber,
        public readonly bool $suspended,
        public readonly array $overdueOrders = [],
    ) {
    }
}

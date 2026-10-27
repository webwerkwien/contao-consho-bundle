<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * Records the subscriptions of a freshly created order (Abo-3). An interface
 * so the purchase can be tested with a double while the dependency stays
 * required — a nullable one would fail in silence.
 */
interface SubscriptionRecorderInterface
{
    /**
     * @return list<int> the new subscriptions' ids, in line order
     *
     * @throws SubscriptionWithoutAccount
     * @throws SubscriptionRuleGoneAtPurchase
     */
    public function record(int $orderId): array;
}

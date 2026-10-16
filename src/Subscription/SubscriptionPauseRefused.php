<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * The pause cannot be announced (any more): the subscription changed since the
 * page was shown, or the length is not on offer (SubscriptionPause::options()).
 */
final class SubscriptionPauseRefused extends \RuntimeException
{
    public static function forSubscription(int $subscriptionId): self
    {
        return new self(\sprintf('Subscription %d cannot be paused like this.', $subscriptionId));
    }
}

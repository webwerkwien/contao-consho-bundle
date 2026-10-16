<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * A line renews a subscription that cannot be renewed at the moment of the
 * purchase (Abo-7b, P69/P70): renewed already — a second basket, a second
 * tab —, another customer's, another article, `suspended`, or not ending. The
 * checkout asks the same before the button; this is the answer under the
 * purchase's transaction, where two clicks cannot both win. The order fails
 * rather than producing a second owner of one licence.
 */
final class SubscriptionRenewalRefused extends \RuntimeException
{
    public static function forSubscription(int $subscriptionId): self
    {
        return new self(\sprintf('Subscription %d cannot be renewed (renewed already, not the buyer\'s, another article, suspended or not ending); no order was placed.', $subscriptionId));
    }
}

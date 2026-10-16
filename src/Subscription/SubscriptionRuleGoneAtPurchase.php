<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * The subscription rule of a line could not be read at the purchase (Abo-3),
 * although the checkout priced the line with it moments before. Like a legal
 * text that disappears in that sliver: the order fails instead of producing a
 * subscription without its terms.
 */
final class SubscriptionRuleGoneAtPurchase extends \RuntimeException
{
    public static function withId(int $ruleId): self
    {
        return new self(\sprintf('The subscription rule %d could not be read at the moment of the purchase; no order was placed.', $ruleId));
    }
}

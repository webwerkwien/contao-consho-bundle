<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

/**
 * A subscription rule a saved price row still names cannot be deleted
 * (Abo-1) — why in CatalogReferences, since CW-5 shared with the delete
 * command.
 *
 * The rule's place in a product's selection (subscriptionRules) is no reason:
 * a deleted rule simply drops out when SaleModes reads it, and the finding
 * for a sale mode without a price has nothing to say about it.
 */
final class SubscriptionRuleDeleteVoter extends CatalogInUseVoter
{
    protected function getTable(): string
    {
        return 'tl_consho_subscription_rule';
    }
}

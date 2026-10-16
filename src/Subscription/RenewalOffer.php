<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * "Lizenz verlängern" as it would go into the basket today (Abo-7b, P67):
 * the same plan and quantity as the old subscription, and the conditions
 * with the CURRENT price — "1,20 € / Monat · Mindestlaufzeit 1 Jahr · …".
 */
final class RenewalOffer
{
    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $articleId,
        public readonly int $ruleId,
        public readonly string $quantity,
        public readonly string $terms,
    ) {
    }
}

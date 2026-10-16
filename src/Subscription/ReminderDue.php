<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * A reminder SubscriptionLifecycle has marked as sent (Abo-6, P42, P43); the
 * run mails it after the commit.
 */
final class ReminderDue
{
    /**
     * @param int  $termEndsAt         the end of the current minimum term
     * @param int  $cancelUntil        the last moment a notice reaches it in time
     * @param bool $endsForLackOfPrice an open-ended rule billed otherwise than
     *                                 monthly, from before variant A: it ends
     */
    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $subscriptionNumber,
        public readonly RenewalKind $renewal,
        public readonly int $termEndsAt,
        public readonly int $cancelUntil,
        public readonly bool $endsForLackOfPrice = false,
    ) {
    }
}

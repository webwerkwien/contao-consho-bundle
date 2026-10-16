<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * One subscription rule as the shop reads it (Abo, A1). The amount is not here:
 * it lives in the price table (A2).
 */
final class SubscriptionRule
{
    /**
     * @param list<int> $groups member groups the rule is offered to; empty = everyone
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly int $termLength,
        public readonly string $termUnit,
        public readonly int $billingInterval,
        public readonly RenewalKind $renewal,
        public readonly int $noticeDays,
        public readonly int $reminderDays,
        public readonly int $graceDays,
        public readonly array $groups,
        /** Months a customer may pause per contract year; 0 = not at all (P102). */
        public readonly int $pauseMonths = 0,
    ) {
    }

    public function termMonths(): int
    {
        return SubscriptionRuleValidator::termMonths($this->termLength, $this->termUnit);
    }

    /**
     * Offered to a visitor in these groups? Read like a group price: an empty
     * list is everyone, otherwise one shared group is enough (Abo-2, P11).
     *
     * @param list<int> $visitorGroups
     */
    public function isFor(array $visitorGroups): bool
    {
        return [] === $this->groups || [] !== array_intersect($this->groups, $visitorGroups);
    }
}

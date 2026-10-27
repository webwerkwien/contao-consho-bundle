<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRuleValidator;

/**
 * The earliest possible end of an ordinary cancellation (Abo-7, P54;
 * § 312k Abs. 5 BGB: a declaration without a date counts for the earliest).
 *
 * The notice is kept when the cancellation arrives no later than `noticeDays`
 * calendar days before the end — the same deadline the reminder mail names
 * (SubscriptionLifecycle::remind()).
 *
 *  - Minimum term, notice kept: at the end of the term.
 *  - Endet: at the end of the term in any case — it ends there anyway.
 *  - Gleiche Laufzeit, notice not kept: at the end of the first following
 *    round whose deadline has not passed, rounds counted from the start (P17).
 *  - Unbefristet, notice not kept, and every cancellation in the indefinite
 *    phase: exactly receipt plus notice.
 *
 * A later requested date stands; an earlier one is moved to the earliest and
 * flagged. A requested DAY (the form asks for a date, read as 00:00) that is
 * the earliest's own day is the earliest, without the flag: "zum 15.01." when
 * the term ends on 15.01. at 10:00 is not a wish for an earlier end — and a
 * wish for a later time that day would bill another period for hours.
 * Extraordinary cancellations are not worked out here: the operator decides
 * their date (P57).
 */
class CancellationTerm
{
    public function __construct(private readonly SubscriptionCalendar $calendar)
    {
    }

    /**
     * @param array<string, mixed> $subscription
     */
    public function ordinary(array $subscription, int $receivedAt, int|null $requestedAt): CancellationDate
    {
        $earliest = $this->earliest($subscription, $receivedAt);

        if (null === $requestedAt || ($requestedAt <= $earliest && $this->calendar->sameDay($requestedAt, $earliest))) {
            return new CancellationDate($earliest, false, $earliest);
        }

        if ($requestedAt < $earliest) {
            return new CancellationDate($earliest, true, $earliest);
        }

        return new CancellationDate($requestedAt, false, $earliest);
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function earliest(array $subscription, int $receivedAt): int
    {
        $notice = (int) $subscription['noticeDays'];
        $afterNotice = $this->calendar->addDays($receivedAt, $notice);

        if ('minimum' !== (string) $subscription['phase']) {
            return $afterNotice;
        }

        $termEndsAt = (int) $subscription['minimumEndsAt'];

        if ($this->noticeKept($termEndsAt, $notice, $receivedAt)) {
            return $termEndsAt;
        }

        return match (RenewalKind::from((string) $subscription['renewal'])) {
            RenewalKind::Ends => $termEndsAt,
            // A rule from before variant A billed otherwise than monthly ends
            // with the term (SubscriptionLifecycle::openEnded()).
            RenewalKind::Indefinite => 1 === (int) $subscription['billingInterval'] ? $afterNotice : $termEndsAt,
            RenewalKind::SameTerm => $this->nextRoundEnd($subscription, $termEndsAt, $notice, $receivedAt),
        };
    }

    private function noticeKept(int $endsAt, int $notice, int $receivedAt): bool
    {
        return $receivedAt <= $this->calendar->addDays($endsAt, -$notice);
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function nextRoundEnd(array $subscription, int $termEndsAt, int $notice, int $receivedAt): int
    {
        $term = SubscriptionRuleValidator::termMonths((int) $subscription['termLength'], (string) $subscription['termUnit']);

        // From the start, like SubscriptionLifecycle::renew(), moved by the
        // months paused (P104); the bound only guards against a term of zero
        // months.
        for ($k = 1; $k <= 1000; ++$k) {
            $end = $this->calendar->boundary($subscription, $k * $term);

            if ($end > $termEndsAt && $this->noticeKept($end, $notice, $receivedAt)) {
                return $end;
            }
        }

        throw new \RuntimeException(\sprintf('Subscription %s has a term that no boundary passes.', $subscription['subscriptionNumber']));
    }
}

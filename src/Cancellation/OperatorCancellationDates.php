<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRuleValidator;

/**
 * The dates the operator may end a subscription at (Abo-9, P128).
 *
 * Only ends of a paid stretch, never a day in between — nothing is refunded
 * pro rata: in the minimum term its end, then the ends of further rounds
 * ("Gleiche Laufzeit") or the month boundaries after it ("Unbefristet" with
 * monthly payment); in the open-ended phase the period boundaries. "Endet"
 * has its one end. Every boundary is the start plus whole months, moved by
 * the months paused (SubscriptionCalendar::boundary, P104).
 *
 * During an announced or running pause nothing is paid, so nothing up to its
 * end counts; its start does where the contract may end there — not inside
 * the minimum term.
 *
 * Unlike the customer's cancellation (CancellationTerm) no date is moved:
 * the operator's notice is a matter of his terms. A date closer than the
 * shop's lead time is offered too, marked as not keeping it — the page warns
 * and asks for a confirmation.
 */
final class OperatorCancellationDates
{
    public const LIMIT = 6;

    public function __construct(private readonly SubscriptionCalendar $calendar)
    {
    }

    /**
     * @param array<string, mixed> $subscription a row of tl_consho_subscription
     *
     * @param int|null $limit at most this many dates; null: LIMIT for the page,
     *                        enough to reach the lead time otherwise
     *                        (SubscriptionPriceCheck, a lead time of months)
     *
     * @return list<array{at: int, kept: bool}> the dates after $now up to the first that keeps the lead time, at most $limit, the earliest first
     */
    public function of(array $subscription, int $leadDays, int $now, int|null $limit = null): array
    {
        $limit ??= self::LIMIT;

        $termEndsAt = 0;
        $step = max(1, (int) $subscription['billingInterval']);
        $dates = [];

        if ('minimum' === (string) $subscription['phase']) {
            $termEndsAt = (int) $subscription['minimumEndsAt'];

            if ($termEndsAt > $now) {
                $dates[] = $termEndsAt;
            }

            $step = match (RenewalKind::from((string) $subscription['renewal'])) {
                RenewalKind::SameTerm => SubscriptionRuleValidator::termMonths((int) $subscription['termLength'], (string) $subscription['termUnit']),
                // A rule from before variant A billed otherwise than monthly
                // ends with the term (SubscriptionLifecycle::openEnded()).
                RenewalKind::Indefinite => 1 === (int) $subscription['billingInterval'] ? 1 : 0,
                RenewalKind::Ends => 0,
            };
        }

        // An announced or running pause: nothing is paid from its start to its
        // end, so no date in between — nor its end — closes a paid stretch.
        // Its start does: the pause begins where a paid period ends.
        $pauseFrom = (int) ($subscription['pauseFrom'] ?? 0);
        $pauseUntil = (int) ($subscription['pauseUntil'] ?? 0);
        $paused = $pauseFrom > 0 && $pauseUntil > $now;

        if ($paused) {
            $dates = array_filter($dates, static fn (int $at): bool => $at < $pauseFrom || $at > $pauseUntil);

            // Only where the contract may end there: in the open-ended phase,
            // or where the pause starts at the end of the minimum term (it was
            // taken from its last period, so the term's end moved to the
            // pause's end). Inside the minimum term it is no end at all.
            if ($pauseFrom > $now && ('minimum' !== (string) $subscription['phase'] || (int) $subscription['minimumEndsAt'] === $pauseUntil)) {
                $dates[] = $pauseFrom;
            }
        }

        // The bound guards against a term of zero months and a broken row.
        for ($k = 1; $step > 0 && $k <= 1000 && \count($dates) < $limit; ++$k) {
            $at = $this->calendar->boundary($subscription, $k * $step);

            if ($at > $now && $at > $termEndsAt && (!$paused || $at < $pauseFrom || $at > $pauseUntil)) {
                $dates[] = $at;
            }
        }

        $dates = array_values(array_unique($dates));
        sort($dates);
        $offered = [];

        // The dates without the lead time and the first one that keeps it
        // (P128: the dates of P114) — no later one: a subscription with a known
        // end gets no reminder before a renewal, so an end years ahead would
        // let it renew silently in between.
        foreach (\array_slice($dates, 0, $limit) as $at) {
            $kept = $this->calendar->addDays($at, -$leadDays) >= $now;
            $offered[] = ['at' => $at, 'kept' => $kept];

            if ($kept) {
                break;
            }
        }

        return $offered;
    }
}

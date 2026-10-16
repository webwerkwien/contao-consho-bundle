<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;

/**
 * The end of a term and what follows it (Abo-6; A3, A8, P42–P49).
 *
 * remind(): `reminderDays` before the end of the minimum term, once per term
 * (`reminderSentAt`), for every renewal kind — before a renewal it is the
 * notice § 6 Abs. 1 Z 2 KSchG asks for, before an end the offer to buy again.
 * The mail is the caller's, after this commit.
 *
 * transition(), at the end of the minimum term:
 *
 *  - Endet: over (P44). The entitlement ends with the last period already.
 *  - Gleiche Laufzeit: the term moves on by one length, from the start (P17),
 *    price frozen; the reminder is due again for the new term (P45).
 *  - Unbefristet ("läuft weiter, jederzeit kündbar"): billed monthly from now
 *    on without an end (P46). Only a monthly plan may be one (variant A,
 *    2026-10-08: the conversion of a yearly plan into the monthly plan's price
 *    is gone, A8/P47/P73); a rule from before that is billed otherwise ends,
 *    so the transition has an answer for it.
 *
 * And, before all of that, a known end (`endsAt`) that has come ends it, in
 * whatever state (P49) — the way a cancellation from Abo-7 will arrive.
 *
 * Only when the billing has caught up with the term: a transition while a
 * period of the minimum term is still unbilled would price it anew. The next
 * run finds it billed. Everything under the subscription's lock, in a
 * transaction of its own, like the biller.
 */
class SubscriptionLifecycle
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionLockInterface $lock,
        private readonly SubscriptionCalendar $calendar,
    ) {
    }

    public function remind(int $subscriptionId, int $now): ReminderDue|null
    {
        return $this->locked(function () use ($subscriptionId, $now): ReminderDue|null {
            $row = $this->lock->lock($subscriptionId);

            if (null === $row || !self::reminderIsDue($row, $now, $this->calendar)) {
                return null;
            }

            $this->connection->update('tl_consho_subscription', ['reminderSentAt' => $now, 'tstamp' => $now], ['id' => $subscriptionId]);

            $renewal = RenewalKind::from((string) $row['renewal']);
            $termEndsAt = (int) $row['minimumEndsAt'];

            return new ReminderDue(
                $subscriptionId,
                (string) $row['subscriptionNumber'],
                $renewal,
                $termEndsAt,
                $this->calendar->addDays($termEndsAt, -(int) $row['noticeDays']),
                // A rule from before variant A: it ends (openEnded()).
                RenewalKind::Indefinite === $renewal && 1 !== (int) $row['billingInterval'],
            );
        });
    }

    /**
     * Asked again under the lock; the run narrows with SQL first.
     *
     * @param array<string, mixed> $row
     */
    public static function reminderIsDue(array $row, int $now, SubscriptionCalendar $calendar): bool
    {
        $termEndsAt = (int) $row['minimumEndsAt'];
        $days = (int) $row['reminderDays'];

        // Paused too (P106): the term moved with the pause, its end still comes.
        return \in_array((string) $row['state'], ['active', 'suspended', 'paused'], true)
            && 'minimum' === (string) $row['phase']
            && 0 === (int) $row['reminderSentAt']
            && 0 === (int) ($row['endsAt'] ?? 0)
            // Renewed already (Abo-7b, review S4): "ends on … — renew" would
            // offer what is done.
            && 0 === (int) ($row['renewedBy'] ?? 0)
            && $days > 0
            && $termEndsAt > $now
            && $calendar->addDays($termEndsAt, -$days) <= $now;
    }

    public function transition(int $subscriptionId, int $now): LifecycleChange|null
    {
        return $this->locked(function () use ($subscriptionId, $now): LifecycleChange|null {
            $row = $this->lock->lock($subscriptionId);

            if (null === $row || 'ended' === (string) $row['state']) {
                return null;
            }

            $endsAt = (int) ($row['endsAt'] ?? 0);

            // P49: a known end ends it, whatever the state.
            if ($endsAt > 0 && $endsAt <= $now) {
                return $this->end($subscriptionId, $endsAt, $now);
            }

            if (!\in_array((string) $row['state'], ['active', 'suspended', 'paused'], true)
                || 'minimum' !== (string) $row['phase']
                || (int) $row['minimumEndsAt'] > $now
                || !self::billedToTermEnd($row)
            ) {
                return null;
            }

            $termEndsAt = (int) $row['minimumEndsAt'];

            return match (RenewalKind::from((string) $row['renewal'])) {
                RenewalKind::Ends => $this->end($subscriptionId, $termEndsAt, $now),
                RenewalKind::SameTerm => $this->renew($row, $now),
                RenewalKind::Indefinite => $this->openEnded($row, $now),
            };
        });
    }

    /**
     * Every period of the minimum term billed — the last one ends with it.
     *
     * Or a pause reaches the end of the term (review Abo pausieren, B1): a
     * pause from the last period's end moved the term's end to its own, and
     * there is nothing to bill in a pause. Without this, billing waited for
     * the transition (`next < minimumEndsAt`) and the transition for billing
     * — for ever; with a yearly plan every pause lies there.
     *
     * @param array<string, mixed> $row
     */
    private static function billedToTermEnd(array $row): bool
    {
        return (int) $row['billingInterval'] < 1
            || (int) $row['periodUntil'] >= (int) $row['minimumEndsAt']
            || (int) ($row['pauseUntil'] ?? 0) >= (int) $row['minimumEndsAt'];
    }

    private function end(int $subscriptionId, int $endsAt, int $now): LifecycleChange
    {
        // The mandate goes with the contract (Michael, 2026-10-03: „ja, beim
        // Ende leeren"): an ended subscription charges nothing, and a renewal
        // is a purchase that brings its own (Abo-4b, P98).
        $this->connection->update('tl_consho_subscription', ['state' => 'ended', 'endsAt' => $endsAt, 'mandate' => '', 'mandateMethod' => 0, 'tstamp' => $now], ['id' => $subscriptionId]);

        return LifecycleChange::Ended;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renew(array $row, int $now): LifecycleChange
    {
        $term = SubscriptionRuleValidator::termMonths((int) $row['termLength'], (string) $row['termUnit']);
        $current = (int) $row['minimumEndsAt'];
        $next = $current;

        // From the start, the first boundary after the current end (P17),
        // moved by the months paused (P104).
        for ($k = 1; $k <= 100 && $next <= $current; ++$k) {
            $next = $this->calendar->boundary($row, $k * $term);
        }

        if ($next <= $current) {
            throw new \RuntimeException(\sprintf('Subscription %s has a term that no boundary passes.', $row['subscriptionNumber']));
        }

        $this->connection->update('tl_consho_subscription', ['minimumEndsAt' => $next, 'reminderSentAt' => 0, 'tstamp' => $now], ['id' => (int) $row['id']]);

        return LifecycleChange::Renewed;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function openEnded(array $row, int $now): LifecycleChange
    {
        $id = (int) $row['id'];

        // Variant A: billed otherwise than monthly, a rule from before it
        // cannot run on cancellable at any time without refunding — it ends.
        if (1 !== (int) $row['billingInterval']) {
            return $this->end($id, (int) $row['minimumEndsAt'], $now);
        }

        $this->connection->update('tl_consho_subscription', ['phase' => 'indefinite', 'tstamp' => $now], ['id' => $id]);

        return LifecycleChange::OpenEnded;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function locked(\Closure $work): mixed
    {
        $level = $this->connection->getTransactionNestingLevel();
        $this->connection->beginTransaction();

        try {
            $result = $work();
            $this->connection->commit();
        } catch (\Throwable $e) {
            while ($this->connection->getTransactionNestingLevel() > $level) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        return $result;
    }
}

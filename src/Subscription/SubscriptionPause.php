<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;

/**
 * Pausing a subscription (Abo pausieren, P101–P112).
 *
 * ── Everything moves when the pause is announced ─────────────────────────
 *
 * A pause of k months is whole periods from the end of the current one
 * (P103). Announcing it moves the subscription at once:
 *
 *  - `pausedMonths` += k: every later boundary is start + n + k months
 *    (SubscriptionCalendar::boundary(), P104) — periods, the term, the dates
 *    of notice, the end of a licence;
 *  - `nextBillingAt` = the end of the pause: the period after the current one
 *    is not billed (SubscriptionBiller starts the next period there);
 *  - `minimumEndsAt` + k months, where it still lies ahead, and the reminder
 *    of the term due again (P104).
 *
 * The cron only says what is the case: `paused` from `pauseFrom` until
 * `pauseUntil` (run()). Access needs nothing: no period is billed, so it ends
 * with the last paid one and comes back with the first after the pause
 * (P105). The arrears of Abo-5 are not touched.
 *
 * ── Who may ──────────────────────────────────────────────────────────────
 *
 * Only an active subscription billed per period (P103), with no end known, not
 * renewed, nothing paused or announced, and its period running as billed.
 * The customer within the allowance per contract year frozen from the rule
 * (P102); the operator beyond it (P101), but in whole periods, at most a year.
 */
class SubscriptionPause
{
    /** What the operator may grant at once: a year. */
    public const OPERATOR_MAX_MONTHS = 12;

    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionLockInterface $lock,
        private readonly SubscriptionCalendar $calendar,
    ) {
    }

    /**
     * The lengths in months that can be chosen now; [] when the subscription
     * cannot be paused.
     *
     * @param array<string, mixed> $row a row of tl_consho_subscription
     *
     * @return list<int>
     */
    public function options(array $row, int $now, bool $operator = false): array
    {
        $interval = (int) ($row['billingInterval'] ?? 0);
        $periodUntil = (int) ($row['periodUntil'] ?? 0);

        if ('active' !== (string) ($row['state'] ?? '')
            || $interval < 1
            || (int) ($row['endsAt'] ?? 0) > 0
            || (int) ($row['renewedBy'] ?? 0) > 0
            || $periodUntil <= $now
            // Also what keeps a second pause out: an announced one moved the
            // next billing to its end.
            || (int) ($row['nextBillingAt'] ?? 0) !== $periodUntil
            // A term that ends anyway (review S2): a pause from its last
            // period would only move the end by months nobody pays.
            || ('minimum' === (string) ($row['phase'] ?? '') && 'ends' === (string) ($row['renewal'] ?? '') && $periodUntil >= (int) ($row['minimumEndsAt'] ?? 0))
        ) {
            return [];
        }

        $max = $operator
            ? self::OPERATOR_MAX_MONTHS
            : (int) ($row['pauseMonths'] ?? 0) - $this->usedInContractYear($row, $periodUntil);

        $options = [];

        for ($months = $interval; $months <= $max; $months += $interval) {
            $options[] = $months;
        }

        return $options;
    }

    /**
     * Announces a pause from the end of the current period.
     *
     * @param string $source `account` or `operator`
     *
     * @throws SubscriptionPauseRefused
     */
    public function schedule(int $subscriptionId, int $months, string $source, int $now): PauseScheduled
    {
        return $this->inTransaction(function () use ($subscriptionId, $months, $source, $now): PauseScheduled {
            $row = $this->lock->lock($subscriptionId) ?? throw SubscriptionPauseRefused::forSubscription($subscriptionId);

            if (!\in_array($months, $this->options($row, $now, 'operator' === $source), true)) {
                throw SubscriptionPauseRefused::forSubscription($subscriptionId);
            }

            $from = (int) $row['periodUntil'];
            $until = $this->untilFor($row, $months);

            $fields = [
                'pausedMonths' => (int) $row['pausedMonths'] + $months,
                'pauseFrom' => $from,
                'pauseUntil' => $until,
                'nextBillingAt' => $until,
                'tstamp' => $now,
            ];

            // The term moves only where it still lies ahead (P104).
            $minimumEndsAt = (int) $row['minimumEndsAt'];

            if ('minimum' === (string) $row['phase'] && $minimumEndsAt >= $from) {
                $fields['minimumEndsAt'] = $this->calendar->boundary($row, $this->monthsTo($row, $minimumEndsAt) + $months);
                $fields['reminderSentAt'] = 0;
            }

            $this->connection->insert('tl_consho_subscription_pause', [
                'pid' => $subscriptionId,
                'tstamp' => $now,
                'months' => $months,
                'pauseFrom' => $from,
                'pauseUntil' => $until,
                'source' => $source,
                'requestedAt' => $now,
                'withdrawnAt' => 0,
                'previousNextBillingAt' => (int) $row['nextBillingAt'],
                'previousMinimumEndsAt' => $minimumEndsAt,
                'previousReminderSentAt' => (int) $row['reminderSentAt'],
                'previousPauseFrom' => (int) ($row['pauseFrom'] ?? 0),
                'previousPauseUntil' => (int) ($row['pauseUntil'] ?? 0),
            ]);

            $pauseId = (int) $this->connection->lastInsertId();
            $this->connection->update('tl_consho_subscription', $fields, ['id' => $subscriptionId]);

            return new PauseScheduled($pauseId, $subscriptionId, (string) $row['subscriptionNumber'], $months, $from, $until);
        });
    }

    /**
     * Where a pause of $months from the end of the current period would end —
     * for the choice on "Meine Abos" and for schedule().
     *
     * @param array<string, mixed> $row
     */
    public function untilFor(array $row, int $months): int
    {
        return $this->calendar->boundary($row, $this->monthsTo($row, (int) $row['periodUntil']) + $months);
    }

    /**
     * Takes back a pause that has not begun (P107): the subscription as it was
     * before. A running pause runs to its end.
     *
     * @return bool whether a pause was withdrawn
     */
    public function withdraw(int $subscriptionId, int $now): bool
    {
        return $this->inTransaction(function () use ($subscriptionId, $now): bool {
            $row = $this->lock->lock($subscriptionId);
            $pause = null === $row ? false : $this->connection->fetchAssociative(
                'SELECT * FROM tl_consho_subscription_pause WHERE pid = ? AND withdrawnAt = 0 AND pauseFrom > ? ORDER BY id DESC LIMIT 1',
                [$subscriptionId, $now],
            );

            // Not once an end is known (review S1): the date of notice was
            // reckoned from the term the pause had moved; putting the term
            // back would leave that end two months behind it, and a same-term
            // contract would renew before it. An ended one always has its end.
            if (null === $row || false === $pause || !self::withdrawable($row, $now)) {
                return false;
            }

            $this->connection->update('tl_consho_subscription', [
                'pausedMonths' => max(0, (int) $row['pausedMonths'] - (int) $pause['months']),
                'pauseFrom' => (int) $pause['previousPauseFrom'],
                'pauseUntil' => (int) $pause['previousPauseUntil'],
                'nextBillingAt' => (int) $pause['previousNextBillingAt'],
                'minimumEndsAt' => (int) $pause['previousMinimumEndsAt'],
                'reminderSentAt' => (int) $pause['previousReminderSentAt'],
                'tstamp' => $now,
            ], ['id' => $subscriptionId]);

            $this->connection->update('tl_consho_subscription_pause', ['withdrawnAt' => $now, 'tstamp' => $now], ['id' => (int) $pause['id']]);

            return true;
        });
    }

    /**
     * Whether a pause is announced and has not begun — what "Meine Abos" and
     * the back end show, also after a cancellation: it takes place all the
     * same (second review, S-A).
     *
     * @param array<string, mixed> $row
     */
    public static function announced(array $row, int $now): bool
    {
        return (int) ($row['pauseFrom'] ?? 0) > $now && 'ended' !== (string) ($row['state'] ?? '');
    }

    /**
     * Whether the announced pause can still be taken back (P107): not once an
     * end is known (review S1).
     *
     * @param array<string, mixed> $row
     */
    public static function withdrawable(array $row, int $now): bool
    {
        return self::announced($row, $now) && 0 === (int) ($row['endsAt'] ?? 0);
    }

    /**
     * What the cron says is the case (P106): `paused` while a pause runs,
     * `active` again after it. A subscription resting for arrears stays
     * resting — Abo-5 decides that, and sets it active once paid; the next run
     * makes it paused again if the pause still runs.
     *
     * @return array{paused: list<string>, resumed: list<string>} subscription numbers
     */
    public function run(int $now): array
    {
        $paused = array_map('strval', $this->connection->fetchFirstColumn(
            "SELECT subscriptionNumber FROM tl_consho_subscription WHERE state = 'active' AND pauseFrom > 0 AND pauseFrom <= ? AND pauseUntil > ? ORDER BY id",
            [$now, $now],
        ));
        $resumed = array_map('strval', $this->connection->fetchFirstColumn(
            "SELECT subscriptionNumber FROM tl_consho_subscription WHERE state = 'paused' AND pauseUntil <= ? ORDER BY id",
            [$now],
        ));

        // In the WHERE again: the state may have moved since the SELECT.
        $this->connection->executeStatement(
            "UPDATE tl_consho_subscription SET state = 'paused', tstamp = ? WHERE state = 'active' AND pauseFrom > 0 AND pauseFrom <= ? AND pauseUntil > ?",
            [$now, $now, $now],
        );
        $this->connection->executeStatement(
            "UPDATE tl_consho_subscription SET state = 'active', tstamp = ? WHERE state = 'paused' AND pauseUntil <= ?",
            [$now, $now],
        );

        return ['paused' => $paused, 'resumed' => $resumed];
    }

    /**
     * Months used in the contract year that contains $from (P102): pauses not
     * withdrawn that begin in it, whoever announced them. Contract years count
     * from the real start — a pause must not move the year it is counted in.
     *
     * @param array<string, mixed> $row
     */
    private function usedInContractYear(array $row, int $from): int
    {
        $start = (int) $row['startedAt'];
        $yearStart = $start;

        for ($y = 1; $y <= 100; ++$y) {
            $next = $this->calendar->addMonths($start, 12 * $y);

            if ($next > $from) {
                return (int) $this->connection->fetchOne(
                    'SELECT COALESCE(SUM(months), 0) FROM tl_consho_subscription_pause WHERE pid = ? AND withdrawnAt = 0 AND pauseFrom >= ? AND pauseFrom < ?',
                    [(int) $row['id'], $yearStart, $next],
                );
            }

            $yearStart = $next;
        }

        return 0;
    }

    /**
     * How many months from the start (beyond those paused) lead to $at —
     * the n with boundary(n) >= $at.
     *
     * @param array<string, mixed> $row
     */
    private function monthsTo(array $row, int $at): int
    {
        for ($n = 0; $n <= 1200; ++$n) {
            if ($this->calendar->boundary($row, $n) >= $at) {
                return $n;
            }
        }

        throw new \RuntimeException(\sprintf('Subscription %s has a date that no boundary reaches.', $row['subscriptionNumber'] ?? ''));
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function inTransaction(\Closure $work): mixed
    {
        // Back to exactly the level we found (SubscriptionBiller, review B1).
        $level = $this->connection->getTransactionNestingLevel();
        $this->connection->beginTransaction();

        try {
            $result = $work();
            $this->connection->commit();

            return $result;
        } catch (\Throwable $e) {
            while ($this->connection->getTransactionNestingLevel() > $level) {
                $this->connection->rollBack();
            }

            throw $e;
        }
    }
}

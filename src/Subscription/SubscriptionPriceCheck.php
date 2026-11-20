<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Cancellation\OperatorCancellationDates;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;

/**
 * What a price offer asks of a subscription (Abo-10): which subscription gets
 * one (P115), for which day (P114, P117), with how much lead time (P127), and
 * whether the plan's price has changed since (P115, P136).
 *
 * The day is one of the dates the operator may end the subscription at
 * (P128 names the dates of P114): the end of the minimum term, in the
 * open-ended phase a period boundary — the first that keeps the lead time. A
 * run an hour late still finds it: the lead time is counted from a day
 * before now. Missed by more than that, the offer is for the next such date
 * (P117) — and is made once that date's lead time is reached.
 *
 * Today's price comes the way "Lizenz verlängern" and the basket get theirs:
 * offered to the customer as they are today (their member groups), priced by
 * the basket's own source for article, plan and quantity.
 */
final class SubscriptionPriceCheck
{
    public const DEFAULT_LEAD_DAYS = 56;

    /**
     * P127: the customer keeps at least two weeks to answer before their own
     * notice runs out.
     */
    public const MIN_ANSWER_DAYS = 14;

    public const REMINDER_DAYS = 7;

    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionCalendar $calendar,
        private readonly OperatorCancellationDates $dates,
        private readonly SubscriptionOffers $offers,
        private readonly CartLinePriceSource $prices,
        private readonly ArticleTreeInterface $articles,
    ) {
    }

    /**
     * P115: running, without a known end, not renewed — a subscription that
     * ends gets no offer (P114), it is renewed at today's price.
     *
     * @param array<string, mixed> $row a row of tl_consho_subscription
     */
    public static function candidate(array $row): bool
    {
        return \in_array((string) $row['state'], ['active', 'suspended', 'paused'], true)
            && (int) ($row['endsAt'] ?? 0) < 1
            && (int) ($row['renewedBy'] ?? 0) < 1
            && self::runsOn($row);
    }

    /**
     * P127: the shop's lead time, but never less than the customer's notice
     * plus two weeks.
     *
     * @param array<string, mixed> $row a row of tl_consho_subscription
     */
    public function leadDays(array $row): int
    {
        $days = $this->connection->fetchOne('SELECT subscriptionOperatorDays FROM tl_consho_shop WHERE id = ?', [(int) $row['shop']]);
        $shop = false === $days || null === $days ? self::DEFAULT_LEAD_DAYS : (int) $days;

        return max($shop, (int) $row['noticeDays'] + self::MIN_ANSWER_DAYS);
    }

    /**
     * The day an offer made now is for — null while none is due: no date, or
     * the next date's lead time not reached yet.
     *
     * @param array<string, mixed> $row a row of tl_consho_subscription
     */
    public function effectiveDay(array $row, int $leadDays, int $now): int|null
    {
        if (!self::runsOn($row)) {
            return null;
        }

        // Boundaries are at least 28 days apart: this many reach any lead
        // time, also one beyond the six dates the operator's page offers.
        $limit = max(OperatorCancellationDates::LIMIT, intdiv($leadDays, 28) + 2);
        $dates = $this->dates->of($row, $leadDays, $this->calendar->addDays($now, -1), $limit);
        $last = [] === $dates ? null : $dates[\count($dates) - 1];

        if (null === $last || !$last['kept'] || $last['at'] <= $now) {
            return null;
        }

        return $this->calendar->addDays($last['at'], -$leadDays) <= $now ? $last['at'] : null;
    }

    /**
     * The deadline of the answer (P118): the day the customer's own notice
     * before the effective day ends — to its end, as the mail names a day.
     * Never after the effective moment itself (a notice of zero days).
     *
     * @param array<string, mixed> $row a row of tl_consho_subscription
     */
    public function deadline(array $row, int $effectiveAt): int
    {
        return self::deadlineOf($this->calendar, $effectiveAt, (int) $row['noticeDays']);
    }

    public static function deadlineOf(SubscriptionCalendar $calendar, int $effectiveAt, int $noticeDays): int
    {
        return $calendar->noticeDeadline($effectiveAt, $noticeDays);
    }

    /**
     * P118: the reminder goes from the start of the day seven days before
     * the deadline's. The lead time leaves at least fourteen days to answer
     * (P127), so this is always after the offer.
     */
    public function reminderFrom(int $deadlineAt): int
    {
        return $this->calendar->dayStart($this->calendar->addDays($deadlineAt, -self::REMINDER_DAYS));
    }

    /**
     * Today's net price of one unit for this customer, plan and quantity —
     * null where the plan is not offered to them any more or has no price:
     * then nothing changes, the subscription runs on at its frozen price
     * (P115).
     *
     * @param array<string, mixed> $row a row of tl_consho_subscription
     */
    public function currentUnitNet(array $row, int $now): string|null
    {
        $articleId = (int) $row['product'];
        $ruleId = (int) $row['rule'];
        $groups = $this->groupsOf((int) $row['member']);

        if (null === $groups || !$this->offers->allows($articleId, $ruleId, $groups)) {
            return null;
        }

        $main = $this->articles->articlesOf([$articleId])[$articleId] ?? $articleId;

        return $this->prices->forCartLine($articleId, $main, (string) $row['currency'], (string) $row['quantity'], $now, $groups, $ruleId)?->unitNet;
    }

    /**
     * P136: changed where the amounts differ at the currency's own places — a
     * difference nobody can see is none.
     */
    public static function changed(string $old, string $new, string $currency): bool
    {
        $digits = Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;

        return !BigDecimal::of($old)->toScale($digits, RoundingMode::HalfUp)->isEqualTo(BigDecimal::of($new)->toScale($digits, RoundingMode::HalfUp));
    }

    /**
     * Not "Endet", and not an open-ended rule from before variant A billed
     * otherwise than monthly: both end with the term.
     *
     * @param array<string, mixed> $row
     */
    private static function runsOn(array $row): bool
    {
        return match (RenewalKind::tryFrom((string) $row['renewal'])) {
            RenewalKind::SameTerm => true,
            RenewalKind::Indefinite => 1 === (int) $row['billingInterval'],
            default => false,
        };
    }

    /**
     * @return list<int>|null null where the account is gone
     */
    private function groupsOf(int $memberId): array|null
    {
        // GROUPS is a reserved word in MySQL 8.
        $groups = $this->connection->fetchOne(\sprintf('SELECT %s FROM tl_member WHERE id = ?', $this->connection->quoteSingleIdentifier('groups')), [$memberId]);

        if (false === $groups) {
            return null;
        }

        return array_values(array_filter(
            array_map('intval', StringUtil::deserialize(\is_string($groups) ? $groups : '', true)),
            static fn (int $id): bool => $id > 0,
        ));
    }
}

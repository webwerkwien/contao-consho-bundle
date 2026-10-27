<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * Period boundaries of a subscription (Abo-3, P17/P18).
 *
 * Every boundary is counted FROM THE START, never from the previous boundary:
 * start + n months, the day clamped to the month's end. Chaining would let a
 * start on the 31st drift to the 28th for good after February.
 *
 * Wall-clock time in the installation's time zone: a boundary keeps the time
 * of day of the purchase across a change to or from summer time.
 */
final class SubscriptionCalendar
{
    /**
     * The time zone is for tests; the service takes the installation's.
     */
    public function __construct(private readonly string|null $timezone = null)
    {
    }

    /**
     * ⚠️ A wall-clock time that does not exist on the target day (the hour
     * skipped by the change to summer time) comes out one hour later — PHP
     * moves it forward. Only a purchase between 02:00 and 03:00 is affected.
     */
    public function addMonths(int $start, int $months): int
    {
        /*
         * The zone is read HERE, not in the constructor (Abo-3):
         * Contao sets the installation's zone when the framework initialises,
         * and on the command line a command's services are built before
         * execute() does that — a zone taken at construction would be
         * php.ini's, and a cron run would move every boundary.
         */
        $zone = new \DateTimeZone($this->timezone ?? date_default_timezone_get());
        $anchor = (new \DateTimeImmutable('@'.$start))->setTimezone($zone);
        $day = (int) $anchor->format('j');

        // From the 1st, adding months never spills into the following month.
        $first = $anchor->setDate((int) $anchor->format('Y'), (int) $anchor->format('n'), 1)->modify(\sprintf('%+d months', $months));

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, (int) $first->format('t')))->getTimestamp();
    }

    /**
     * A boundary of a subscription: its start plus $months, plus every month
     * it has been paused (Abo pausieren, P104). Every "start + n months" of
     * the subscription asks HERE — periods, the term, the dates of notice and
     * the end of a licence — so a pause moves all of them alike. Added to the
     * months, not to the start: a start on the 31st must not become the 28th
     * for good because a pause began in February.
     *
     * @param array<string, mixed> $row a row of tl_consho_subscription
     */
    public function boundary(array $row, int $months): int
    {
        return $this->addMonths((int) ($row['startedAt'] ?? 0), (int) ($row['pausedMonths'] ?? 0) + $months);
    }

    /**
     * Calendar days at the same wall-clock time (Abo-5, the grace period):
     * a day across the change of time is 23 or 25 hours, not 86,400 seconds.
     * The zone is read here, for the reason given in addMonths().
     */
    public function addDays(int $at, int $days): int
    {
        $zone = new \DateTimeZone($this->timezone ?? date_default_timezone_get());

        return (new \DateTimeImmutable('@'.$at))->setTimezone($zone)->modify(\sprintf('%+d days', $days))->getTimestamp();
    }

    /**
     * 00:00 of a day typed as YYYY-MM-DD (an HTML date field, Abo-7); null for
     * anything that is not a real day.
     */
    public function startOfDay(string $day): int|null
    {
        $zone = new \DateTimeZone($this->timezone ?? date_default_timezone_get());
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone);

        return false === $date || $date->format('Y-m-d') !== $day ? null : $date->getTimestamp();
    }

    public function sameDay(int $a, int $b): bool
    {
        $zone = new \DateTimeZone($this->timezone ?? date_default_timezone_get());

        return (new \DateTimeImmutable('@'.$a))->setTimezone($zone)->format('Y-m-d') === (new \DateTimeImmutable('@'.$b))->setTimezone($zone)->format('Y-m-d');
    }
}

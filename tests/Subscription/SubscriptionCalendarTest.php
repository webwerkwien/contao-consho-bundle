<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;

/**
 * Abo-3, P17/P18: boundaries counted from the start, clamped at the end of the month,
 * at the time of day of the purchase in the installation's time zone.
 */
class SubscriptionCalendarTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function boundaries(): iterable
    {
        yield 'Start selbst' => ['2026-01-31 10:15', 0, '2026-01-31 10:15'];
        yield '31. → Februar geklemmt' => ['2026-01-31 10:15', 1, '2026-02-28 10:15'];
        yield '31. → März wieder 31. (Anker, nicht Kette)' => ['2026-01-31 10:15', 2, '2026-03-31 10:15'];
        yield '31. → April 30.' => ['2026-01-31 10:15', 3, '2026-04-30 10:15'];
        yield 'Schaltjahr' => ['2028-01-31 10:15', 1, '2028-02-29 10:15'];
        yield 'Zeitumstellung MEZ → MESZ' => ['2026-03-15 10:15', 1, '2026-04-15 10:15'];
        yield 'Zeitumstellung MESZ → MEZ' => ['2026-10-15 23:30', 1, '2026-11-15 23:30'];
        yield 'zwölf Monate' => ['2026-02-28 08:00', 12, '2027-02-28 08:00'];
        yield 'Jahreswechsel' => ['2026-11-30 12:00', 3, '2027-02-28 12:00'];
        // 02:30 does not exist on 28.03.2027 — one hour later.
        yield 'Uhrzeit in der Umstellungslücke' => ['2027-02-28 02:30', 1, '2027-03-28 03:30'];
    }

    #[DataProvider('boundaries')]
    public function testBoundaryIsStartPlusMonthsClamped(string $start, int $months, string $expected): void
    {
        $zone = new \DateTimeZone(self::ZONE);
        $calendar = new SubscriptionCalendar(self::ZONE);

        $result = $calendar->addMonths((new \DateTimeImmutable($start, $zone))->getTimestamp(), $months);

        $this->assertSame($expected, (new \DateTimeImmutable('@'.$result))->setTimezone($zone)->format('Y-m-d H:i'));
    }

    /**
     * Pausing a subscription, P104: pause months count towards the months, not towards the
     * start — a start on the 31st stays the 31st, even if the pause
     * began in February.
     */
    public function testBoundaryAddsTheMonthsPaused(): void
    {
        $zone = new \DateTimeZone(self::ZONE);
        $calendar = new SubscriptionCalendar(self::ZONE);
        $row = ['startedAt' => (new \DateTimeImmutable('2026-01-31 10:15', $zone))->getTimestamp(), 'pausedMonths' => 1];
        $local = static fn (int $t): string => (new \DateTimeImmutable('@'.$t))->setTimezone($zone)->format('Y-m-d H:i');

        self::assertSame('2026-03-31 10:15', $local($calendar->boundary($row, 1)));
        self::assertSame('2026-04-30 10:15', $local($calendar->boundary($row, 2)));
        self::assertSame('2026-02-28 10:15', $local($calendar->boundary(['startedAt' => $row['startedAt']], 1)), 'ohne Pause wie addMonths()');
    }

    public function testDefaultsToTheInstallationsTimeZone(): void
    {
        $previous = date_default_timezone_get();

        // Built while the zone is still php.ini's, as a command's service is
        // before the framework initialises (Abo-3).
        date_default_timezone_set('UTC');
        $calendar = new SubscriptionCalendar();
        date_default_timezone_set('America/New_York');

        try {
            $zone = new \DateTimeZone('America/New_York');
            $start = (new \DateTimeImmutable('2026-01-30 22:00', $zone))->getTimestamp();

            // In UTC the start is already the 31st and clamps to 28.02. 03:00
            // UTC — 27.02. in New York. In New York it is the 30th.
            $result = $calendar->addMonths($start, 1);

            $this->assertSame('2026-02-28 22:00', (new \DateTimeImmutable('@'.$result))->setTimezone($zone)->format('Y-m-d H:i'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testStartOfDayFromADateField(): void
    {
        $calendar = new SubscriptionCalendar('Europe/Vienna');
        $zone = new \DateTimeZone('Europe/Vienna');

        $this->assertSame((new \DateTimeImmutable('2027-03-28 00:00', $zone))->getTimestamp(), $calendar->startOfDay('2027-03-28'));
        $this->assertNull($calendar->startOfDay('2027-02-30'), 'no such day');
        $this->assertNull($calendar->startOfDay('28.03.2027'));
        $this->assertNull($calendar->startOfDay(''));
    }

    public function testSameDayInTheInstallationsZone(): void
    {
        $calendar = new SubscriptionCalendar('Europe/Vienna');
        $zone = new \DateTimeZone('Europe/Vienna');
        $at = static fn (string $local): int => (new \DateTimeImmutable($local, $zone))->getTimestamp();

        // 00:30 in Vienna is still the 14th in UTC, 10:00 the 15th: the same
        // day only in the installation's zone, which decides.
        $this->assertTrue($calendar->sameDay($at('2027-01-15 00:30'), $at('2027-01-15 10:00')));
        $this->assertFalse($calendar->sameDay($at('2027-01-15 00:30'), $at('2027-01-14 23:30')));
    }
}

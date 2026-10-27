<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DeliveryPeriod;

class DeliveryPeriodTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testOhneLieferzeitKeinZeitraum(): void
    {
        self::assertNull(DeliveryPeriod::of($this->at('2026-09-24 10:00'), 0, 0));
    }

    /**
     * Thursday, 24.09.2026: 2 working days → Monday, 5 → the Thursday after.
     * The weekend does not count.
     */
    public function testWerktageUeberspringenDasWochenende(): void
    {
        self::assertSame(['from' => '2026-09-28', 'to' => '2026-10-01'], DeliveryPeriod::of($this->at('2026-09-24 10:00'), 2, 5));
    }

    /**
     * The time zone of the installation decides the day: 23:30 in Vienna is
     * still the same evening in UTC, at 00:30 it is already the next day
     * — counting starts from the Viennese day.
     */
    public function testGezaehltWirdAbDemTagDerInstallation(): void
    {
        self::assertSame(['from' => '2026-09-28', 'to' => '2026-09-28'], DeliveryPeriod::of($this->at('2026-09-25 00:30'), 1, 1));
    }

    public function testNurBisErgibtEinenTagUndBisKleinerAbWirdAb(): void
    {
        self::assertSame(['from' => '2026-09-24', 'to' => '2026-09-25'], DeliveryPeriod::of($this->at('2026-09-24 10:00'), 0, 1));
        self::assertSame(['from' => '2026-09-29', 'to' => '2026-09-29'], DeliveryPeriod::of($this->at('2026-09-24 10:00'), 3, 1));
    }

    private function at(string $vienna): int
    {
        return (new \DateTimeImmutable($vienna, new \DateTimeZone('Europe/Vienna')))->getTimestamp();
    }
}

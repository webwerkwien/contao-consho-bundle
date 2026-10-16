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
     * Donnerstag, 24.09.2026: 2 Werktage → Montag, 5 → Donnerstag darauf.
     * Das Wochenende zählt nicht.
     */
    public function testWerktageUeberspringenDasWochenende(): void
    {
        self::assertSame(['from' => '2026-09-28', 'to' => '2026-10-01'], DeliveryPeriod::of($this->at('2026-09-24 10:00'), 2, 5));
    }

    /**
     * Die Zeitzone der Installation entscheidet über den Tag: 23:30 Uhr in
     * Wien ist in UTC noch derselbe Abend, um 00:30 Uhr schon der nächste Tag
     * — gezählt wird ab dem Wiener Tag.
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

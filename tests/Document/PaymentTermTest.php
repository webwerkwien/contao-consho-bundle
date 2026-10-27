<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\PaymentTerm;

class PaymentTermTest extends TestCase
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

    /**
     * Calendar days, not working days as for the delivery period: 14 days are
     * two weeks, the weekend counts.
     */
    public function testKalendertageAbDemAusstellungstag(): void
    {
        // Monday, 28.09.2026, 10:00 Vienna time.
        $issuedAt = (new \DateTimeImmutable('2026-09-28 10:00:00'))->getTimestamp();

        self::assertSame('2026-10-12', PaymentTerm::of($issuedAt, 14)['date']);
        self::assertSame('2026-09-28', PaymentTerm::of($issuedAt, 0)['date'], 'sofort fällig: heute');
    }

    /**
     * "Payable by the 12th" means: the 12th still counts. Overdue only from
     * the first second after it.
     */
    public function testDasEndeIstDieLetzteSekundeDesTages(): void
    {
        $issuedAt = (new \DateTimeImmutable('2026-09-28 10:00:00'))->getTimestamp();

        self::assertSame((new \DateTimeImmutable('2026-10-12 23:59:59'))->getTimestamp(), PaymentTerm::of($issuedAt, 14)['endsAt']);
    }

    /**
     * 23:30 Vienna time is still the same day in UTC, 00:30 no longer is.
     * Counting happens in the server's zone, like the delivery period.
     */
    public function testDerTagZaehltInDerZoneDesServers(): void
    {
        $issuedAt = (new \DateTimeImmutable('2026-09-29 00:30:00'))->getTimestamp();

        self::assertSame('2026-10-13', PaymentTerm::of($issuedAt, 14)['date']);
    }
}

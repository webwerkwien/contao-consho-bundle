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
     * Kalendertage, nicht Werktage wie beim Lieferzeitraum: 14 Tage sind zwei
     * Wochen, das Wochenende zählt mit.
     */
    public function testKalendertageAbDemAusstellungstag(): void
    {
        // Montag, 28.09.2026, 10:00 Wiener Zeit.
        $issuedAt = (new \DateTimeImmutable('2026-09-28 10:00:00'))->getTimestamp();

        self::assertSame('2026-10-12', PaymentTerm::of($issuedAt, 14)['date']);
        self::assertSame('2026-09-28', PaymentTerm::of($issuedAt, 0)['date'], 'sofort fällig: heute');
    }

    /**
     * „Zahlbar bis zum 12." heißt: Der 12. zählt noch. Überfällig erst ab
     * der ersten Sekunde danach.
     */
    public function testDasEndeIstDieLetzteSekundeDesTages(): void
    {
        $issuedAt = (new \DateTimeImmutable('2026-09-28 10:00:00'))->getTimestamp();

        self::assertSame((new \DateTimeImmutable('2026-10-12 23:59:59'))->getTimestamp(), PaymentTerm::of($issuedAt, 14)['endsAt']);
    }

    /**
     * 23:30 Wiener Zeit ist in UTC noch derselbe Tag, 00:30 schon nicht mehr.
     * Gezählt wird in der Zone des Servers, wie der Lieferzeitraum.
     */
    public function testDerTagZaehltInDerZoneDesServers(): void
    {
        $issuedAt = (new \DateTimeImmutable('2026-09-29 00:30:00'))->getTimestamp();

        self::assertSame('2026-10-13', PaymentTerm::of($issuedAt, 14)['date']);
    }
}

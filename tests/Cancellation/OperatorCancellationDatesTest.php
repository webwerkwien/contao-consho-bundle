<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cancellation;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cancellation\OperatorCancellationDates;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;

/**
 * Abo-9, P128: the dates the operator may end a subscription at.
 */
class OperatorCancellationDatesTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    public function testGleicheLaufzeitBietetDasLaufzeitendeUndDieNaechsteRundeAn(): void
    {
        $row = self::row('sameTerm', '2026-01-15 10:00', 12, '2027-01-15 10:00');

        $dates = self::dates()->of($row, 56, self::at('2026-12-01 10:00'));

        // The term's end is too close, the next round keeps the lead time —
        // and nothing after it: a known end suppresses the reminder.
        self::assertSame([
            ['at' => self::at('2027-01-15 10:00'), 'kept' => false],
            ['at' => self::at('2028-01-15 10:00'), 'kept' => true],
        ], $dates);
    }

    public function testMitVorlaufNurDasLaufzeitende(): void
    {
        $row = self::row('sameTerm', '2026-01-15 10:00', 12, '2027-01-15 10:00');

        self::assertSame([['at' => self::at('2027-01-15 10:00'), 'kept' => true]], self::dates()->of($row, 56, self::at('2026-06-01 10:00')));
    }

    /**
     * Announced pause 15.03.–15.06. in the open-ended phase: its start ends
     * the last paid period, nothing up to its end does.
     */
    public function testEineAngekuendigtePauseBietetIhrenBeginnStattTermineDarin(): void
    {
        $row = self::row('indefinite', '2026-01-15 10:00', 12, '2027-01-15 10:00');
        $row['phase'] = 'indefinite';
        $row['pausedMonths'] = 3;
        $row['pauseFrom'] = self::at('2027-03-15 10:00');
        $row['pauseUntil'] = self::at('2027-06-15 10:00');

        self::assertSame([
            ['at' => self::at('2027-03-15 10:00'), 'kept' => false],
            ['at' => self::at('2027-07-15 10:00'), 'kept' => true],
        ], self::dates()->of($row, 56, self::at('2027-03-01 10:00')));
    }

    /**
     * A running pause: its start is past, the first date is a boundary after
     * its end.
     */
    public function testEineLaufendePauseBietetIhrenBeginnNichtAn(): void
    {
        $row = self::row('indefinite', '2026-01-15 10:00', 12, '2027-01-15 10:00');
        $row['phase'] = 'indefinite';
        $row['pausedMonths'] = 3;
        $row['pauseFrom'] = self::at('2027-03-15 10:00');
        $row['pauseUntil'] = self::at('2027-06-15 10:00');

        self::assertSame([['at' => self::at('2027-07-15 10:00'), 'kept' => true]], self::dates()->of($row, 56, self::at('2027-04-01 10:00')));
    }

    /**
     * A pause inside the minimum term (quarterly, "Gleiche Laufzeit"): its
     * start is no end of the contract — the moved term's end is.
     */
    public function testEinePauseInDerMindestlaufzeitIstKeinEnde(): void
    {
        $row = self::row('sameTerm', '2026-01-15 10:00', 12, '2027-04-15 10:00', billingInterval: 3);
        $row['pausedMonths'] = 3;
        $row['pauseFrom'] = self::at('2026-07-15 10:00');
        $row['pauseUntil'] = self::at('2026-10-15 10:00');

        self::assertSame([['at' => self::at('2027-04-15 10:00'), 'kept' => true]], self::dates()->of($row, 56, self::at('2026-04-20 10:00')));
    }

    /**
     * A pause from the last period of the minimum term: the term's end moved
     * to the pause's end, the pause's start is the old end — offered.
     */
    public function testEinePauseAusDerLetztenPeriodeBietetIhrenBeginnAn(): void
    {
        $row = self::row('sameTerm', '2026-01-15 10:00', 12, '2027-04-15 10:00');
        $row['pausedMonths'] = 3;
        $row['pauseFrom'] = self::at('2027-01-15 10:00');
        $row['pauseUntil'] = self::at('2027-04-15 10:00');

        self::assertSame([['at' => self::at('2027-01-15 10:00'), 'kept' => true]], self::dates()->of($row, 56, self::at('2026-11-01 10:00')));
    }

    public function testEndetHatNurSeinLaufzeitende(): void
    {
        $row = self::row('ends', '2026-01-15 10:00', 12, '2027-01-15 10:00');

        self::assertSame([['at' => self::at('2027-01-15 10:00'), 'kept' => true]], self::dates()->of($row, 56, self::at('2026-06-01 10:00')));
    }

    public function testUnbefristetInDerMindestlaufzeitBietetDanachDieMonatsgrenzenAn(): void
    {
        $row = self::row('indefinite', '2026-01-31 10:00', 12, '2027-01-31 10:00');

        // 80 days: 31.01. and 28.02. are too close, 31.03. keeps them.
        $dates = self::dates()->of($row, 80, self::at('2026-12-20 10:00'));

        self::assertCount(3, $dates);
        self::assertSame(self::at('2027-01-31 10:00'), $dates[0]['at']);
        // From the start, not chained: 28.02., then 31.03. again.
        self::assertSame(self::at('2027-02-28 10:00'), $dates[1]['at']);
        self::assertSame(['at' => self::at('2027-03-31 10:00'), 'kept' => true], $dates[2]);
    }

    public function testEineAltregelOhneMonatlicheZahlungEndetNurAmLaufzeitende(): void
    {
        $row = self::row('indefinite', '2026-01-15 10:00', 12, '2027-01-15 10:00', billingInterval: 0);

        self::assertCount(1, self::dates()->of($row, 56, self::at('2026-06-01 10:00')));
    }

    public function testImUnbefristetenTeilDiePeriodengrenzenNachJetzt(): void
    {
        $row = self::row('indefinite', '2026-01-15 10:00', 12, '2027-01-15 10:00');
        $row['phase'] = 'indefinite';

        $dates = self::dates()->of($row, 56, self::at('2027-03-20 11:00'));

        self::assertSame(self::at('2027-04-15 10:00'), $dates[0]['at']);
        self::assertFalse($dates[0]['kept']);
        // 56 days before 15.05. 10:00 is 20.03. 10:00 — an hour too late.
        self::assertSame(self::at('2027-05-15 10:00'), $dates[1]['at']);
        self::assertFalse($dates[1]['kept']);
        self::assertTrue($dates[2]['kept']);
        self::assertCount(3, $dates);
    }

    public function testDerVorlaufReichtGenauBisZumStichtag(): void
    {
        $row = self::row('sameTerm', '2026-01-15 10:00', 12, '2027-01-15 10:00');
        $deadline = (new SubscriptionCalendar(self::ZONE))->addDays(self::at('2027-01-15 10:00'), -56);

        self::assertTrue(self::dates()->of($row, 56, $deadline)[0]['kept']);
        self::assertFalse(self::dates()->of($row, 56, $deadline + 1)[0]['kept']);
    }

    public function testEinePauseVerschiebtDieTermine(): void
    {
        $row = self::row('sameTerm', '2026-01-15 10:00', 12, '2027-03-15 10:00');
        $row['pausedMonths'] = 2;

        $dates = self::dates()->of($row, 56, self::at('2027-02-01 10:00'));

        self::assertSame(self::at('2027-03-15 10:00'), $dates[0]['at']);
        self::assertSame(self::at('2028-03-15 10:00'), $dates[1]['at']);
    }

    public function testEinVergangenesLaufzeitendeWirdNichtAngeboten(): void
    {
        // The transition has not run yet: the term ended an hour ago.
        $row = self::row('sameTerm', '2026-01-15 10:00', 12, '2027-01-15 10:00');

        self::assertSame(self::at('2028-01-15 10:00'), self::dates()->of($row, 56, self::at('2027-01-15 11:00'))[0]['at']);
    }

    private static function dates(): OperatorCancellationDates
    {
        return new OperatorCancellationDates(new SubscriptionCalendar(self::ZONE));
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $renewal, string $start, int $termMonths, string $minimumEndsAt, int $billingInterval = 1): array
    {
        return [
            'subscriptionNumber' => 'WEB-00001-1',
            'renewal' => $renewal,
            'billingInterval' => $billingInterval,
            'phase' => 'minimum',
            'startedAt' => self::at($start),
            'termLength' => $termMonths,
            'termUnit' => 'month',
            'minimumEndsAt' => self::at($minimumEndsAt),
            'noticeDays' => 30,
        ];
    }

    private static function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }
}

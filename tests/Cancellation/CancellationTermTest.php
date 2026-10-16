<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cancellation;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationTerm;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;

/**
 * Abo-7, P54: the earliest possible date of an ordinary cancellation
 * (§ 312k Abs. 5 BGB), and what becomes of a requested one.
 */
class CancellationTermTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    public function testInDerMindestlaufzeitMitReichenderFristZumEndeDerLaufzeit(): void
    {
        foreach (['ends', 'sameTerm', 'indefinite'] as $renewal) {
            $row = self::row($renewal, '2026-01-15 10:00', 12, '2027-01-15 10:00', 30);

            $date = self::term()->ordinary($row, self::at('2026-12-16 10:00'), null);

            self::assertSame(self::at('2027-01-15 10:00'), $date->effectiveAt, $renewal);
            self::assertFalse($date->earliestApplied, $renewal);
        }
    }

    public function testDieFristReichtGenauBisZumStichtagUndKeineSekundeLaenger(): void
    {
        $row = self::row('sameTerm', '2026-01-15 10:00', 12, '2027-01-15 10:00', 30);

        self::assertSame(self::at('2027-01-15 10:00'), self::term()->ordinary($row, self::at('2026-12-16 10:00'), null)->effectiveAt);
        self::assertSame(self::at('2028-01-15 10:00'), self::term()->ordinary($row, self::at('2026-12-16 10:00') + 1, null)->effectiveAt);
    }

    public function testEndetAendertNichtAuchWennDieFristNichtReicht(): void
    {
        $row = self::row('ends', '2026-01-15 10:00', 12, '2027-01-15 10:00', 30);

        self::assertSame(self::at('2027-01-15 10:00'), self::term()->ordinary($row, self::at('2027-01-10 09:00'), null)->effectiveAt);
    }

    public function testGleicheLaufzeitOhneReichendeFristZumEndeDerNaechstenRundeVomStartGezaehlt(): void
    {
        // Start on the 31st, one month: the term ends on 28.02.; the next round
        // on 31.03. counted from the start — chained, it would be 28.03.
        $row = self::row('sameTerm', '2026-01-31 10:00', 1, '2026-02-28 10:00', 7);

        self::assertSame(self::at('2026-03-31 10:00'), self::term()->ordinary($row, self::at('2026-02-25 10:00'), null)->effectiveAt);
    }

    public function testGleicheLaufzeitMitFristLaengerAlsEineRundeSpringtBisSieReicht(): void
    {
        // 60 days' notice on a one-month term: 31.03. is 60 days away only
        // until 30.01., so the next round that allows it ends on 30.04.
        $row = self::row('sameTerm', '2026-01-31 10:00', 1, '2026-02-28 10:00', 60);

        self::assertSame(self::at('2026-04-30 10:00'), self::term()->ordinary($row, self::at('2026-02-27 10:00'), null)->effectiveAt);
    }

    public function testUnbefristetOhneReichendeFristEingangPlusFrist(): void
    {
        $row = self::row('indefinite', '2026-01-15 10:00', 12, '2027-01-15 10:00', 30);

        self::assertSame(self::at('2027-02-09 09:30'), self::term()->ordinary($row, self::at('2027-01-10 09:30'), null)->effectiveAt);
    }

    /**
     * Fable-Review Variante A, S1: Eine Altregel „jederzeit kündbar" ohne
     * monatliche Zahlung endet am Laufzeitende (SubscriptionLifecycle) — die
     * späte Kündigung darf kein Ende danach versprechen.
     */
    public function testEineAltregelOhneMonatlicheZahlungEndetAmLaufzeitende(): void
    {
        $row = self::row('indefinite', '2026-01-15 10:00', 12, '2027-01-15 10:00', 30, billingInterval: 0);

        self::assertSame(self::at('2027-01-15 10:00'), self::term()->ordinary($row, self::at('2027-01-10 09:30'), null)->effectiveAt);
    }

    public function testImUnbefristetenTeilGenauEingangPlusFristInKalendertagen(): void
    {
        $row = self::row('indefinite', '2026-01-15 10:00', 12, '2027-01-15 10:00', 30);
        $row['phase'] = 'indefinite';

        // Across the change to summer time (28.03.2027): same wall-clock time.
        self::assertSame(self::at('2027-04-09 08:15'), self::term()->ordinary($row, self::at('2027-03-10 08:15'), null)->effectiveAt);
    }

    public function testEinSpatererWunschterminGilt(): void
    {
        $row = self::row('indefinite', '2026-01-15 10:00', 12, '2027-01-15 10:00', 30);
        $row['phase'] = 'indefinite';

        $date = self::term()->ordinary($row, self::at('2027-03-01 10:00'), self::at('2027-06-30 00:00'));

        self::assertSame(self::at('2027-06-30 00:00'), $date->effectiveAt);
        self::assertFalse($date->earliestApplied);
    }

    public function testEinFruehererWunschterminWirdAufDenFruehestmoeglichenGesetztUndGesagt(): void
    {
        $row = self::row('indefinite', '2026-01-15 10:00', 12, '2027-01-15 10:00', 30);
        $row['phase'] = 'indefinite';

        $date = self::term()->ordinary($row, self::at('2027-03-01 10:00'), self::at('2027-03-15 00:00'));

        self::assertSame(self::at('2027-03-31 10:00'), $date->effectiveAt);
        self::assertTrue($date->earliestApplied);
    }

    public function testDerWunschterminGenauAmFruehestmoeglichenIstKeinFruehererTermin(): void
    {
        $row = self::row('sameTerm', '2026-01-15 10:00', 12, '2027-01-15 10:00', 30);

        $date = self::term()->ordinary($row, self::at('2026-06-01 10:00'), self::at('2027-01-15 10:00'));

        self::assertSame(self::at('2027-01-15 10:00'), $date->effectiveAt);
        self::assertFalse($date->earliestApplied);
    }

    /**
     * Das Formular fragt einen Tag (00:00). „Zum 15.01." bei einem Ende am
     * 15.01. um 10:00 ist das Ende selbst — kein früherer Wunsch.
     */
    public function testDerWunschtagDesFruehestmoeglichenEndesIstDiesesEnde(): void
    {
        $row = self::row('sameTerm', '2026-01-15 10:00', 12, '2027-01-15 10:00', 30);

        $date = self::term()->ordinary($row, self::at('2026-06-01 10:00'), self::at('2027-01-15 00:00'));

        self::assertSame(self::at('2027-01-15 10:00'), $date->effectiveAt);
        self::assertFalse($date->earliestApplied);

        // Gegenprobe: der Vortag ist früher und wird gesagt.
        self::assertTrue(self::term()->ordinary($row, self::at('2026-06-01 10:00'), self::at('2027-01-14 00:00'))->earliestApplied);
    }

    public function testEinWunschterminInDerVergangenheitWirdHochgesetzt(): void
    {
        $row = self::row('sameTerm', '2026-01-15 10:00', 12, '2027-01-15 10:00', 30);

        $date = self::term()->ordinary($row, self::at('2026-06-01 10:00'), self::at('2026-05-01 10:00'));

        self::assertSame(self::at('2027-01-15 10:00'), $date->effectiveAt);
        self::assertTrue($date->earliestApplied);
    }

    private static function term(): CancellationTerm
    {
        return new CancellationTerm(new SubscriptionCalendar(self::ZONE));
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $renewal, string $start, int $termMonths, string $minimumEndsAt, int $noticeDays, int $billingInterval = 1): array
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
            'noticeDays' => $noticeDays,
        ];
    }

    private static function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }
}

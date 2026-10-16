<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Withdrawal;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WindowState;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalWindow;

/**
 * Frist und Verfügbarkeit (Spec D 6.2, W4; Abschnitt 11: an den Grenzen).
 *
 * Letzter Versand 01.10.2026 10:00 Wien, 14 Tage + 7 Tage Puffer: offen bis
 * 22.10.2026 23:59:59 Wien.
 */
final class WithdrawalWindowTest extends TestCase
{
    private \DateTimeZone $vienna;

    private int $shipped;

    protected function setUp(): void
    {
        $this->vienna = new \DateTimeZone('Europe/Vienna');
        $this->shipped = $this->at('2026-10-01 10:00:00');
    }

    public function testSchliesstAmEndeDesTagesVersandPlusFristPlusPuffer(): void
    {
        $window = $this->window();

        self::assertSame(WindowState::Open, $window->state);
        self::assertSame($this->at('2026-10-22 23:59:59'), $window->closesAt);
    }

    public function testTagVorAmUndNachDemSchluss(): void
    {
        $window = $this->window();

        self::assertTrue($window->isOpenAt($this->at('2026-10-21 12:00:00')), 'Tag vor dem Schluss');
        self::assertTrue($window->isOpenAt($this->at('2026-10-22 23:59:59')), 'am Schlusstag, letzte Sekunde');
        self::assertFalse($window->isOpenAt($this->at('2026-10-23 00:00:00')), 'Tag danach');
    }

    /**
     * Die Zeitzone der Installation, nicht UTC: Ein Versand um 00:30 Wiener
     * Zeit ist in UTC noch der Vortag — und die Frist wäre einen Tag kürzer.
     */
    public function testDerTagZaehltInDerZeitzoneDerInstallation(): void
    {
        $window = WithdrawalWindow::of(14, 0, false, true, true, $this->at('2026-10-01 00:30:00'), $this->vienna);

        self::assertSame($this->at('2026-10-15 23:59:59'), $window->closesAt);
    }

    public function testSolangeWareOffenIstBleibtEsOffenOhneDatum(): void
    {
        $window = WithdrawalWindow::of(14, 7, false, true, false, $this->shipped, $this->vienna);

        self::assertNull($window->closesAt);
        self::assertTrue($window->isOpenAt($this->at('2027-12-31 12:00:00')));
    }

    public function testNochNichtsVersandtIstOffen(): void
    {
        self::assertTrue(WithdrawalWindow::of(14, 7, false, false, false, null, $this->vienna)->isOpenAt($this->shipped));
    }

    public function testFristNullHeisstKeineFunktion(): void
    {
        $window = WithdrawalWindow::of(0, 7, false, true, true, $this->shipped, $this->vienna);

        self::assertSame(WindowState::Off, $window->state);
        self::assertFalse($window->isOpenAt($this->shipped));
    }

    /** E7: storniert, bevor etwas versandt war — der Vertrag ist schon beendet. */
    public function testStorniertOhneVersandHatKeineFunktion(): void
    {
        $window = WithdrawalWindow::of(14, 7, true, false, true, null, $this->vienna);

        self::assertSame(WindowState::Cancelled, $window->state);
        self::assertFalse($window->isOpenAt($this->shipped));
    }

    public function testPufferNullZaehltNurDieFrist(): void
    {
        self::assertSame($this->at('2026-10-15 23:59:59'), WithdrawalWindow::of(14, 0, false, true, true, $this->shipped, $this->vienna)->closesAt);
    }

    private function window(): WithdrawalWindow
    {
        return WithdrawalWindow::of(14, 7, false, true, true, $this->shipped, $this->vienna);
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, $this->vienna))->getTimestamp();
    }
}

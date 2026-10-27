<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\AgeGate;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutProblem;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities;

class AgeGateTest extends TestCase
{
    /** 2026-09-22, 00:00 UTC. */
    private const NOW = 1_790_035_200;

    #[DataProvider('hinweisStufen')]
    public function testSechsZwoelfUndVierzehnSindNurEinHinweis(int $age): void
    {
        // C19 says it explicitly: nobody asks for an ID for a board game
        // from 12. Blocking would make a shop that only carries toys
        // unable to sell.
        self::assertNull($this->check($age));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function hinweisStufen(): iterable
    {
        yield 'ohne Grenze' => [0];
        yield 'ab 6' => [6];
        yield 'ab 12' => [12];
        yield 'ab 14' => [14];
    }

    /**
     * 🔴 The default case of a freshly set-up shop: the operator adds spirits
     * and sets up neither a group nor a checking shipping method. Then the
     * item is NOT orderable — that is not an oversight, it is the answer. An
     * age that nobody can establish is not established.
     */
    #[DataProvider('gepruefteStufen')]
    public function testOhneBeideWegeIstNichtsBestellbar(int $age): void
    {
        self::assertSame(CheckoutProblem::AgeNotVerifiable, $this->check($age));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function gepruefteStufen(): iterable
    {
        yield 'ab 16' => [16];
        yield 'ab 18' => [18];
    }

    public function testEineGepruefteGruppeOeffnetDieBestellung(): void
    {
        self::assertNull($this->check(18, groups: [3], verified: [3]));
    }

    public function testEineFremdeGruppeOeffnetNichts(): void
    {
        self::assertSame(CheckoutProblem::AgeNotVerifiable, $this->check(18, groups: [2], verified: [3]));
    }

    public function testEinePruefendeVersandartOeffnetDieBestellung(): void
    {
        self::assertNull($this->check(18, shipping: new ShippingCapabilities(18, false)));
    }

    /**
     * A shipping method that checks from 16 does not carry a delivery from 18.
     * It does check an ID then, but against the wrong limit.
     */
    public function testEineZuNiedrigPruefendeVersandartReichtNicht(): void
    {
        self::assertSame(
            CheckoutProblem::AgeNotVerifiable,
            $this->check(18, shipping: new ShippingCapabilities(16, false)),
        );

        self::assertNull($this->check(16, shipping: new ShippingCapabilities(18, false)));
    }

    /**
     * **One of the two is enough.**
     *
     * The specification wrote "and/or … both set up → both apply", which could
     * also be read as "both must be fulfilled". The sentence stands here next
     * to the expectation, because an `assertNull` alone only says what the
     * code does, not what was decided.
     */
    public function testEinGepruefetesMitgliedDarfAuchOhnePruefendeVersandartBestellen(): void
    {
        self::assertNull($this->check(
            18,
            groups: [3],
            verified: [3],
            shipping: new ShippingCapabilities(0, false),
        ));
    }

    public function testEinGastDarfMitPruefenderVersandartBestellen(): void
    {
        // The other direction of the same decision: no account, no group,
        // but a delivery that wants to see the ID.
        self::assertNull($this->check(18, groups: [], verified: [3], shipping: new ShippingCapabilities(18, false)));
    }

    public function testEinGeburtsdatumWirdNurVerlangtWennDieVersandartEsMeldet(): void
    {
        self::assertNull($this->check(18, groups: [3], verified: [3], dateOfBirth: ''));

        self::assertSame(
            CheckoutProblem::DateOfBirthMissing,
            $this->check(18, groups: [3], verified: [3], shipping: new ShippingCapabilities(18, true), dateOfBirth: ''),
        );
    }

    /**
     * 🔴 If the shipping method asks for a date of birth, it also checks it.
     * "You are old enough because you are in a group" would turn the question
     * into decoration — and a buyer who is too young would get through.
     */
    public function testEineGepruefteGruppeUeberspringtDasGeburtsdatumNicht(): void
    {
        self::assertSame(
            CheckoutProblem::TooYoung,
            $this->check(
                18,
                groups: [3],
                verified: [3],
                shipping: new ShippingCapabilities(18, true),
                dateOfBirth: '2015-01-01',
            ),
        );
    }

    public function testEinAusreichendAltesGeburtsdatumGehtDurch(): void
    {
        self::assertNull($this->check(18, shipping: new ShippingCapabilities(18, true), dateOfBirth: '1980-05-17'));
    }

    /**
     * On the birthday itself one is old enough, a day before not. Calendar
     * years are counted — whoever was born on 29 February comes of age on
     * 1 March in a year without one.
     */
    public function testDerGeburtstagSelbstZaehltBereits(): void
    {
        self::assertNull($this->check(18, shipping: new ShippingCapabilities(18, true), dateOfBirth: '2008-09-22'));

        self::assertSame(
            CheckoutProblem::TooYoung,
            $this->check(18, shipping: new ShippingCapabilities(18, true), dateOfBirth: '2008-09-23'),
        );
    }

    public function testEinUnlesbaresGeburtsdatumIstKeinAltersnachweis(): void
    {
        self::assertSame(
            CheckoutProblem::TooYoung,
            $this->check(18, shipping: new ShippingCapabilities(18, true), dateOfBirth: 'irgendwas'),
        );
    }

    /**
     * 🔴 A shipping method that checks from 16 and asks for a date of birth
     * for it carries NO delivery from 18 — not even when the typed date is old
     * enough. A self-declaration is neither of the two ways from C19; it adds
     * to a way, it does not replace it.
     *
     * Before, a `return null` stood after the date check, and exactly this
     * case went through.
     */
    public function testEinPassendesGeburtsdatumOeffnetKeineHoehereStufe(): void
    {
        self::assertSame(
            CheckoutProblem::AgeNotVerifiable,
            $this->check(18, shipping: new ShippingCapabilities(16, true), dateOfBirth: '1980-05-17'),
        );
    }

    public function testEinPassendesGeburtsdatumZusammenMitEinemWegGehtDurch(): void
    {
        // The check from 18 carries the delivery from 18 — and the date is right.
        self::assertNull($this->check(18, shipping: new ShippingCapabilities(18, true), dateOfBirth: '1980-05-17'));

        // And through the group, while the method only checks from 16.
        self::assertNull($this->check(
            18,
            groups: [3],
            verified: [3],
            shipping: new ShippingCapabilities(16, true),
            dateOfBirth: '1980-05-17',
        ));
    }

    /**
     * The checkout's field follows the same answer as the check: a method
     * that asks, and a limit that is checked at all.
     */
    public function testDasFeldErscheintNurWoDieAbfrageEsBraucht(): void
    {
        $gate = new AgeGate();

        self::assertTrue($gate->asksDateOfBirth(16, new ShippingCapabilities(18, true)));
        self::assertTrue($gate->asksDateOfBirth(18, new ShippingCapabilities(0, true)));
        // 14 is a notice: nothing to check it against.
        self::assertFalse($gate->asksDateOfBirth(14, new ShippingCapabilities(18, true)));
        self::assertFalse($gate->asksDateOfBirth(18, new ShippingCapabilities(18, false)));
        self::assertFalse($gate->asksDateOfBirth(18, null));
    }

    /**
     * @param list<int> $groups
     * @param list<int> $verified
     */
    private function check(
        int $age,
        array $groups = [],
        array $verified = [],
        ShippingCapabilities|null $shipping = null,
        string $dateOfBirth = '',
    ): CheckoutProblem|null {
        return (new AgeGate())->check($age, $groups, $verified, $shipping, $dateOfBirth, self::NOW);
    }
}

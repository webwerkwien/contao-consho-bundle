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
        // C19 sagt es ausdrücklich: Für ein Brettspiel ab 12 verlangt niemand
        // einen Ausweis. Sperren würde einen Shop unverkäuflich machen, der
        // nur Spielzeug führt.
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
     * 🔴 Der Standardfall eines frisch eingerichteten Shops: Der Betreiber
     * stellt Spirituosen ein und richtet weder Gruppe noch prüfende
     * Versandart ein. Dann ist der Artikel NICHT bestellbar — das ist kein
     * Versehen, sondern die Antwort. Ein Alter, das niemand feststellen kann,
     * ist nicht festgestellt.
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
     * Eine Versandart, die ab 16 prüft, trägt keine Lieferung ab 18. Sie prüft
     * dann zwar einen Ausweis, aber gegen die falsche Grenze.
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
     * Michaels Entscheidung vom 2026-09-22 im Wortlaut: **„Einer genügt."**
     *
     * Die Spezifikation schrieb „und/oder … Beides eingerichtet → beides
     * gilt", was sich auch als „beide müssen erfüllt sein" lesen ließ. Der
     * Satz steht hier neben der Erwartung, weil ein `assertNull` allein nur
     * sagt, was der Code tut, und nicht, was jemand entschieden hat.
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
        // Die Gegenrichtung derselben Entscheidung: kein Konto, keine Gruppe,
        // aber eine Zustellung, die den Ausweis sehen will.
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
     * 🔴 Fragt die Versandart nach einem Geburtsdatum, dann prüft sie es auch.
     * „Du bist alt genug, weil du in einer Gruppe bist" würde die Frage zur
     * Verzierung machen — und ein zu junger Besteller käme durch.
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
     * Am Geburtstag selbst ist man alt genug, einen Tag davor nicht. Gezählt
     * werden Kalenderjahre — wer am 29. Februar geboren ist, wird in einem
     * Jahr ohne einen solchen am 1. März volljährig.
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
     * 🔴 Im Review vom 2026-09-22 gefunden. Eine Versandart, die ab 16 prüft
     * und dafür ein Geburtsdatum verlangt, trägt KEINE Lieferung ab 18 — auch
     * dann nicht, wenn das eingetippte Datum alt genug ist. Eine
     * Selbstauskunft ist keiner der beiden Wege aus C19; sie ergänzt einen
     * Weg, sie ersetzt ihn nicht.
     *
     * Vorher stand nach der Datumsprüfung ein `return null`, und genau dieser
     * Fall ging durch.
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
        // Die Prüfung ab 18 trägt die Lieferung ab 18 — und das Datum stimmt.
        self::assertNull($this->check(18, shipping: new ShippingCapabilities(18, true), dateOfBirth: '1980-05-17'));

        // Und über die Gruppe, während die Art nur ab 16 prüft.
        self::assertNull($this->check(
            18,
            groups: [3],
            verified: [3],
            shipping: new ShippingCapabilities(16, true),
            dateOfBirth: '1980-05-17',
        ));
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

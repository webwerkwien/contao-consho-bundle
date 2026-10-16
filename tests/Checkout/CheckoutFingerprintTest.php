<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Cart\CartLine;
use Webwerkwien\ContaoConshoBundle\Cart\CartView;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutData;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutFingerprint;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutState;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodOption;

class CheckoutFingerprintTest extends TestCase
{
    /**
     * Die Zusicherung, auf der alles andere ruht: Zweimal dasselbe gerechnet
     * ergibt dieselbe Prüfsumme. Ohne sie wäre jeder Kaufklick eine Abweichung
     * und niemand könnte bestellen.
     *
     * Das geht nur, weil `CheckoutCalculator` ohne Nebenwirkungen rechnet —
     * die Zusicherung aus C-5, die dort keinen Abnehmer hatte.
     */
    public function testDieselbenEingabenErgebenDieselbePruefsumme(): void
    {
        $fingerprints = new CheckoutFingerprint();

        self::assertSame(
            $fingerprints->of($this->state()),
            $fingerprints->of($this->state()),
        );
    }

    /**
     * 🔴 Ein geänderter Rechtstext ändert die Prüfsumme (F3, 2026-09-23).
     *
     * Der Kaufklick friert die AGB und die Widerrufsbelehrung ein, indem er
     * die Elemente NEU liest. Speichert der Betreiber zwischen Seitenaufruf
     * und Klick eine neue Fassung, hätte der Kunde v1 gelesen und v2
     * unterschrieben — mit seinem Zeitstempel daneben. Genau der Fall, für
     * den es den Schnappschuss gibt.
     */
    public function testEinGeaenderterRechtstextAendertDiePruefsumme(): void
    {
        $fingerprints = new CheckoutFingerprint();

        $vorher = $fingerprints->of($this->state(), ['terms' => 'aaa', 'withdrawal' => 'bbb']);
        $nachher = $fingerprints->of($this->state(), ['terms' => 'ccc', 'withdrawal' => 'bbb']);

        self::assertNotSame($vorher, $nachher);
    }

    public function testEinVerschwundenerRechtstextAendertDiePruefsumme(): void
    {
        // Ein Element, das zwischen Anzeige und Klick aus seinem Zeitfenster
        // fällt, ist auch eine Änderung — sonst entstünde eine Bestellung mit
        // Häkchen und ohne Text.
        $fingerprints = new CheckoutFingerprint();

        self::assertNotSame(
            $fingerprints->of($this->state(), ['terms' => 'aaa', 'withdrawal' => 'bbb']),
            $fingerprints->of($this->state(), ['terms' => 'aaa']),
        );
    }

    public function testDieReihenfolgeDerRechtstexteAendertNichts(): void
    {
        // Sonst hinge die Prüfsumme daran, in welcher Reihenfolge die Quelle
        // die Texte zurückgibt — und ein Kauf schlüge fehl, weil eine Abfrage
        // anders sortiert hat.
        $fingerprints = new CheckoutFingerprint();

        self::assertSame(
            $fingerprints->of($this->state(), ['terms' => 'aaa', 'withdrawal' => 'bbb']),
            $fingerprints->of($this->state(), ['withdrawal' => 'bbb', 'terms' => 'aaa']),
        );
    }

    /**
     * Jede einzelne Änderung, die den Kaufwillen berührt, ändert die
     * Prüfsumme. Ein Test je Feld statt eines Sammeltests: Fällt ein Feld aus
     * der Berechnung, soll genau dieser Fall rot werden und nicht „irgendeine"
     * Abweichung.
     */
    #[DataProvider('aenderungen')]
    public function testJedeAenderungAendertDiePruefsumme(CheckoutState $geaendert): void
    {
        $fingerprints = new CheckoutFingerprint();

        self::assertNotSame($fingerprints->of($this->state()), $fingerprints->of($geaendert));
    }

    /**
     * @return iterable<string, array{CheckoutState}>
     */
    public static function aenderungen(): iterable
    {
        yield 'Menge' => [self::build(quantity: '2')];
        yield 'Zeilenbetrag' => [self::build(lineGross: '13.2000')];
        yield 'Steuerklasse' => [self::build(taxClassId: 2)];
        yield 'anderer Artikel' => [self::build(articleId: 77)];
        yield 'Gesamtbetrag' => [self::build(grossTotal: '99.0000')];
        yield 'Nettobetrag' => [self::build(netTotal: '99.0000')];
        yield 'Währung' => [self::build(currency: 'CHF')];
        yield 'Reverse Charge' => [self::build(reverseCharge: true)];
        yield 'Mindestalter' => [self::build(minimumAge: 18)];
        yield 'Versand nötig' => [self::build(needsShipping: false)];
        yield 'andere Versandart' => [self::build(shipping: new MethodOption(9, 'Paket', true, '4.1250', '4.9500'))];
        yield 'Versandart weg' => [self::build(shipping: null)];
        yield 'Versandbetrag' => [self::build(shipping: new MethodOption(4, 'Paket', true, '8.2500', '9.9000'))];
        yield 'andere Zahlungsart' => [self::build(payment: new MethodOption(8, 'Vorkasse', true))];
        yield 'Zahlungsart weg' => [self::build(payment: null)];
        yield 'Rechnungsadresse' => [self::build(billingCity: 'Graz')];
        yield 'Lieferadresse getrennt' => [self::build(separateShipping: true)];
        yield 'UID' => [self::build(vatId: 'ATU12345678')];
        yield 'Geburtsdatum' => [self::build(dateOfBirth: '1990-01-01')];
        // Abo-2: dasselbe Geld, ein anderer Vertrag — Monatsabo statt Einmalkauf.
        yield 'Modell' => [self::build(subscriptionRule: 5)];
        // Fable-Review Abo-2, L2: Der Kunde las den Satz zu digitalen Inhalten,
        // inzwischen steht das Produkt auf „Dienstleistung" — die Bestellung
        // friert die neue Art ein und die Bestätigung nennt einen Satz, den er
        // nie gesehen hat.
        yield 'Widerrufsart' => [self::build(withdrawalKinds: ['service'])];
    }

    public function testEineVerlaengerungIstEinAndererVertragAlsEinNeuesAbo(): void
    {
        // Abo-7b, Review L1: dasselbe Modell, derselbe Betrag — einmal neu,
        // einmal als Verlängerung von Abo 31. Gegen das Modell verglichen,
        // nicht gegen den Einmalkauf, sonst unterschiede schon die Regel.
        $fingerprints = new CheckoutFingerprint();

        self::assertNotSame($fingerprints->of(self::build(subscriptionRule: 5)), $fingerprints->of(self::build(subscriptionRule: 5, renews: 31)));
        self::assertNotSame($fingerprints->of(self::build(subscriptionRule: 5, renews: 31)), $fingerprints->of(self::build(subscriptionRule: 5, renews: 32)));
        self::assertSame($fingerprints->of(self::build(subscriptionRule: 5)), $fingerprints->of(self::build(subscriptionRule: 5, renews: 0)), 'ohne Verlängerung wie vor Abo-7b');
    }

    public function testOhneDigitaleZeilenBleibtDiePruefsummeWieVorAbo2(): void
    {
        // Leer schreibt nichts dazu: Eine Kasse, die beim Deploy offen ist,
        // wird nicht abgelehnt.
        $fingerprints = new CheckoutFingerprint();

        self::assertSame($fingerprints->of(self::build()), $fingerprints->of(self::build(withdrawalKinds: [])));
        self::assertNotSame($fingerprints->of(self::build(withdrawalKinds: ['content'])), $fingerprints->of(self::build(withdrawalKinds: ['content', 'service'])));
    }

    /**
     * 🔴 Der Zeitstempel der VIES-Abfrage darf die Prüfsumme NICHT ändern.
     *
     * Eine Prüfung, die abgelaufen ist und im Hintergrund neu lief, während
     * der Kunde die Angaben las, ist dieselbe Vereinbarung. Stünde sie mit
     * drin, wäre der Kauf für jeden UID-Kunden ein Glücksspiel gegen die Uhr.
     */
    public function testDerZeitpunktDerUidPruefungAendertNichts(): void
    {
        $fingerprints = new CheckoutFingerprint();

        $frueh = self::build(vatCheck: new VatIdCheckResult(VatIdStatus::Valid, 1_700_000_000, 'Beispiel GmbH', 'REF-1'));
        $spaet = self::build(vatCheck: new VatIdCheckResult(VatIdStatus::Valid, 1_800_000_000, 'Beispiel GmbH', 'REF-2'));

        self::assertSame($fingerprints->of($frueh), $fingerprints->of($spaet));
    }

    /**
     * 🔴 Und das AGB-Häkchen ebenso wenig: Es steht auf genau dem Formular,
     * das die Prüfsumme trägt. Zählte es mit, machte jedes Häkchen die
     * Prüfsumme ungültig, mit der es abgeschickt wurde — der Kauf wäre nie
     * möglich, und zwar nur bei Shops, die das Häkchen verlangen.
     */
    public function testDasAgbHaeckchenAendertNichts(): void
    {
        $fingerprints = new CheckoutFingerprint();

        self::assertSame(
            $fingerprints->of(self::build(termsAcceptedAt: 0)),
            $fingerprints->of(self::build(termsAcceptedAt: 1_700_000_000)),
        );
    }

    /**
     * Ein korrigierter Tippfehler im Titel einer Versandart ist keine
     * Änderung an der Abmachung. Betrag und ID entscheiden — die stehen in
     * der Prüfsumme, der Anzeigetext nicht.
     */
    public function testEinGeaenderterTitelAendertNichts(): void
    {
        $fingerprints = new CheckoutFingerprint();

        self::assertSame(
            $fingerprints->of(self::build(shipping: new MethodOption(4, 'Paket', true, '4.1250', '4.9500'))),
            $fingerprints->of(self::build(shipping: new MethodOption(4, 'Paket versichert', true, '4.1250', '4.9500'))),
        );
    }

    public function testEineLeerePruefsummePasstNie(): void
    {
        // Ein Formular ohne das Feld — ein altes Template, ein Bot, ein
        // abgeschnittener POST. hash_equals('…', '') wäre sonst nur zufällig
        // falsch.
        self::assertFalse((new CheckoutFingerprint())->matches($this->state(), ''));
    }

    public function testDieEigenePruefsummePasst(): void
    {
        $fingerprints = new CheckoutFingerprint();

        self::assertTrue($fingerprints->matches($this->state(), $fingerprints->of($this->state())));
    }

    private function state(): CheckoutState
    {
        return self::build();
    }

    private static function build(
        int $articleId = 42,
        string $quantity = '1',
        string $lineGross = '12.0000',
        int $taxClassId = 1,
        string $netTotal = '13.2500',
        string $grossTotal = '15.9000',
        string $currency = 'EUR',
        bool $reverseCharge = false,
        int $minimumAge = 0,
        bool $needsShipping = true,
        MethodOption|null $shipping = new MethodOption(4, 'Paket', true, '4.1250', '4.9500'),
        MethodOption|null $payment = new MethodOption(9, 'Vorkasse', true),
        string $billingCity = 'Wien',
        bool $separateShipping = false,
        string $vatId = '',
        VatIdCheckResult|null $vatCheck = null,
        string $dateOfBirth = '',
        int $termsAcceptedAt = 0,
        int $subscriptionRule = 0,
        array $withdrawalKinds = [],
        int $renews = 0,
    ): CheckoutState {
        $line = new CartLine(1, $articleId, $articleId, $quantity, '10.0000', '10.0000', $lineGross, $taxClassId, $subscriptionRule, $renews);

        $data = new CheckoutData(
            billing: new Address(lastname: 'Morgenbesser', street: 'Teststraße 1', postal: '1100', city: $billingCity, country: 'AT', email: 'test@example.org'),
            shipping: new Address(lastname: 'Morgenbesser', street: 'Anderswo 9', postal: '8010', city: 'Graz', country: 'AT'),
            separateShipping: $separateShipping,
            shippingMethod: null === $shipping ? 0 : $shipping->id,
            paymentMethod: null === $payment ? 0 : $payment->id,
            vatId: $vatId,
            vatCheck: $vatCheck,
            dateOfBirth: $dateOfBirth,
            termsAcceptedAt: $termsAcceptedAt,
        );

        return new CheckoutState(
            new CartView([$line], [], $quantity, '10.0000', $lineGross, $currency),
            $data,
            $needsShipping,
            null === $shipping ? [] : [$shipping],
            null === $payment ? [] : [$payment],
            $shipping,
            $payment,
            [],
            [],
            null,
            $netTotal,
            $grossTotal,
            $currency,
            $reverseCharge,
            $minimumAge,
            [],
            withdrawalKinds: $withdrawalKinds,
        );
    }
}

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
     * The assurance everything else rests on: computing the same thing twice
     * gives the same checksum. Without it every purchase click would be a
     * deviation and nobody could order.
     *
     * That only works because `CheckoutCalculator` computes without side
     * effects — the assurance from C-5 that had no consumer there.
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
     * 🔴 A changed legal text changes the checksum.
     *
     * The purchase click freezes the terms and conditions and the withdrawal
     * information by reading the elements AGAIN. If the operator saves a new
     * version between page view and click, the customer would have read v1
     * and signed v2 — with his timestamp next to it. Exactly the case the
     * snapshot exists for.
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
        // An element that drops out of its time window between display and
        // click is a change too — otherwise an order with a tick and without
        // a text would be created.
        $fingerprints = new CheckoutFingerprint();

        self::assertNotSame(
            $fingerprints->of($this->state(), ['terms' => 'aaa', 'withdrawal' => 'bbb']),
            $fingerprints->of($this->state(), ['terms' => 'aaa']),
        );
    }

    public function testDieReihenfolgeDerRechtstexteAendertNichts(): void
    {
        // Otherwise the checksum would depend on the order in which the source
        // returns the texts — and a purchase would fail because a query sorted
        // differently.
        $fingerprints = new CheckoutFingerprint();

        self::assertSame(
            $fingerprints->of($this->state(), ['terms' => 'aaa', 'withdrawal' => 'bbb']),
            $fingerprints->of($this->state(), ['withdrawal' => 'bbb', 'terms' => 'aaa']),
        );
    }

    /**
     * Every single change that touches the intent to buy changes the
     * checksum. One test per field instead of a collective test: if a field
     * drops out of the calculation, exactly this case should turn red and not
     * "some" deviation.
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
        yield 'Geburtsdatum' => [self::build(dateOfBirth: '1990-01-01', needsDateOfBirth: true)];        // Abo-2: the same money, a different contract — monthly subscription instead of a one-off purchase.
        yield 'Modell' => [self::build(subscriptionRule: 5)];
        // Abo-2: the customer read the sentence on digital content,
        // meanwhile the product is set to "service" — the order
        // freezes the new kind and the confirmation names a sentence he
        // has never seen.
        yield 'Widerrufsart' => [self::build(withdrawalKinds: ['service'])];
    }

    public function testEineVerlaengerungIstEinAndererVertragAlsEinNeuesAbo(): void
    {
        // Abo-7b: the same model, the same amount — once new,
        // once as a renewal of subscription 31. Compared against the model,
        // not against the one-off purchase, otherwise the rule alone would differ.
        $fingerprints = new CheckoutFingerprint();

        self::assertNotSame($fingerprints->of(self::build(subscriptionRule: 5)), $fingerprints->of(self::build(subscriptionRule: 5, renews: 31)));
        self::assertNotSame($fingerprints->of(self::build(subscriptionRule: 5, renews: 31)), $fingerprints->of(self::build(subscriptionRule: 5, renews: 32)));
        self::assertSame($fingerprints->of(self::build(subscriptionRule: 5)), $fingerprints->of(self::build(subscriptionRule: 5, renews: 0)), 'ohne Verlängerung wie vor Abo-7b');
    }

    public function testOhneDigitaleZeilenBleibtDiePruefsummeWieVorAbo2(): void
    {
        // Empty adds nothing: a checkout that is open during an update
        // is not refused.
        $fingerprints = new CheckoutFingerprint();

        self::assertSame($fingerprints->of(self::build()), $fingerprints->of(self::build(withdrawalKinds: [])));
        self::assertNotSame($fingerprints->of(self::build(withdrawalKinds: ['content'])), $fingerprints->of(self::build(withdrawalKinds: ['content', 'service'])));
    }

    /**
     * 🔴 The timestamp of the VIES query must NOT change the checksum.
     *
     * A check that expired and ran again in the background while the customer
     * read the details is the same agreement. If it were included, the
     * purchase would be a gamble against the clock for every customer with a
     * VAT ID.
     */
    public function testDerZeitpunktDerUidPruefungAendertNichts(): void
    {
        $fingerprints = new CheckoutFingerprint();

        $frueh = self::build(vatCheck: new VatIdCheckResult(VatIdStatus::Valid, 1_700_000_000, 'Beispiel GmbH', 'REF-1'));
        $spaet = self::build(vatCheck: new VatIdCheckResult(VatIdStatus::Valid, 1_800_000_000, 'Beispiel GmbH', 'REF-2'));

        self::assertSame($fingerprints->of($frueh), $fingerprints->of($spaet));
    }

    /**
     * 🔴 Nor the terms-and-conditions tick: it sits on exactly the form that
     * carries the checksum. If it counted, every tick would invalidate the
     * checksum it was submitted with — buying would never be possible, and
     * only in shops that require the tick.
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
     * A corrected typo in the title of a shipping method is no change to the
     * agreement. Amount and ID decide — they are in the checksum, the display
     * text is not.
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
        // A form without the field — an old template, a bot, a
        // truncated POST. hash_equals('…', '') would otherwise be false only
        // by chance.
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
        bool $needsDateOfBirth = false,
    ): CheckoutState {
        $line = new CartLine(1, $articleId, $articleId, $quantity, '10.0000', '10.0000', $lineGross, $taxClassId, $subscriptionRule, $renews);

        $data = new CheckoutData(
            billing: new Address(lastname: 'Example', street: 'Teststraße 1', postal: '1100', city: $billingCity, country: 'AT', email: 'test@example.org'),
            shipping: new Address(lastname: 'Example', street: 'Anderswo 9', postal: '8010', city: 'Graz', country: 'AT'),
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
            needsDateOfBirth: $needsDateOfBirth,
        );
    }
}

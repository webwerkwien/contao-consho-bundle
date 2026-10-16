<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutData;

class CheckoutDataTest extends TestCase
{
    /** F-3: der Zeitpunkt der Zustimmung übersteht die Speicherung. */
    public function testDieZustimmungUeberstehtDieSpeicherung(): void
    {
        $data = (new CheckoutData())->withDigitalConsentAt(1_757_000_000);

        self::assertSame(1_757_000_000, CheckoutData::fromJson($data->toJson())->digitalConsentAt);
        self::assertSame(0, CheckoutData::fromJson('{}')->digitalConsentAt);
    }

    public function testEverythingSurvivesTheRoundTrip(): void
    {
        $data = (new CheckoutData())
            ->withBilling(new Address('Musterfirma', 'Frau', 'Eva', 'Muster', 'Hauptstr. 1', '1100', 'Wien', '', 'AT', '+43 1 234', 'eva@example.org'))
            ->withShipping(new Address(street: 'Nebenweg 2', postal: '1010', city: 'Wien', country: 'AT'))
            ->withShippingMethod(4)
            ->withPaymentMethod(7)
            ->withVatId('ATU12345678')
            ->withVatCheck(new VatIdCheckResult(VatIdStatus::Valid, 1_790_000_000, 'Musterfirma GmbH', 'WAPI1'))
            ->withDateOfBirth('1980-05-17')
            ->withTermsAcceptedAt(1_790_000_100)
            ->withShippingPhone('+436641234567')
        ;

        $back = CheckoutData::fromJson($data->toJson());

        self::assertEquals($data, $back);
        self::assertSame('Eva', $back->billing->firstname);
        self::assertSame('Nebenweg 2', $back->shipping->street);
        self::assertTrue($back->separateShipping);
        self::assertSame('WAPI1', $back->vatCheck?->requestIdentifier);
    }

    /**
     * ⚠️ The one property this class really has to have. A visitor with
     * something in the basket must never meet a 500 because a column holds
     * JSON from an older shape, a half-finished write, or a hand edit — and on
     * c5 a column HAS been hand-edited more than once.
     *
     * Empty is the same state as "not filled in yet", so the checkout asks
     * again, which is the right answer for unreadable input anyway.
     */
    #[DataProvider('unreadable')]
    public function testUnreadableStoredDataReadsAsEmptyInsteadOfThrowing(string $json): void
    {
        $data = CheckoutData::fromJson($json);

        self::assertEquals(new CheckoutData(), $data);
        self::assertSame('', $data->deliveryCountry());
        self::assertFalse($data->hasVerifiedVatId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadable(): iterable
    {
        yield 'empty column' => [''];
        yield 'whitespace' => ["  \n"];
        yield 'not JSON at all' => ['{broken'];
        yield 'JSON, but not an object' => ['"a string"'];
        yield 'JSON null' => ['null'];
        yield 'a list where an object belongs' => ['[1, 2, 3]'];
        yield 'fields of the wrong type' => ['{"billing": "nope", "shippingMethod": {"x": 1}, "vatCheck": 42}'];
        yield 'an unknown VAT status' => ['{"vatCheck": {"status": "maybe", "checkedAt": 1}}'];
    }

    public function testAnUnknownFieldIsIgnoredRatherThanFatal(): void
    {
        // A column written by a later version, read by an older one.
        $data = CheckoutData::fromJson('{"paymentMethod": 3, "somethingFromTheFuture": {"deep": [1]}}');

        self::assertSame(3, $data->paymentMethod);
    }

    public function testTheDeliveryAddressIsTheBillingOneUnlessAskedOtherwise(): void
    {
        $billing = new Address(street: 'Hauptstr. 1', country: 'AT');
        $data = (new CheckoutData())->withBilling($billing);

        self::assertSame('AT', $data->deliveryCountry());
        self::assertSame($billing, $data->deliveryAddress());

        $separate = $data->withShipping(new Address(street: 'Bahnhofstr. 9', country: 'DE'));

        self::assertSame('DE', $separate->deliveryCountry());
    }

    /**
     * Unticking "different delivery address" has to CLEAR it, not just stop
     * reading it. Otherwise the old address stays in the row and the next
     * version of the checkout — or a document renderer — finds a delivery
     * address the visitor has taken back.
     */
    public function testDroppingTheSeparateAddressClearsIt(): void
    {
        $data = (new CheckoutData())
            ->withBilling(new Address(street: 'Hauptstr. 1', country: 'AT'))
            ->withShipping(new Address(street: 'Bahnhofstr. 9', country: 'DE'))
            ->withShipping(new Address(street: 'Bahnhofstr. 9', country: 'DE'), separate: false)
        ;

        self::assertFalse($data->separateShipping);
        self::assertEquals(new Address(), $data->shipping);
        self::assertSame('AT', $data->deliveryCountry());
        self::assertStringNotContainsString('Bahnhofstr', $data->toJson());
    }

    /**
     * 🔴 The expensive one: a successful check belongs to the ID it was made
     * for. Changing the ID afterwards and keeping the verdict is how an
     * unchecked VAT ID ends up on an invoice with no tax on it — reverse
     * charge granted on evidence about a different number.
     */
    public function testChangingTheVatIdDropsTheEarlierVerdict(): void
    {
        $checked = (new CheckoutData())
            ->withVatId('ATU12345678')
            ->withVatCheck(new VatIdCheckResult(VatIdStatus::Valid, 1_790_000_000, null, 'WAPI1'))
        ;

        self::assertTrue($checked->hasVerifiedVatId());

        $changed = $checked->withVatId('DE123456789');

        self::assertNull($changed->vatCheck);
        self::assertFalse($changed->hasVerifiedVatId());
    }

    public function testResubmittingTheSameVatIdKeepsTheCheck(): void
    {
        // Re-sending an unchanged address form must not cost a VIES request.
        $checked = (new CheckoutData())
            ->withVatId('ATU12345678')
            ->withVatCheck(new VatIdCheckResult(VatIdStatus::Valid, 1_790_000_000, null, 'WAPI1'))
        ;

        self::assertSame($checked, $checked->withVatId('ATU12345678'));
        self::assertTrue($checked->withVatId('ATU12345678')->hasVerifiedVatId());
    }

    #[DataProvider('unverified')]
    public function testOnlyAValidCheckCountsAsVerified(VatIdCheckResult|null $result): void
    {
        $data = (new CheckoutData())->withVatId('ATU12345678')->withVatCheck($result);

        self::assertFalse($data->hasVerifiedVatId());
    }

    /**
     * @return iterable<string, array{VatIdCheckResult|null}>
     */
    public static function unverified(): iterable
    {
        yield 'never checked' => [null];
        yield 'invalid' => [new VatIdCheckResult(VatIdStatus::Invalid, 1)];

        // C15: unreachable means "order WITH tax", never "assume it is fine".
        yield 'service unavailable' => [new VatIdCheckResult(VatIdStatus::Unavailable, 1)];
    }

    public function testAnIdWithoutACheckIsNotVerified(): void
    {
        self::assertFalse((new CheckoutData())->withVatId('ATU12345678')->hasVerifiedVatId());
    }

    public function testWithMethodsDoNotChangeTheOriginal(): void
    {
        // The calculator builds hypothetical states; none of them may leak.
        $data = new CheckoutData();

        $data->withShippingMethod(2)->withPaymentMethod(3)->withTermsAcceptedAt(5);

        self::assertSame(0, $data->shippingMethod);
        self::assertSame(0, $data->paymentMethod);
        self::assertSame(0, $data->termsAcceptedAt);
    }
}

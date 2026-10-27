<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Address;

use Contao\CoreBundle\Intl\Countries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\AddressNormalizer;
use Webwerkwien\ContaoConshoBundle\Address\PhoneNormalizer;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;

class AddressNormalizerTest extends TestCase
{
    private AddressNormalizer $normalizer;

    protected function setUp(): void
    {
        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE', 'CH']);

        $this->normalizer = new AddressNormalizer(
            new CountryProvider($countries),
            new PhoneNormalizer(),
        );
    }

    #[DataProvider('namesThatMustSurviveUntouched')]
    public function testNamesAreNeverChanged(string $name): void
    {
        $address = new Address(lastname: $name, country: 'AT');

        self::assertSame($name, $this->normalizer->normalize($address)->lastname);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function namesThatMustSurviveUntouched(): iterable
    {
        yield ['van der Berg'];
        yield ["O'Brien"];
        yield ['MacLeod'];
        yield ['de la Cruz'];
        yield ['ÖZDEMIR'];
        yield ['McDonald'];
        yield ['von und zu Guttenberg'];
        yield ['di Lorenzo'];
    }

    public function testFirstNamesAreNeverChangedEither(): void
    {
        $address = new Address(firstname: 'jean-luc', country: 'AT');

        self::assertSame('jean-luc', $this->normalizer->normalize($address)->firstname);
    }

    public function testTrimsAndCollapsesWhitespace(): void
    {
        $address = new Address(street: '  Haupt   straße   5  ', country: 'AT');

        self::assertSame('Haupt straße 5', $this->normalizer->normalize($address)->street);
    }

    public function testUppercasesTheCountryCode(): void
    {
        $address = new Address(country: 'at');

        self::assertSame('AT', $this->normalizer->normalize($address)->country);
    }

    public function testLowercasesTheEmailAddress(): void
    {
        $address = new Address(email: '  Eva@Example.Net ', country: 'AT');

        self::assertSame('eva@example.net', $this->normalizer->normalize($address)->email);
    }

    public function testNormalisesThePhoneNumberUsingTheCountry(): void
    {
        $address = new Address(country: 'at', phone: '0664 1234567');

        self::assertSame('+436641234567', $this->normalizer->normalize($address)->phone);
    }

    public function testRemovesSpacesFromThePostalCode(): void
    {
        $address = new Address(postal: ' 1100 ', country: 'AT');

        self::assertSame('1100', $this->normalizer->normalize($address)->postal);
    }

    public function testSuggestsButDoesNotApplyAStreetInTitleCase(): void
    {
        $address = new Address(street: 'hauptstr. 5', country: 'AT');

        $normalized = $this->normalizer->normalize($address);

        // Unchanged apart from whitespace — the suggestion is offered, not applied.
        self::assertSame('hauptstr. 5', $normalized->street);
        self::assertContains('Hauptstraße 5', $this->normalizer->suggestions($address));
    }

    public function testSuggestsNothingForAnAlreadyTidyStreet(): void
    {
        $address = new Address(street: 'Hauptstraße 5', country: 'AT');

        self::assertSame([], $this->normalizer->suggestions($address));
    }
}

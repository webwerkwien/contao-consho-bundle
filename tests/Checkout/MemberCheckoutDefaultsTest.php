<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use Contao\CoreBundle\Intl\Countries;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\AddressNormalizer;
use Webwerkwien\ContaoConshoBundle\Address\PhoneNormalizer;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutData;
use Webwerkwien\ContaoConshoBundle\Checkout\MemberCheckoutDefaults;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;

class MemberCheckoutDefaultsTest extends TestCase
{
    use MemberSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createMemberTable($this->connection);
    }

    public function testDieRechnungsadresseKommtAusDemMitgliedskonto(): void
    {
        $this->insertMember($this->connection, 7, [
            'company' => 'web.example.net',
            'firstname' => 'Eva',
            'lastname' => 'Example',
            'street' => 'Teststraße 1',
            'postal' => '1100',
            'city' => 'Wien',
            'country' => 'AT',
            'phone' => '+43 1 234567',
            'email' => 'web@example.net',
        ]);

        $data = $this->defaults()->applyTo(new CheckoutData(), 7);

        self::assertSame('web.example.net', $data->billing->company);
        self::assertSame('Eva', $data->billing->firstname);
        self::assertSame('Teststraße 1', $data->billing->street);
        self::assertSame('1100', $data->billing->postal);
        self::assertSame('Wien', $data->billing->city);
        self::assertSame('AT', $data->billing->country);
        self::assertSame('web@example.net', $data->billing->email);
    }

    /**
     * 🔴 **`gender` is not taken over.** Contao stores `male`/`female` — a
     * gender, not a form of address. Taken over directly, "male" would stand
     * on the invoice; translated, it would be an assumption about the form of
     * address that the field does not carry.
     */
    public function testDasGeschlechtWirdNichtZurAnrede(): void
    {
        $this->insertMember($this->connection, 7, [
            'gender' => 'male',
            'lastname' => 'Example',
            'street' => 'Teststraße 1',
            'postal' => '1100',
            'city' => 'Wien',
            'country' => 'AT',
        ]);

        self::assertSame('', $this->defaults()->applyTo(new CheckoutData(), 7)->billing->salutation);
    }

    public function testEineHinterlegteLieferadresseWirdAngehaktUndUebernommen(): void
    {
        $this->insertMember($this->connection, 7, [
            'lastname' => 'Example',
            'street' => 'Teststraße 1',
            'postal' => '1100',
            'city' => 'Wien',
            'country' => 'AT',
            'consho_shippingLastname' => 'Nachbar',
            'consho_shippingStreet' => 'Anderegasse 2',
            'consho_shippingPostal' => '1010',
            'consho_shippingCity' => 'Wien',
            'consho_shippingCountry' => 'AT',
        ]);

        $data = $this->defaults()->applyTo(new CheckoutData(), 7);

        // Ticked, not just filled: unset, the address would lie in a
        // part of the form nobody expands — and the parcel would go to
        // the billing address while the account says something else.
        self::assertTrue($data->separateShipping);
        self::assertSame('Anderegasse 2', $data->shipping->street);
    }

    public function testOhneLieferadresseBleibtDasHaeckchenAus(): void
    {
        $this->insertMember($this->connection, 7, [
            'lastname' => 'Example',
            'street' => 'Teststraße 1',
            'postal' => '1100',
            'city' => 'Wien',
            'country' => 'AT',
        ]);

        $data = $this->defaults()->applyTo(new CheckoutData(), 7);

        self::assertFalse($data->separateShipping);
        self::assertTrue($data->shipping->isEmpty());
    }

    /**
     * 🔴 The core: only what **nobody has touched** is prefilled. Whoever
     * deletes his first name because he orders as a company must not get it
     * back on the next page view.
     */
    public function testEineAngefassteAdresseWirdNichtUeberschrieben(): void
    {
        $this->insertMember($this->connection, 7, [
            'firstname' => 'Eva',
            'lastname' => 'Example',
            'street' => 'Teststraße 1',
            'postal' => '1100',
            'city' => 'Wien',
            'country' => 'AT',
        ]);

        $eigene = new CheckoutData(billing: new Address(lastname: 'Anders'));

        $data = $this->defaults()->applyTo($eigene, 7);

        self::assertSame('Anders', $data->billing->lastname);
        self::assertSame('', $data->billing->firstname, 'Kein Feld wird einzeln nachgefüllt.');
        self::assertSame('', $data->billing->street);
    }

    public function testEinGastBekommtNichts(): void
    {
        $this->insertMember($this->connection, 7, ['lastname' => 'Example', 'city' => 'Wien']);

        self::assertTrue($this->defaults()->applyTo(new CheckoutData(), 0)->billing->isEmpty());
    }

    public function testEinMitgliedOhneAdresseAendertNichts(): void
    {
        // Exactly the situation of demo members: account yes, address no.
        $this->insertMember($this->connection, 7, ['gender' => 'male']);

        $data = $this->defaults()->applyTo(new CheckoutData(), 7);

        self::assertTrue($data->billing->isEmpty());
        self::assertFalse($data->separateShipping);
    }

    /**
     * Contao keeps the date of birth as a timestamp of midnight; the
     * checkout's field wants the day. Filled also where the address was
     * already typed — the two are independent — and never over a typed date.
     */
    public function testDasGeburtsdatumKommtAusDemKonto(): void
    {
        $this->insertMember($this->connection, 7, ['lastname' => 'Example', 'dateOfBirth' => (string) mktime(0, 0, 0, 5, 17, 1960)]);

        self::assertSame('1960-05-17', $this->defaults()->applyTo(new CheckoutData(), 7)->dateOfBirth);
        self::assertSame('1960-05-17', $this->defaults()->applyTo(new CheckoutData(billing: new Address(lastname: 'Anders')), 7)->dateOfBirth);
        self::assertSame('1980-01-02', $this->defaults()->applyTo(new CheckoutData(dateOfBirth: '1980-01-02'), 7)->dateOfBirth);
        self::assertSame('', $this->defaults()->applyTo(new CheckoutData(), 0)->dateOfBirth);
    }

    public function testEinLeeresGeburtsdatumBleibtLeer(): void
    {
        $this->insertMember($this->connection, 7, ['lastname' => 'Example']);
        $this->insertMember($this->connection, 8, ['lastname' => 'Example', 'dateOfBirth' => '0']);

        self::assertSame('', $this->defaults()->applyTo(new CheckoutData(), 7)->dateOfBirth);
        self::assertSame('', $this->defaults()->applyTo(new CheckoutData(), 8)->dateOfBirth);
    }

    public function testEinGeloeschtesMitgliedAendertNichts(): void
    {
        self::assertTrue($this->defaults()->applyTo(new CheckoutData(), 99)->billing->isEmpty());
    }

    /**
     * 🔴 Contao allows `country` six characters, for subdivisions such as
     * `GB-ENG`. Consho works with ISO 3166-1 alpha-2 everywhere — tax rates,
     * delivery countries, shop whitelist. Guessing a `GB` from it would be a
     * delivery country the customer never chose.
     */
    public function testEinLandmitUntergliederungWirdNichtGeraten(): void
    {
        $this->insertMember($this->connection, 7, [
            'lastname' => 'Smith',
            'street' => 'Test Road 1',
            'postal' => 'SW1',
            'city' => 'London',
            'country' => 'GB-ENG',
        ]);

        $data = $this->defaults()->applyTo(new CheckoutData(), 7);

        self::assertSame('', $data->billing->country);
        self::assertSame('London', $data->billing->city, 'Der Rest der Adresse kommt trotzdem an.');
    }

    /**
     * Contao 5 stores the code in UPPER CASE — `Countries::getCountryCodes()`
     * says so verbatim. Contao 4 stored it in lower case, and exactly that
     * assumption would have been the wrong one. The test holds both spellings,
     * so that an old installation does not silently end up without a tax rate.
     */
    public function testEinKleingeschriebenesLandWirdGrossUebernommen(): void
    {
        $this->insertMember($this->connection, 7, [
            'lastname' => 'Example',
            'street' => 'Teststraße 1',
            'postal' => '1100',
            'city' => 'Wien',
            'country' => 'at',
        ]);

        self::assertSame('AT', $this->defaults()->applyTo(new CheckoutData(), 7)->billing->country);
    }

    public function testDieUidKommtMitUndVerdraengtKeineEingetippte(): void
    {
        $this->insertMember($this->connection, 7, [
            'lastname' => 'Example',
            'street' => 'Teststraße 1',
            'postal' => '1100',
            'city' => 'Wien',
            'country' => 'AT',
            'consho_vatId' => 'ATU12345678',
        ]);

        self::assertSame('ATU12345678', $this->defaults()->applyTo(new CheckoutData(), 7)->vatId);

        // A VAT ID typed in the checkout belongs to the visitor — even when the
        // address next to it is still untouched.
        $eigene = new CheckoutData(vatId: 'DE999999999');

        self::assertSame('DE999999999', $this->defaults()->applyTo($eigene, 7)->vatId);
    }

    /**
     * 🔴 **The prefilled address is normalised like a typed one.**
     *
     * The prefill was the one way onto the invoice that bypassed
     * `AddressNormalizer` — and that for the customer who never touched the
     * form and whose values therefore nobody corrects either.
     *
     * What is in the member account Contao has never normalised: the
     * registration takes the e-mail as it was typed.
     */
    public function testDieVorbelegteAdresseWirdNormalisiertWieEineGetippte(): void
    {
        $this->insertMember($this->connection, 7, [
            'lastname' => 'Muster',
            'street' => 'Hauptstr.  1',
            'postal' => '1 100',
            'city' => 'Wien  Favoriten',
            'country' => 'at',
            'email' => 'Eva@Example.ORG ',
        ]);

        $billing = $this->defaults()->applyTo(new CheckoutData(), 7)->billing;

        self::assertSame('eva@example.org', $billing->email, 'Die E-Mail steht so auf der Rechnung, wie sie hier ankommt.');
        self::assertSame('1100', $billing->postal);
        self::assertSame('Wien Favoriten', $billing->city);
        self::assertSame('AT', $billing->country);

        self::assertSame('Hauptstr. 1', $billing->street, 'Doppelte Leerzeichen fallen weg.');

        /*
         * ⚠️ **Spelled-out street names do NOT belong here.** The first
         * draft had an assertion on "…straße" here — wrong:
         * `STREET_ABBREVIATIONS` belongs to `suggestions()`, a suggestion
         * the customer can accept. `normalize()` does not touch them, and
         * that is right: rewriting an address against the resident's will
         * does not belong in a silent calculation step.
         */

        // And what it explicitly does NOT touch stays: names belong on
        // an invoice as they are written.
        self::assertSame('Muster', $billing->lastname);
    }

    /**
     * 🔴 **The real normaliser, not a mock.** It is part of the assertion:
     * what this class delivers ends up on an invoice, and until then the
     * prefill was the only way there that bypassed `AddressNormalizer` — of
     * all people for the customer who never touched the form and therefore
     * corrected nothing.
     */
    private function defaults(): MemberCheckoutDefaults
    {
        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE', 'CH', 'GB']);

        return new MemberCheckoutDefaults(
            $this->connection,
            new AddressNormalizer(new CountryProvider($countries), new PhoneNormalizer()),
        );
    }
}

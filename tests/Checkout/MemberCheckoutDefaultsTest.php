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
            'firstname' => 'Michael',
            'lastname' => 'Morgenbesser',
            'street' => 'Teststraße 1',
            'postal' => '1100',
            'city' => 'Wien',
            'country' => 'AT',
            'phone' => '+43 1 234567',
            'email' => 'web@example.net',
        ]);

        $data = $this->defaults()->applyTo(new CheckoutData(), 7);

        self::assertSame('web.example.net', $data->billing->company);
        self::assertSame('Michael', $data->billing->firstname);
        self::assertSame('Teststraße 1', $data->billing->street);
        self::assertSame('1100', $data->billing->postal);
        self::assertSame('Wien', $data->billing->city);
        self::assertSame('AT', $data->billing->country);
        self::assertSame('web@example.net', $data->billing->email);
    }

    /**
     * 🔴 Die Entscheidung vom 2026-09-23 (Michael): **`gender` wird nicht
     * übernommen.** Contao speichert `male`/`female` — ein Geschlecht, keine
     * Anrede. Direkt übernommen stünde „male" auf der Rechnung; übersetzt wäre
     * es eine Annahme über die Anrede, die das Feld nicht trägt.
     */
    public function testDasGeschlechtWirdNichtZurAnrede(): void
    {
        $this->insertMember($this->connection, 7, [
            'gender' => 'male',
            'lastname' => 'Morgenbesser',
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
            'lastname' => 'Morgenbesser',
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

        // Angehakt, nicht nur gefüllt: Ungesetzt läge die Adresse in einem
        // Teil des Formulars, den niemand aufklappt — und das Paket ginge an
        // die Rechnungsadresse, während das Konto etwas anderes sagt.
        self::assertTrue($data->separateShipping);
        self::assertSame('Anderegasse 2', $data->shipping->street);
    }

    public function testOhneLieferadresseBleibtDasHaeckchenAus(): void
    {
        $this->insertMember($this->connection, 7, [
            'lastname' => 'Morgenbesser',
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
     * 🔴 Der Kern: Vorbelegt wird nur, was **niemand angefasst** hat. Wer
     * seinen Vornamen löscht, weil er als Firma bestellt, darf ihn beim
     * nächsten Seitenaufruf nicht zurückbekommen.
     */
    public function testEineAngefassteAdresseWirdNichtUeberschrieben(): void
    {
        $this->insertMember($this->connection, 7, [
            'firstname' => 'Michael',
            'lastname' => 'Morgenbesser',
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
        $this->insertMember($this->connection, 7, ['lastname' => 'Morgenbesser', 'city' => 'Wien']);

        self::assertTrue($this->defaults()->applyTo(new CheckoutData(), 0)->billing->isEmpty());
    }

    public function testEinMitgliedOhneAdresseAendertNichts(): void
    {
        // Genau die Lage der Demo-Mitglieder auf c5: Konto ja, Adresse nein.
        $this->insertMember($this->connection, 7, ['gender' => 'male']);

        $data = $this->defaults()->applyTo(new CheckoutData(), 7);

        self::assertTrue($data->billing->isEmpty());
        self::assertFalse($data->separateShipping);
    }

    public function testEinGeloeschtesMitgliedAendertNichts(): void
    {
        self::assertTrue($this->defaults()->applyTo(new CheckoutData(), 99)->billing->isEmpty());
    }

    /**
     * 🔴 Contao lässt `country` sechs Zeichen, für Untergliederungen wie
     * `GB-ENG`. Consho rechnet überall mit ISO 3166-1 alpha-2 — Steuersätze,
     * Lieferländer, Shop-Whitelist. Ein `GB` daraus zu raten wäre ein
     * Lieferland, das der Kunde nie gewählt hat.
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
     * Contao 5 speichert den Code in GROSSBUCHSTABEN — `Countries::getCountryCodes()`
     * sagt es im Wortlaut. Contao 4 tat es klein, und genau diese Annahme
     * wäre die falsche gewesen. Der Test hält beide Schreibweisen fest, damit
     * eine Altinstallation nicht still ohne Steuersatz dasteht.
     */
    public function testEinKleingeschriebenesLandWirdGrossUebernommen(): void
    {
        $this->insertMember($this->connection, 7, [
            'lastname' => 'Morgenbesser',
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
            'lastname' => 'Morgenbesser',
            'street' => 'Teststraße 1',
            'postal' => '1100',
            'city' => 'Wien',
            'country' => 'AT',
            'consho_vatId' => 'ATU12345678',
        ]);

        self::assertSame('ATU12345678', $this->defaults()->applyTo(new CheckoutData(), 7)->vatId);

        // Eine im Checkout eingetippte UID gehört dem Besucher — auch wenn die
        // Adresse daneben noch unberührt ist.
        $eigene = new CheckoutData(vatId: 'DE999999999');

        self::assertSame('DE999999999', $this->defaults()->applyTo($eigene, 7)->vatId);
    }

    /**
     * 🔴 **Die vorbelegte Adresse wird normalisiert wie eine getippte.**
     *
     * Gefunden im Fremdmodell-Review vom 2026-09-23: Die Vorbelegung war der
     * eine Weg auf die Rechnung, der an `AddressNormalizer` vorbeiführte —
     * und zwar für den Kunden, der das Formular nie angefasst hat und dessen
     * Werte deshalb auch niemand berichtigt.
     *
     * Was im Mitgliedskonto steht, hat Contao nie normalisiert: Die
     * Registrierung nimmt die E-Mail, wie sie getippt wurde.
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
         * ⚠️ **Ausgeschriebene Straßennamen gehören NICHT hierher.** Im
         * ersten Entwurf stand hier eine Zusicherung auf „…straße" — falsch:
         * `STREET_ABBREVIATIONS` gehört zu `suggestions()`, einem Vorschlag,
         * den der Kunde annehmen kann. `normalize()` fasst sie nicht an, und
         * das ist richtig: Eine Adresse gegen den Willen des Bewohners
         * umzuschreiben, gehört nicht in einen stillen Rechenschritt.
         */

        // Und was er ausdrücklich NICHT anfasst, bleibt: Namen gehören auf
        // eine Rechnung, wie sie geschrieben werden.
        self::assertSame('Muster', $billing->lastname);
    }

    /**
     * 🔴 **Der echte Normalisierer, kein Mock.** Er ist seit dem Review vom
     * 2026-09-23 Teil der Zusicherung: Was diese Klasse liefert, landet auf
     * einer Rechnung, und bis dahin war die Vorbelegung der einzige Weg
     * dorthin, der an `AddressNormalizer` vorbeiführte — ausgerechnet für den
     * Kunden, der das Formular nie angefasst und deshalb auch nichts
     * berichtigt hat.
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use Contao\CoreBundle\Filesystem\Dbafs\UnableToResolveUuidException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\StringUtil;
use Symfony\Component\Uid\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentSource;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Tests\Subscription\SubscriptionSchema;

class DbalDocumentSourceTest extends TestCase
{
    private const LOGO_UUID = 'b0b4c1e2-9a7b-11ef-8d4f-0242ac120002';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // From the DCA since block R (review F10): the hand-written table
        // had neither `eInvoice` nor `invoiceContactEmail`, so no test could
        // see them reach the snapshot (Fall 31/57, the fifth time).
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, language TEXT NOT NULL DEFAULT '', consho_currency TEXT NOT NULL DEFAULT '')",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_files (id INTEGER PRIMARY KEY, uuid BLOB NULL, type TEXT NOT NULL DEFAULT '', hash TEXT NOT NULL DEFAULT '')",
        );
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_payment_method');
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_translation');

        $this->connection->insert('tl_consho_shop', [
            'id' => 3, 'fallbackLanguage' => 'de', 'vatId' => 'ATU12345678',
            'invoiceCompany' => 'Muster', 'invoiceLegalForm' => 'GmbH', 'invoiceStreet' => 'Musterstraße 1',
            'invoicePostal' => '1100', 'invoiceCity' => 'Wien', 'invoiceCountry' => 'AT',
            'invoiceLogo' => StringUtil::uuidToBin(self::LOGO_UUID),
            'invoiceFooter' => 'Firmenbuch FN 1a', 'invoiceReverseChargeNote' => 'Übergang der Steuerschuld',
        ]);
        $this->connection->insert('tl_files', ['uuid' => StringUtil::uuidToBin(self::LOGO_UUID), 'type' => 'file', 'hash' => 'md5-of-logo']);
        $this->connection->insert('tl_consho_payment_method', [
            'id' => 4,
            'instructions' => '<p>Bitte überweisen</p>',
            'jsonData' => json_encode(['accountHolder' => 'Muster GmbH', 'iban' => 'AT61 1904 3002 3457 3201', 'bic' => '', 'secretToken' => 'nie']),
        ]);
    }

    public function testDerAbsenderKommtAusDerShopKonfigurationSamtUid(): void
    {
        $context = $this->source()->contextFor($this->order('de'));

        self::assertSame(
            ['company' => 'Muster', 'legalForm' => 'GmbH', 'street' => 'Musterstraße 1', 'postal' => '1100', 'city' => 'Wien', 'country' => 'AT', 'vatId' => 'ATU12345678', 'email' => ''],
            $context['issuer'],
        );
    }

    /**
     * C-12: Contao speichert Texteingaben kodiert. Der Schnappschuss hält, was
     * der Betreiber gemeint hat — die Vorlage maskiert einmal, sonst stand
     * „&#40;Binnenmarktregelung&#41;" auf der Rechnung (Abnahme 2026-09-26).
     */
    public function testKodierteEingabenKommenAlsKlartextInDenSchnappschuss(): void
    {
        $this->connection->update('tl_consho_shop', [
            'invoiceCompany' => 'Müller &amp; Söhne',
            'invoiceReverseChargeNote' => 'Steuerfrei gemäß Art. 6 Abs. 1 UStG 1994 &#40;Binnenmarktregelung&#41;.',
            'invoiceFooter' => 'FN 1a &quot;Wien&quot;',
        ], ['id' => 3]);
        $this->connection->update('tl_consho_payment_method', ['jsonData' => json_encode(['accountHolder' => 'Müller &amp; Söhne', 'iban' => 'AT61'])], ['id' => 4]);

        $context = $this->source()->contextFor($this->order('de'));

        self::assertSame('Müller & Söhne', $context['issuer']['company']);
        self::assertSame('Steuerfrei gemäß Art. 6 Abs. 1 UStG 1994 (Binnenmarktregelung).', $context['notes']['reverseCharge']);
        self::assertSame('FN 1a "Wien"', $context['notes']['footer']);
        self::assertSame('Müller & Söhne', $context['payment']['details']['accountHolder'], 'auch der Name im Giro-Code');
        self::assertSame('<p>Bitte überweisen</p>', $context['payment']['instructions'], 'Rich-Text bleibt HTML');
    }

    /**
     * UUID UND Prüfsumme — die UUID allein bemerkt kein ausgetauschtes Logo.
     */
    public function testDasLogoWirdMitUuidUndPruefsummeFestgehalten(): void
    {
        self::assertSame(['uuid' => self::LOGO_UUID, 'hash' => md5('das Logo')], $this->source()->contextFor($this->order('de'))['logo']);
    }

    /**
     * 🔴 Die Prüfsumme kommt aus dem INHALT, nicht aus tl_files.hash — dort
     * steht in diesem Test absichtlich etwas anderes. InvoiceLogo vergleicht
     * gegen den Inhalt; stünde hier ein veralteter Tabellenwert, erschiene das
     * Logo auf keiner neu erzeugten Rechnung je wieder.
     */
    public function testDiePruefsummeKommtAusDemInhaltNichtAusDerTabelle(): void
    {
        self::assertNotSame('md5-of-logo', $this->source()->contextFor($this->order('de'))['logo']['hash'] ?? null);
    }

    /**
     * Ein gesetztes Logo, dessen Datei es nicht (mehr) gibt, ist keins — es
     * gäbe nichts einzufrieren.
     */
    public function testEinLogoOhneDateiIstKeines(): void
    {
        self::assertNull($this->source(logoGone: true)->contextFor($this->order('de'))['logo']);
    }

    /**
     * Nur die Bankangaben, und nur die gefüllten — was ein Modul sonst in
     * jsonData ablegt, gehört nicht auf eine Rechnung.
     */
    public function testNurGefuellteBankangabenKommenMit(): void
    {
        self::assertSame(
            ['accountHolder' => 'Muster GmbH', 'iban' => 'AT61 1904 3002 3457 3201'],
            $this->source()->contextFor($this->order('de'))['payment']['details'],
        );
    }

    /**
     * Giro-Code: der Wunsch der Zahlungsart, gelesen aus jsonData — ohne
     * nach dem Typ zu fragen. Eine spätere Zahlungsart „Kauf auf Rechnung"
     * mit Bankverbindung bekommt ihn damit ohne neuen Code (Michael,
     * 2026-09-24). Die Tabelle hier hat gar keine Spalte `type`.
     */
    public function testDerGiroCodeWunschKommtAusJsonDataUnabhaengigVomTyp(): void
    {
        $before = $this->source()->contextFor($this->order('de'))['payment'];
        self::assertFalse($before['giroCode']);

        $this->connection->update('tl_consho_payment_method', [
            'jsonData' => json_encode(['accountHolder' => 'Muster GmbH', 'iban' => 'AT611904300234573201', 'giroCode' => '1']),
        ], ['id' => 4]);

        $payment = $this->source()->contextFor($this->order('de'))['payment'];

        self::assertTrue($payment['giroCode']);
        // Der Schalter ist keine Bankangabe und erscheint nicht in der Tabelle der Rechnung.
        self::assertArrayNotHasKey('giroCode', $payment['details']);
    }

    public function testInDerHauptspracheStehenDieTexteDesDatensatzes(): void
    {
        $context = $this->source()->contextFor($this->order('de'));

        self::assertSame('Firmenbuch FN 1a', $context['notes']['footer']);
        self::assertSame('<p>Bitte überweisen</p>', $context['payment']['instructions']);
    }

    /**
     * In der Sprache der Bestellung — und wo keine Übersetzung steht, der
     * Text des Datensatzes, wie überall sonst (TranslationJoin).
     */
    public function testInEinerZweitspracheDieUebersetzungSonstDerHaupttext(): void
    {
        $this->connection->insert('tl_consho_translation', [
            'pid' => 3, 'ptable' => 'tl_consho_shop', 'language' => 'en', 'invoiceFooter' => 'Company register FN 1a', 'invoiceReverseChargeNote' => '',
        ]);

        $context = $this->source()->contextFor($this->order('en'));

        self::assertSame('Company register FN 1a', $context['notes']['footer']);
        self::assertSame('Übergang der Steuerschuld', $context['notes']['reverseCharge']);
        self::assertSame('<p>Bitte überweisen</p>', $context['payment']['instructions']);
    }

    /**
     * Block R (R10, review F10): der Schalter des Shops und die Kontakt-E-Mail
     * kommen über die Quelle in den Schnappschuss — bis zur Entscheidung
     * „Geschäftskunde".
     */
    public function testDerSchalterDesShopsReichtBisInDenSchnappschuss(): void
    {
        $before = $this->source()->contextFor($this->order('de'));
        self::assertFalse($before['eInvoice'] ?? null);

        $this->connection->update('tl_consho_shop', ['eInvoice' => 1, 'invoiceContactEmail' => 'rechnung@example.org'], ['id' => 3]);
        // Vorkasse mit IBAN: SEPA-Überweisung (ohne Typ wäre es ZZZ).
        $this->connection->update('tl_consho_payment_method', ['type' => 'prepayment'], ['id' => 4]);
        $context = $this->source()->contextFor($this->order('de'));

        self::assertTrue($context['eInvoice'] ?? null);
        self::assertSame('rechnung@example.org', $context['issuer']['email']);
        self::assertSame('58', $context['payment']['meansCode'] ?? null);

        $builder = new \Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder();
        self::assertTrue($builder->build($this->order('de') + ['businessCustomer' => '1'], [], $context)['eInvoice']);
        self::assertFalse($builder->build($this->order('de') + ['businessCustomer' => '0'], [], $context)['eInvoice']);
    }

    private function source(bool $logoGone = false): DbalDocumentSource
    {
        $files = $this->createMock(VirtualFilesystemInterface::class);

        if ($logoGone) {
            $files->method('read')->willThrowException(new UnableToResolveUuidException(Uuid::fromString(self::LOGO_UUID)));
        } else {
            $files->method('read')->with(self::callback(static fn (mixed $uuid): bool => $uuid instanceof Uuid && self::LOGO_UUID === $uuid->toRfc4122()))->willReturn('das Logo');
        }

        return new DbalDocumentSource($this->connection, new TranslationStorage($this->connection), new ShopLanguages($this->connection), $files, $this->createStub(OrderBalanceReaderInterface::class));
    }

    /**
     * @return array<string, string>
     */
    /**
     * Abo-4 (P31): Abonummer und Leistungszeitraum einer Folgebestellung, als
     * Kalendertage in der Zone der Installation — der Zeitraum von der
     * BESTELLUNG, wo er eingefroren ist, nicht vom Abo, das weiterschaltet.
     */
    public function testEineFolgebestellungNenntAboUndZeitraum(): void
    {
        SubscriptionSchema::create($this->connection);
        $this->connection->insert('tl_consho_subscription', ['id' => 5, 'subscriptionNumber' => 'WEB-00084-1', 'periodFrom' => 1, 'periodUntil' => 2]);

        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            // 29.10.2026 00:30 Wien ist in UTC noch der 28.10.
            $from = (string) (new \DateTimeImmutable('2026-10-29 00:30'))->getTimestamp();
            $until = (string) (new \DateTimeImmutable('2026-11-29 00:30'))->getTimestamp();

            $context = $this->source()->contextFor(['subscription' => '5', 'servicePeriodFrom' => $from, 'servicePeriodUntil' => $until] + $this->order('de'));
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertSame(['number' => 'WEB-00084-1', 'from' => '2026-10-29', 'to' => '2026-11-29'], $context['subscription'] ?? null);
    }

    public function testEinKaufNenntKeinAbo(): void
    {
        self::assertNull($this->source()->contextFor(['subscription' => '0', 'servicePeriodFrom' => ''] + $this->order('de'))['subscription'] ?? null);
        self::assertNull($this->source()->contextFor($this->order('de'))['subscription'] ?? null);
    }

    private function order(string $language): array
    {
        return ['shop' => '3', 'language' => $language, 'paymentMethod' => '4'];
    }

    /**
     * WG12: Die Zahlungen vor der Rechnung, in der Reihenfolge ihres Eingangs
     * — nur die dieser Bestellung.
     */
    public function testDieZahlungenDerBestellungKommenMit(): void
    {
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_payment');
        // Mit einer Bestellnummer fragt die Quelle auch nach der Sendung.
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_delivery');
        $this->connection->insert('tl_consho_payment', ['pid' => 9, 'receivedAt' => 200, 'amount' => '-1.0000', 'method' => 'refund']);
        $this->connection->insert('tl_consho_payment', ['pid' => 9, 'receivedAt' => 100, 'amount' => '5.0000', 'method' => 'Gutschein']);
        $this->connection->insert('tl_consho_payment', ['pid' => 8, 'receivedAt' => 100, 'amount' => '7.0000', 'method' => 'Vorkasse']);

        self::assertSame(
            [['method' => 'Gutschein', 'amount' => '5.0000'], ['method' => 'refund', 'amount' => '-1.0000']],
            $this->source()->contextFor(['id' => '9'] + $this->order('de'))['payments'],
        );
    }
}

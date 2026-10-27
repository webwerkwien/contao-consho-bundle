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
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
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

        // From the DCA since block R: the hand-written table
        // had neither `eInvoice` nor `invoiceContactEmail`, so no test could
        // see them reach the snapshot — a hand-written schema goes stale with
        // the next column.
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::createCore($this->connection, 'tl_page');
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
     * C-12: Contao stores text input encoded. The snapshot holds what the
     * operator meant — the template escapes once, otherwise
     * "&#40;Binnenmarktregelung&#41;" stood on the invoice.
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
     * UUID AND checksum — the UUID alone does not notice a replaced logo.
     */
    public function testDasLogoWirdMitUuidUndPruefsummeFestgehalten(): void
    {
        self::assertSame(['uuid' => self::LOGO_UUID, 'hash' => md5('das Logo')], $this->source()->contextFor($this->order('de'))['logo']);
    }

    /**
     * 🔴 The checksum comes from the CONTENT, not from tl_files.hash — in this
     * test that column deliberately holds something else. InvoiceLogo compares
     * against the content; if an outdated table value stood here, the logo
     * would never appear again on any newly generated invoice.
     */
    public function testDiePruefsummeKommtAusDemInhaltNichtAusDerTabelle(): void
    {
        self::assertNotSame('md5-of-logo', $this->source()->contextFor($this->order('de'))['logo']['hash'] ?? null);
    }

    /**
     * A logo that is set but whose file does not exist (any more) is none —
     * there would be nothing to freeze.
     */
    public function testEinLogoOhneDateiIstKeines(): void
    {
        self::assertNull($this->source(logoGone: true)->contextFor($this->order('de'))['logo']);
    }

    /**
     * Only the bank details, and only the filled ones — whatever else a module
     * puts in jsonData does not belong on an invoice.
     */
    public function testNurGefuellteBankangabenKommenMit(): void
    {
        self::assertSame(
            ['accountHolder' => 'Muster GmbH', 'iban' => 'AT61 1904 3002 3457 3201'],
            $this->source()->contextFor($this->order('de'))['payment']['details'],
        );
    }

    /**
     * L-1: whether the customer pays before the goods go out. Prepayment and
     * anything collected online do; a purchase on account and cash on
     * delivery pay after delivery, and their invoice is no advance invoice.
     */
    public function testVorkasseUndOnlineZahlungSindVorauszahlungen(): void
    {
        foreach (['prepayment' => true, 'test' => true, 'invoice' => false, 'cod' => false] as $type => $advance) {
            $this->connection->update('tl_consho_payment_method', ['type' => $type], ['id' => 4]);

            self::assertSame($advance, $this->source()->contextFor($this->order('de'))['payment']['advance'], $type);
        }

        // No method: paid in full by vouchers at the purchase (WG-5, E1).
        self::assertTrue($this->source()->contextFor(['paymentMethod' => '0'] + $this->order('de'))['payment']['advance']);
        // A method that no longer exists.
        self::assertFalse($this->source()->contextFor(['paymentMethod' => '99'] + $this->order('de'))['payment']['advance']);
    }

    /**
     * Giro code: the wish of the payment method, read from jsonData — without
     * asking for the type. A later payment method "purchase on account" with
     * bank details thus gets it without new code. The table here has no
     * `type` column at all.
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
        // The switch is not a bank detail and does not appear in the invoice's table.
        self::assertArrayNotHasKey('giroCode', $payment['details']);
    }

    public function testInDerHauptspracheStehenDieTexteDesDatensatzes(): void
    {
        $context = $this->source()->contextFor($this->order('de'));

        self::assertSame('Firmenbuch FN 1a', $context['notes']['footer']);
        self::assertSame('<p>Bitte überweisen</p>', $context['payment']['instructions']);
    }

    /**
     * In the language of the order — and where there is no translation, the
     * text of the record, as everywhere else (TranslationJoin).
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
     * Block R (R10): the shop's switch and the contact e-mail reach the
     * snapshot through the source — up to the decision "business customer".
     */
    public function testDerSchalterDesShopsReichtBisInDenSchnappschuss(): void
    {
        $before = $this->source()->contextFor($this->order('de'));
        self::assertFalse($before['eInvoice'] ?? null);

        $this->connection->update('tl_consho_shop', ['eInvoice' => 1, 'invoiceContactEmail' => 'rechnung@example.org'], ['id' => 3]);
        // Prepayment with IBAN: SEPA credit transfer (without a type it would be ZZZ).
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
     * Abo-4 (P31): subscription number and service period of a follow-up order,
     * as calendar days in the installation's zone — the period from the
     * ORDER, where it is frozen, not from the subscription, which moves on.
     */
    public function testEineFolgebestellungNenntAboUndZeitraum(): void
    {
        SubscriptionSchema::create($this->connection);
        $this->connection->insert('tl_consho_subscription', ['id' => 5, 'subscriptionNumber' => 'WEB-00084-1', 'periodFrom' => 1, 'periodUntil' => 2]);

        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            // 29.10.2026 00:30 Vienna is still the 28.10. in UTC.
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
     * WG12: The payments before the invoice, in the order they came in
     * — only those of this order.
     */
    public function testDieZahlungenDerBestellungKommenMit(): void
    {
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_payment');
        // With an order number the source also asks for the shipment.
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_delivery');
        $this->connection->insert('tl_consho_payment', ['pid' => 9, 'receivedAt' => 200, 'amount' => '-1.0000', 'method' => 'refund']);
        $this->connection->insert('tl_consho_payment', ['pid' => 9, 'receivedAt' => 100, 'amount' => '5.0000', 'method' => 'Gutschein']);
        $this->connection->insert('tl_consho_payment', ['pid' => 8, 'receivedAt' => 100, 'amount' => '7.0000', 'method' => 'Vorkasse']);

        self::assertSame(
            [['method' => 'Gutschein', 'amount' => '5.0000'], ['method' => 'refund', 'amount' => '-1.0000']],
            $this->source()->contextFor(['id' => '9'] + $this->order('de'))['payments'],
        );
    }

    /**
     * EZ-4: per single-purpose voucher what it paid on this order, refunds to
     * it deducted, with the tax its sale carried — named as the redemption
     * named it. A voucher refunded in full drops out, other payments are not
     * part of it.
     */
    public function testWasEinzweckGutscheineBereitsVersteuertBezahltHaben(): void
    {
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_payment');
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_delivery');
        $tax = ['prepaidTaxRate' => '20.0000', 'prepaidTaxCountry' => 'at'];
        $this->connection->insert('tl_consho_payment', ['pid' => 9, 'receivedAt' => 100, 'amount' => '30.0000', 'method' => 'Gutschein …MDSN', 'reference' => 'voucher:1', 'prepaidTax' => '5.0000'] + $tax);
        $this->connection->insert('tl_consho_payment', ['pid' => 9, 'receivedAt' => 200, 'amount' => '-6.0000', 'method' => 'refund', 'reference' => 'voucher:1', 'prepaidTax' => '-1.0000'] + $tax);
        $this->connection->insert('tl_consho_payment', ['pid' => 9, 'receivedAt' => 100, 'amount' => '12.0000', 'method' => 'Gutschein …HHHH', 'reference' => 'voucher:2', 'prepaidTax' => '2.0000'] + $tax);
        $this->connection->insert('tl_consho_payment', ['pid' => 9, 'receivedAt' => 300, 'amount' => '-12.0000', 'method' => 'refund', 'reference' => 'voucher:2', 'prepaidTax' => '-2.0000'] + $tax);
        $this->connection->insert('tl_consho_payment', ['pid' => 9, 'receivedAt' => 100, 'amount' => '5.0000', 'method' => 'Gutschein …WXYZ', 'reference' => 'voucher:3']);
        $this->connection->insert('tl_consho_payment', ['pid' => 8, 'receivedAt' => 100, 'amount' => '30.0000', 'method' => 'Gutschein …MDSN', 'reference' => 'voucher:1', 'prepaidTax' => '5.0000'] + $tax);

        self::assertSame(
            [['label' => 'Gutschein …MDSN', 'country' => 'AT', 'rate' => '20.0000', 'net' => '20.0000', 'tax' => '4.0000', 'gross' => '24.0000']],
            $this->source()->contextFor(['id' => '9'] + $this->order('de'))['prepaid'],
        );
    }

    /**
     * The anonymisation empties the reference: two vouchers at different
     * rates then stand apart, not merged under one rate and one name.
     */
    public function testOhneReferenzStehtJedeZahlungFuerSich(): void
    {
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_payment');
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_delivery');
        $this->connection->insert('tl_consho_payment', ['pid' => 9, 'receivedAt' => 100, 'amount' => '24.0000', 'method' => 'Gutschein …MDSN', 'prepaidTaxRate' => '20.0000', 'prepaidTaxCountry' => 'AT', 'prepaidTax' => '4.0000']);
        $this->connection->insert('tl_consho_payment', ['pid' => 9, 'receivedAt' => 100, 'amount' => '11.0000', 'method' => 'Gutschein …HHHH', 'prepaidTaxRate' => '10.0000', 'prepaidTaxCountry' => 'AT', 'prepaidTax' => '1.0000']);

        $prepaid = $this->source()->contextFor(['id' => '9'] + $this->order('de'))['prepaid'];

        self::assertSame([['Gutschein …MDSN', '20.0000', '4.0000'], ['Gutschein …HHHH', '10.0000', '1.0000']], array_map(static fn (array $entry): array => [$entry['label'], $entry['rate'], $entry['tax']], $prepaid));
    }
}

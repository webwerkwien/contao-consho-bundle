<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\GiroCode;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceLogo;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceRenderer;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceView;
use Webwerkwien\ContaoConshoBundle\Document\Pdf\DompdfRenderer;
use Webwerkwien\ContaoConshoBundle\Document\EInvoice\ZugferdInvoice;
use Webwerkwien\ContaoConshoBundle\Document\Pdf\PdfRendererInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * 13.1 "Snapshot versions": a sample snapshot of EVERY formatVersion
 * renders, with number, totals and tax per rate.
 *
 * Against the real template and the real language files — a transcript
 * would not contain the original's error. The translator reads
 * $GLOBALS['TL_LANG'] like Contao's own, so that a missing key stays in
 * the HTML as a key and the test finds it.
 */
class InvoiceRendererTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../contao/languages/de/default.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function everyVersion(): iterable
    {
        yield 'Fassung 1 (vor C-8, ohne formatVersion und lineType)' => [self::versionOne()];
        yield 'Fassung 2' => [self::versionTwo()];
        yield 'Fassung 3 (mit Giro-Code)' => [self::versionThree()];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('everyVersion')]
    public function testJedeFassungRendertMitNummerSummenUndSteuerJeSatz(array $payload): void
    {
        $html = $this->renderer()->html($this->document($payload))['html'];

        self::assertStringContainsString('Rechnung RE-00007', $html);
        self::assertStringContainsString('82,00', $html);
        self::assertStringContainsString('12,00', $html);
        // ICU separates with a non-breaking space, so that "20 %" does not wrap.
        self::assertMatchesRegularExpression('/10[\x{00A0}\x{202F} ]%/u', $html);
        self::assertMatchesRegularExpression('/20[\x{00A0}\x{202F} ]%/u', $html);
        self::assertStringContainsString('Schwedenbitter', $html);
    }

    /**
     * Every key of the template is in the language file. If one is missing,
     * the invoice would print "CONSHO.invoice.…" — visible to the customer.
     */
    #[DataProvider('everyVersion')]
    public function testKeinUnuebersetzterSchluesselBleibtStehen(array $payload): void
    {
        $html = $this->renderer()->html($this->document($payload, regenerated: true), true)['html'];

        self::assertStringNotContainsString('CONSHO.', $html);
    }

    /**
     * C-10: The cancellation of EVERY version renders — it takes on the shape
     * of the invoice it cancels, so also one from before C-8. It names it,
     * carries its amounts with the opposite sign and demands no payment.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('everyVersion')]
    public function testDerStornoJederFassungNenntSeineRechnungUndFordertNichts(array $payload): void
    {
        $cancellation = (new DocumentPayloadBuilder())->cancellationOf($payload, 'RE-00007', 1790000000);

        $html = $this->renderer()->html([
            'id' => 2, 'type' => 'cancellation', 'documentNumber' => 'ST-00003', 'issuedAt' => 1790100000, 'orderLanguage' => 'de',
            'payload' => json_encode($cancellation, JSON_THROW_ON_ERROR),
        ])['html'];

        self::assertStringContainsString('Stornorechnung ST-00003', $html);
        self::assertStringContainsString('RE-00007', $html);
        self::assertMatchesRegularExpression('/[-\x{2212}]82,00/u', $html);
        self::assertStringNotContainsString('AT61 1904 3002 3457 3201', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    /**
     * D-3: "Rechnungsnummer GS-00001" above "Korrigiert Rechnung RE-00037"
     * read as a contradiction. Hence a label of its own, only for the
     * correction — the cancellation keeps the old one.
     */
    public function testDieKorrekturHatEigeneNummerUndDatumBeschriftung(): void
    {
        $line = ['productName' => 'Schwedenbitter', 'sku' => 'SB-250', 'quantity' => '1', 'unitPrice' => '-50.0000', 'taxRate' => '20.0000', 'taxAmount' => '-10.0000', 'lineTotal' => '-60.0000', 'lineType' => 'product'];
        $correction = (new DocumentPayloadBuilder())->correctionOf(self::versionTwo(), 'RE-00007', 1790000000, [$line], 'WEB-00042-W1');

        foreach (['de' => ['Nummer der Rechnungskorrektur', 'Datum der Rechnungskorrektur'], 'en' => ['Correction number', 'Correction date']] as $language => [$number, $date]) {
            $GLOBALS['TL_LANG'] = [];
            require __DIR__.'/../../contao/languages/'.$language.'/default.php';

            $html = $this->renderer()->html([
                'id' => 3, 'type' => 'creditNote', 'documentNumber' => 'GS-00001', 'issuedAt' => 1790100000, 'orderLanguage' => $language,
                'payload' => json_encode($correction, JSON_THROW_ON_ERROR),
            ])['html'];

            self::assertStringContainsString('<th scope="row">'.$number.'</th><td>GS-00001</td>', $html);
            self::assertStringContainsString('<th scope="row">'.$date.'</th>', $html);
        }

        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../contao/languages/de/default.php';

        self::assertStringContainsString('<th scope="row">Rechnungsnummer</th><td>RE-00007</td>', $this->renderer()->html($this->document(self::versionTwo()))['html'], 'die Rechnung bleibt, wie sie ist');
    }

    /**
     * And the same for English: both language files carry all keys.
     */
    public function testAuchEnglischIstVollstaendig(): void
    {
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../contao/languages/en/default.php';

        $payload = self::versionTwo();
        $payload['order']['language'] = 'en';

        $html = $this->renderer()->html($this->document($payload), true)['html'];

        self::assertStringNotContainsString('CONSHO.', $html);
        self::assertStringContainsString('lang="en"', $html);

        $cancellation = (new DocumentPayloadBuilder())->cancellationOf($payload, 'RE-00007', 1790000000);
        $html = $this->renderer()->html(['type' => 'cancellation', 'documentNumber' => 'ST-00003', 'payload' => json_encode($cancellation, JSON_THROW_ON_ERROR)] + $this->document($payload))['html'];

        self::assertStringNotContainsString('CONSHO.', $html);
    }

    /**
     * Version 1 is NEVER completed from today's shop: no issuer, because there
     * is none in the snapshot.
     */
    public function testFassungEinsBleibtOhneAbsender(): void
    {
        $html = $this->renderer()->html($this->document(self::versionOne()))['html'];

        self::assertStringNotContainsString('Muster GmbH', $html);
        self::assertStringNotContainsString('UID-Nr.:', $html);
    }

    public function testFassungZweiZeigtAbsenderBankUndFusszeile(): void
    {
        $html = $this->renderer()->html($this->document(self::versionTwo()))['html'];

        self::assertStringContainsString('Muster GmbH', $html);
        self::assertStringContainsString('UID-Nr.: ATU12345678', $html);
        self::assertStringContainsString('AT61 1904 3002 3457 3201', $html);
        self::assertStringContainsString('Firmenbuch FN 1a', $html);
    }

    /**
     * The "generated anew" note (8.3) appears only when it applies.
     */
    public function testNeuErzeugtStehtNurWennEsGilt(): void
    {
        self::assertStringNotContainsString('Neu erzeugt', $this->renderer()->html($this->document(self::versionTwo()))['html']);
        self::assertStringContainsString('Neu erzeugt', $this->renderer()->html($this->document(self::versionTwo()), true)['html']);
    }

    /**
     * Version 1 stored the tax per rate raw with four decimals. The view
     * recomputes it by the rule of C-8 — otherwise a summary with 3.33 would
     * stand next to 3.32.
     */
    public function testFassungEinsRechnetDieSteuerJeSatzNachDerNeuenRegel(): void
    {
        $line = [
            'productName' => 'Wanderstock', 'sku' => '', 'quantity' => '1.0000', 'unitPrice' => '8.3367',
            'taxRate' => '20.0000', 'taxAmount' => '1.6673', 'lineTotal' => '10.0040', 'priceSource' => 'list',
        ];
        $payload = [
            'order' => ['orderNumber' => 'WEB-1', 'currency' => 'EUR', 'netTotal' => '16.68', 'taxTotal' => '3.32', 'grandTotal' => '20.00'],
            'items' => [$line, $line],
            'taxSummary' => ['20.0000' => ['net' => '16.6734', 'tax' => '3.3346']],
        ];

        $html = $this->renderer()->html($this->document($payload))['html'];

        self::assertStringContainsString('3,32', $html);
        self::assertStringNotContainsString('3,33', $html);
    }

    /**
     * C-8, delivery date: The period is in the snapshot as a date and is
     * printed as such — read and written in one time zone. Vienna is ahead of
     * UTC; a date read as Viennese midnight and printed in UTC would be the
     * day before.
     */
    public function testDerLieferzeitraumStehtMitDenDatenDesSchnappschusses(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            $payload = self::versionTwo();
            $payload['delivery'] = ['from' => '2026-09-28', 'to' => '2026-10-01'];

            $html = $this->renderer()->html($this->document($payload))['html'];
        } finally {
            date_default_timezone_set($timezone);
        }

        self::assertStringContainsString('Voraussichtl. Lieferung', $html);
        self::assertStringContainsString('28.09.2026 – 01.10.2026', $html);
    }

    /**
     * The recipient's VAT ID is on the invoice — unless VIES reported it as
     * invalid. Not reachable: printed.
     */
    public function testDieUidDesEmpfaengersNurWennVIESSieNichtAbgelehntHat(): void
    {
        foreach (['valid' => true, 'unavailable' => true, 'invalid' => false] as $status => $printed) {
            $payload = self::versionTwo();
            $payload['order']['vatId'] = 'DE123456789';
            $payload['order']['vatIdStatus'] = $status;

            $html = $this->renderer()->html($this->document($payload))['html'];

            self::assertSame($printed, str_contains($html, 'DE123456789'), $status);
        }
    }

    /**
     * E-2: "On account" names the day by which payment is due — as a date
     * from the snapshot (version 4), printed in the same zone.
     */
    public function testDasZahlungszielStehtAlsDatumAufDerRechnung(): void
    {
        $payload = self::versionTwo();
        $payload['formatVersion'] = 4;
        $payload['payment']['dueDate'] = '2026-10-12';

        $html = $this->renderer()->html($this->document($payload))['html'];

        self::assertStringContainsString('Zahlbar bis', $html);
        self::assertStringContainsString('12.10.2026', $html);
        self::assertStringNotContainsString('Zahlbar bis', $this->renderer()->html($this->document(self::versionTwo()))['html'], 'ohne Ziel keine Zeile');
    }

    /**
     * WG12 (version 9): What was paid before the invoice is shown below the
     * total, with the open amount — and next to the giro code stands the
     * amount it asks for, not the whole one.
     */
    public function testZahlungenUndOffenerBetragStehenUnterDerSumme(): void
    {
        $payload = ['formatVersion' => 9] + self::versionTwo();
        $payload['payment']['payments'] = [['method' => 'Gutschein', 'amount' => '30.0000'], ['method' => 'refund', 'amount' => '-5.0000']];
        $payload['payment']['outstanding'] = '57.0000';
        $payload['payment']['giroCode'] = GiroCode::payload($payload['payment']['details'], '57.0000', 'EUR', 'RE-00007');

        $html = $this->renderer()->html($this->document($payload))['html'];

        self::assertStringContainsString('Bereits bezahlt (Gutschein)', $html);
        self::assertStringContainsString('Erstattet', $html);
        self::assertStringContainsString('Offener Betrag', $html);
        self::assertSame(2, substr_count($html, '57,00'), 'Summenblock und Giro-Angaben');
        self::assertStringNotContainsString('Offener Betrag', $this->renderer()->html($this->document(self::versionThree()))['html'], 'ohne Zahlungen keine Zeile');
    }

    /**
     * E-6b: After shipping, the delivery date instead of the period — for a
     * partial delivery with the day on which the rest is expected to follow.
     */
    public function testNachDemVersandDasLieferdatum(): void
    {
        $payload = self::versionTwo();
        $payload['formatVersion'] = 5;
        $payload['delivery'] = ['from' => '2026-10-01', 'to' => '2026-10-01', 'kind' => 'delivered'];
        $html = $this->renderer()->html($this->document($payload))['html'];

        self::assertStringContainsString('Lieferdatum', $html);
        self::assertStringContainsString('01.10.2026', $html);
        self::assertStringNotContainsString('Voraussichtl. Lieferung', $html);

        $payload['delivery'] = ['from' => '2026-10-01', 'to' => '2026-10-08', 'kind' => 'partial'];
        $html = $this->renderer()->html($this->document($payload))['html'];

        self::assertStringContainsString('01.10.2026 (Teillieferung)', $html);
        self::assertStringContainsString('Restlieferung voraussichtl.', $html);
        self::assertStringContainsString('08.10.2026', $html);
    }

    /**
     * L-1: An invoice paid in advance and issued before shipping says that it
     * covers an advance payment (formatVersion 11). An older snapshot with the
     * same data stays as it was printed; a purchase on account has no note.
     */
    public function testDieVorauszahlungsrechnungSagtEsAb11(): void
    {
        $payload = self::versionTwo();
        $payload['formatVersion'] = 11;
        $payload['delivery'] = ['from' => '2026-09-28', 'to' => '2026-10-01', 'kind' => 'expected', 'advance' => true];
        $note = 'Diese Rechnung rechnet über eine Vorauszahlung ab, die Lieferung erfolgt nach Zahlungseingang.';

        self::assertStringContainsString($note, $this->renderer()->html($this->document($payload))['html']);

        $payload['formatVersion'] = 10;
        self::assertStringNotContainsString($note, $this->renderer()->html($this->document($payload))['html']);

        $payload['formatVersion'] = 11;
        unset($payload['delivery']['advance']);
        self::assertStringNotContainsString($note, $this->renderer()->html($this->document($payload))['html']);

        $payload['delivery'] = ['from' => '2026-10-01', 'to' => '2026-10-01', 'kind' => 'delivered', 'advance' => true];
        self::assertStringNotContainsString($note, $this->renderer()->html($this->document($payload))['html']);
    }

    /**
     * Abo-2 (P13): The terms of a subscription stand at its line — as they
     * were frozen at purchase.
     */
    public function testDieAbobedingungenStehenAnDerZeile(): void
    {
        $payload = self::versionTwo();
        $payload['formatVersion'] = 6;
        $payload['items'][0]['subscriptionTerms'] = '1,00 € / Monat · Laufzeit 1 Jahr · endet danach';

        $html = $this->renderer()->html($this->document($payload))['html'];

        self::assertStringContainsString('1,00 € / Monat · Laufzeit 1 Jahr · endet danach', $html);
        self::assertStringNotContainsString('consho-invoice__terms', $this->renderer()->html($this->document(self::versionTwo()))['html'], 'ohne Abo keine Zeile');
    }

    /**
     * EZ-3 (version 12): a line that sold a single-purpose voucher says so;
     * the others and older snapshots do not.
     */
    public function testDerEinzweckGutscheinStehtAnDerZeile(): void
    {
        $payload = self::versionTwo();
        $payload['formatVersion'] = 12;
        $payload['items'][0]['voucherSinglePurpose'] = 1;

        self::assertStringContainsString('<small class="consho-invoice__terms">Einzweck-Gutschein</small>', $this->renderer()->html($this->document($payload))['html']);
        self::assertStringNotContainsString('Einzweck-Gutschein', $this->renderer()->html($this->document(self::versionTwo()))['html']);
    }

    /**
     * EZ-4 (version 13): below the VAT, the tax a single-purpose voucher
     * already carried, with its name and rate, and what remains. Without
     * one neither line.
     */
    public function testDieBereitsVersteuerteSteuerStehtUnterDerUmsatzsteuer(): void
    {
        $payload = self::versionTwo();
        $payload['formatVersion'] = 13;
        $payload['prepaid'] = [['label' => 'Gutschein …MDSN', 'country' => 'AT', 'rate' => '20.0000', 'net' => '20.0000', 'tax' => '4.0000', 'gross' => '24.0000']];

        $html = $this->renderer()->html($this->document($payload))['html'];

        self::assertMatchesRegularExpression('~davon mit Gutschein …MDSN bereits versteuert \(20\s?%\)</th><td class="num">4,00~u', $html);
        self::assertMatchesRegularExpression('~Verbleibende Umsatzsteuer</th><td class="num">8,00~u', $html);
        self::assertStringNotContainsString('Verbleibende Umsatzsteuer', $this->renderer()->html($this->document(self::versionTwo()))['html']);
    }

    /**
     * Abo-4 (P31): The invoice of a follow-up order names subscription and
     * service period — version 7. Without: no line.
     */
    public function testDieFolgerechnungNenntAboUndLeistungszeitraum(): void
    {
        $payload = self::versionTwo();
        $payload['formatVersion'] = 7;
        $payload['subscription'] = ['number' => 'WEB-00084-1', 'from' => '2026-10-29', 'to' => '2026-11-29'];

        $html = str_replace(["\u{a0}", "\u{202f}"], ' ', $this->renderer()->html($this->document($payload))['html']);

        self::assertMatchesRegularExpression('/<th scope="row">Abo<\/th><td>WEB-00084-1<\/td>/', $html);
        self::assertMatchesRegularExpression('/<th scope="row">Leistungszeitraum<\/th><td>29\.10\.2026 – 29\.11\.2026<\/td>/', $html);

        $plain = $this->renderer()->html($this->document(self::versionTwo()))['html'];
        self::assertStringNotContainsString('Leistungszeitraum', $plain);
        self::assertStringNotContainsString('>Abo<', $plain);
    }

    /**
     * DL4, version 8: With two tax countries the country stands before the
     * rate — at the line and in the summary. With one country as before.
     */
    public function testZweiSteuerlaenderStehenMitLandAufDerRechnung(): void
    {
        $payload = self::versionTwo();
        $payload['formatVersion'] = 8;
        $payload['items'] = array_map(static fn (array $item): array => ['taxCountry' => 'AT'] + $item, $payload['items']);
        $payload['items'][0]['taxCountry'] = 'DE';

        $html = str_replace(["\u{a0}", "\u{202f}"], ' ', $this->renderer()->html($this->document($payload))['html']);

        self::assertMatchesRegularExpression('/<th scope="row">DE \d/', $html, 'Übersicht mit Land');
        self::assertMatchesRegularExpression('/<th scope="row">AT \d/', $html);

        $one = self::versionTwo();
        $one['formatVersion'] = 8;
        $one['items'] = array_map(static fn (array $item): array => ['taxCountry' => 'AT'] + $item, $one['items']);
        self::assertDoesNotMatchRegularExpression('/<th scope="row">AT \d/', str_replace(["\u{a0}", "\u{202f}"], ' ', $this->renderer()->html($this->document($one))['html']), 'ein Land: ohne');
    }

    /**
     * WG3: A voucher sold as not taxable does not appear on the invoice as
     * "0 %" but as "not taxable" — as a summary line of its own after the
     * rates, at the item without a rate.
     */
    public function testDerGutscheinStehtAlsNichtSteuerbarAufDerRechnung(): void
    {
        $payload = self::versionTwo();
        $payload['formatVersion'] = 9;
        $payload['items'][] = ['productName' => 'Gutschein 50 €', 'sku' => 'GS-50', 'quantity' => '1.0000', 'unitPrice' => '50.0000', 'taxRate' => '0.0000', 'taxAmount' => '0.0000', 'lineTotal' => '50.0000', 'priceSource' => 'notTaxable', 'lineType' => 'product', 'taxCountry' => ''];

        $html = str_replace(["\u{a0}", "\u{202f}"], ' ', $this->renderer()->html($this->document($payload))['html']);

        self::assertStringContainsString('<th scope="row">nicht steuerbar</th>', $html);
        self::assertStringNotContainsString('<th scope="row">0 %</th>', $html);
        // After the two rates.
        self::assertGreaterThan(strpos($html, '<th scope="row">20 %</th>'), strpos($html, 'nicht steuerbar'));
    }

    public function testOhneLieferzeitraumKeineZeile(): void
    {
        self::assertStringNotContainsString('Voraussichtl. Lieferung', $this->renderer()->html($this->document(self::versionTwo()))['html']);
    }

    public function testEinUnlesbarerSchnappschussWirdNichtAlsLeereRechnungGedruckt(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->renderer()->html(['id' => 5, 'type' => 'invoice', 'documentNumber' => 'RE-1', 'issuedAt' => 1, 'payload' => '{kaputt']);
    }

    /**
     * dev#2: The delivery note has a template of its own — what is in the
     * parcel, what follows separately, no amount. The address is the delivery
     * address.
     */
    public function testDerLieferscheinNenntPaketUndRestOhnePreise(): void
    {
        $html = $this->renderer()->html($this->deliveryNote(self::versionTwo()))['html'];

        self::assertStringContainsString('Lieferschein LS-00003', $html);
        self::assertStringContainsString('<th scope="row">Lieferscheinnummer</th><td>LS-00003</td>', $html);
        self::assertStringContainsString('Teillieferung', $html);
        self::assertStringContainsString('Inhalt dieser Sendung', $html);
        self::assertStringContainsString('Folgt separat', $html);
        self::assertStringContainsString('Wanderstock', $html);
        self::assertStringContainsString('Rucksack', $html);
        self::assertStringContainsString('CA1AT', $html);
        self::assertStringContainsString('Lieferweg 3', $html);
        self::assertStringNotContainsString('€', $html, 'kein Betrag auf dem Lieferschein');
        self::assertStringNotContainsString('Rechnungsnummer', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    public function testDerVollstaendigeLieferscheinHatKeinenRest(): void
    {
        $html = $this->renderer()->html($this->deliveryNote(self::versionTwo(), complete: true))['html'];

        self::assertStringNotContainsString('Teillieferung', $html);
        self::assertStringNotContainsString('Folgt separat', $html);
    }

    public function testDerLieferscheinIstAuchEnglischVollstaendig(): void
    {
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../contao/languages/en/default.php';

        $html = $this->renderer()->html($this->deliveryNote(self::versionTwo(), language: 'en'))['html'];

        self::assertStringContainsString('Delivery note LS-00003', $html);
        self::assertStringContainsString('To follow separately', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    public function testDerLieferscheinWirdZumPdf(): void
    {
        $cache = sys_get_temp_dir().'/consho-note-'.bin2hex(random_bytes(4));

        try {
            $pdf = $this->renderer(new DompdfRenderer($cache))->pdf($this->deliveryNote(self::versionTwo()))['pdf'];
        } finally {
            foreach (glob($cache.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($cache);
        }

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    /**
     * @param array<string, mixed> $invoice a snapshot of the same order, for its customer
     *
     * @return array<string, mixed>
     */
    private function deliveryNote(array $invoice, bool $complete = false, string $language = 'de'): array
    {
        $order = array_merge($invoice['order'], ['shippingFirstname' => 'Erika', 'shippingLastname' => 'Muster', 'shippingStreet' => 'Lieferweg 3', 'shippingPostal' => '1100', 'shippingCity' => 'Wien', 'shippingCountry' => 'at']);
        $order['language'] = $language;
        $payload = (new DocumentPayloadBuilder())->deliveryNote(
            $order,
            ['issuer' => $invoice['issuer'] ?? [], 'logo' => null, 'notes' => ['footer' => 'Danke']],
            [['productName' => 'Wanderstock', 'sku' => 'WS-1', 'quantity' => '2']],
            $complete ? [] : [['productName' => 'Rucksack', 'sku' => 'RS-1', 'quantity' => '1']],
            ['shippedAt' => '2026-10-21', 'carrier' => 'Post AT', 'trackingNumber' => 'CA1AT'],
        );

        return [
            'id' => 9, 'type' => 'deliveryNote', 'documentNumber' => 'LS-00003', 'issuedAt' => 1790100000, 'orderLanguage' => $language,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * And as a PDF — with the real renderer, once.
     */
    public function testDieRechnungWirdZumPdf(): void
    {
        $cache = sys_get_temp_dir().'/consho-invoice-'.bin2hex(random_bytes(4));

        try {
            // Version 3 with giro code: the PNG has to get through PDF/A mode.
            $pdf = $this->renderer(new DompdfRenderer($cache))->pdf($this->document(self::versionThree()))['pdf'];
        } finally {
            foreach (glob($cache.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($cache);
        }

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    private function renderer(PdfRendererInterface|null $pdf = null, LoggerInterface|null $logger = null, ZugferdInvoice|null $eInvoice = null): InvoiceRenderer
    {
        $loader = new FilesystemLoader();
        /*
         * 🔴 The ROOT of contao/templates, not the document/ folder. Pointed at
         * the folder, the loader found the template under exactly the wrong
         * name the renderer used — the test agreed with the mistake it should
         * have caught, and a real installation answered with a 500. Contao keeps
         * the folder in the name; so does this loader now.
         */
        $loader->addPath(__DIR__.'/../../contao/templates', 'Contao');

        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], string|null $domain = null, string|null $locale = null): string
            {
                $value = $GLOBALS['TL_LANG'];

                foreach (explode('.', $id) as $key) {
                    if (!\is_array($value) || !isset($value[$key])) {
                        return $id;
                    }

                    $value = $value[$key];
                }

                /*
                 * vsprintf, like Contao's own translator for contao_default —
                 * NOT strtr. With strtr this test rendered "vom %date%" happily
                 * while a real installation threw "The arguments array must contain
                 * 2 items" on every cancellation (C-10): the
                 * "%d" in "%date%" is a format for vsprintf.
                 */
                if (!\is_string($value)) {
                    return $id;
                }

                return [] === $parameters ? $value : vsprintf($value, array_values($parameters));
            }

            public function getLocale(): string
            {
                return 'de';
            }
        };

        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));

        return new InvoiceRenderer(
            $twig,
            $translator,
            new InvoiceView(new MoneyFormatter(), new DocumentPayloadBuilder(), $logger ?? new NullLogger()),
            new InvoiceLogo($this->createStub(VirtualFilesystemInterface::class)),
            $pdf ?? new DompdfRenderer(sys_get_temp_dir().'/consho-never-used'),
            $eInvoice,
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function document(array $payload, bool $regenerated = false): array
    {
        return [
            'id' => 1, 'type' => 'invoice', 'documentNumber' => 'RE-00007', 'issuedAt' => 1790000000, 'orderLanguage' => 'de',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Like the invoices issued before C-8: without formatVersion, without
     * lineType, tax per rate raw with four decimals.
     *
     * @return array<string, mixed>
     */
    private static function versionOne(): array
    {
        return [
            'order' => [
                'orderNumber' => 'WEB-00042', 'currency' => 'EUR', 'firstname' => 'Eva', 'lastname' => 'Example',
                'billingStreet' => 'Musterstraße 1', 'billingPostal' => '1100', 'billingCity' => 'Wien', 'billingCountry' => 'AT',
                'netTotal' => '70.0000', 'taxTotal' => '12.0000', 'grandTotal' => '82.0000',
            ],
            'items' => [
                ['productName' => 'Schwedenbitter', 'sku' => 'SB-250', 'quantity' => '1.0000', 'unitPrice' => '50.0000', 'taxRate' => '20.0000', 'taxAmount' => '10.0000', 'lineTotal' => '60.0000', 'priceSource' => 'list'],
                ['productName' => 'Kräutertee', 'sku' => 'KT-100', 'quantity' => '2.0000', 'unitPrice' => '10.0000', 'taxRate' => '10.0000', 'taxAmount' => '2.0000', 'lineTotal' => '22.0000', 'priceSource' => 'list'],
            ],
            'taxSummary' => ['10.0000' => ['net' => '20.0000', 'tax' => '2.0000'], '20.0000' => ['net' => '50.0000', 'tax' => '10.0000']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function versionTwo(): array
    {
        $builder = new DocumentPayloadBuilder();

        $one = self::versionOne();
        $order = $one['order'] + ['language' => 'de', 'createdAt' => '1790000000', 'paymentMethodTitle' => 'Vorkasse', 'shippingMethodTitle' => 'Paket'];
        $items = array_map(static fn (array $item): array => $item + ['lineType' => 'product'], $one['items']);

        // Built by today's builder, stamped as the version it has the shape of:
        // version 3 only adds payment.giroCode, which this one lacks.
        return ['formatVersion' => 2] + $builder->build($order, $items, [
            'issuer' => ['company' => 'Muster GmbH', 'legalForm' => '', 'street' => 'Musterstraße 1', 'postal' => '1100', 'city' => 'Wien', 'country' => 'AT', 'vatId' => 'ATU12345678'],
            'logo' => null,
            'payment' => ['instructions' => '<p>Bitte überweisen Sie den Betrag.</p>', 'details' => ['accountHolder' => 'Muster GmbH', 'iban' => 'AT61 1904 3002 3457 3201']],
            'notes' => ['footer' => 'Firmenbuch FN 1a', 'reverseCharge' => ''],
        ]);
    }

    /**
     * Version 3: like 2, plus the finished giro code content — as
     * DocumentIssuer freezes it after assigning the number.
     *
     * @return array<string, mixed>
     */
    private static function versionThree(): array
    {
        $payload = ['formatVersion' => 3] + self::versionTwo();
        $payload['payment']['giroCode'] = GiroCode::payload($payload['payment']['details'], '82.0000', 'EUR', 'RE-00007');

        return $payload;
    }

    /**
     * Giro code (C-8): The image stands next to the same details in plain
     * text — amount and remittance information included (EPC069-12). And 🔴
     * the printed remittance information is the one the image yields when
     * scanned: the image is taken from the HTML and read back.
     */
    public function testFassungDreiDrucktDenGiroCodeNebenDemKlartext(): void
    {
        $html = $this->renderer()->html($this->document(self::versionThree()))['html'];

        self::assertSame(1, preg_match('#<td class="giro-code">\s*<img src="data:image/png;base64,([^"]+)"#', $html, $match));
        self::assertMatchesRegularExpression('#Verwendungszweck</th><td>RE-00007</td>#', $html);
        self::assertStringContainsString('Zum Bezahlen mit der Banking-App scannen', $html);

        $scanned = (new QRCode(new QROptions()))->readFromBlob((string) base64_decode($match[1], true))->data;
        $lines = explode("\n", $scanned);

        self::assertSame('RE-00007', $lines[10]);
        self::assertSame('EUR82.00', $lines[7]);
        self::assertSame('AT611904300234573201', $lines[6]);
    }

    /**
     * If the frozen code cannot be drawn, the invoice appears without it —
     * with the bank details in plain text — and the system log says so.
     * Before, it disappeared without a word.
     */
    public function testEinUnzeichenbarerCodeFehltUndStehtImLog(): void
    {
        $payload = self::versionThree();
        $payload['payment']['giroCode'] = str_repeat('x', 400);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('RE-00007'));

        $html = $this->renderer(logger: $logger)->html($this->document($payload))['html'];

        self::assertStringNotContainsString('<td class="giro-code">', $html);
        self::assertStringContainsString('AT61 1904 3002 3457 3201', $html);
    }

    /**
     * For screen readers the label is the image's alternative text; the
     * visible line below is hidden, otherwise it would come twice.
     */
    public function testDerCodeHatDieBeschriftungAlsAlternativtext(): void
    {
        $html = $this->renderer()->html($this->document(self::versionThree()))['html'];

        self::assertMatchesRegularExpression('#<img src="data:image/png;base64,[^"]+" alt="Zum Bezahlen mit der Banking-App scannen">#', $html);
        self::assertStringContainsString('<span aria-hidden="true">Zum Bezahlen mit der Banking-App scannen</span>', $html);
    }

    /**
     * The payment block sits at the bottom — in the PDF: payment and footer
     * stand at the foot of the page, an invisible copy in the flow keeps their
     * space free. The HTML version has no page and keeps them in the flow,
     * without a copy (a screen reader would otherwise read everything twice).
     * Checked on the HTML the renderer really hands to the PDF tool.
     */
    public function testImPdfSitztDerAbschlussUntenInDerHtmlFassungImFluss(): void
    {
        $spy = new class implements PdfRendererInterface {
            public string $html = '';

            public function render(string $html): string
            {
                $this->html = $html;

                return '%PDF-';
            }
        };

        $this->renderer($spy)->pdf($this->document(self::versionThree()));
        $flowing = $this->renderer()->html($this->document(self::versionThree()))['html'];

        self::assertSame(1, substr_count($spy->html, '<div class="closing-space" aria-hidden="true">'));
        self::assertSame(1, substr_count($spy->html, '<div class="closing-paged">'));
        self::assertSame(2, substr_count($spy->html, 'Firmenbuch FN 1a'));

        self::assertStringNotContainsString('<div class="closing-', $flowing);
        self::assertSame(1, substr_count($flowing, 'Firmenbuch FN 1a'));
        self::assertSame(1, substr_count($flowing, '<div class="payment">'));
    }

    /**
     * Without payment details and footer there is no closing part — not even
     * an empty invisible copy that would take up space.
     */
    public function testOhneZahlungUndFusszeileKeinAbschluss(): void
    {
        $spy = new class implements PdfRendererInterface {
            public string $html = '';

            public function render(string $html): string
            {
                $this->html = $html;

                return '%PDF-';
            }
        };

        $payload = self::versionThree();
        $payload['payment'] = ['instructions' => '', 'details' => []];
        $payload['notes']['footer'] = '';

        $this->renderer($spy)->pdf($this->document($payload));

        self::assertStringNotContainsString('<div class="closing-', $spy->html);
    }

    /**
     * Block R (R9): Every PDF of a document goes through the e-invoice — it
     * decides on the snapshot whether it embeds the XML.
     */
    public function testDasPdfGehtDurchDieERechnung(): void
    {
        $spy = new class extends ZugferdInvoice {
            /** @var array<string, mixed> */
            public array $payload = [];

            public function embed(string $pdf, array $document, array $payload): string
            {
                $this->payload = $payload;

                return $pdf.'+XML';
            }
        };
        $pdf = new class implements PdfRendererInterface {
            public function render(string $html): string
            {
                return '%PDF-';
            }
        };

        self::assertSame('%PDF-+XML', $this->renderer($pdf, eInvoice: $spy)->pdf($this->document(self::versionThree()))['pdf']);
        self::assertSame(3, $spy->payload['formatVersion']);
    }

    /**
     * The block with invoice number and dates is right-aligned like issuer
     * and totals.
     */
    public function testDieRechnungsdatenSitzenRechtsbuendig(): void
    {
        $html = $this->renderer()->html($this->document(self::versionThree()))['html'];

        self::assertMatchesRegularExpression('#<td class="facts-cell">\s*<table class="facts facts-right">\s*<tr><th scope="row">Rechnungsnummer#', $html);
        self::assertStringContainsString('.facts-right { width: auto; margin-left: auto;', $html);
    }

    /**
     * Control case: version 2 knows no giro code — no second graphic, no
     * "Verwendungszweck" line, the bank details as before.
     */
    public function testOhneGiroCodeBleibtDieBankverbindungWieBisher(): void
    {
        $html = $this->renderer()->html($this->document(self::versionTwo()))['html'];

        self::assertStringNotContainsString('data:image/png', $html);
        self::assertStringNotContainsString('Verwendungszweck', $html);
        self::assertStringContainsString('AT61 1904 3002 3457 3201', $html);
    }
}

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
 * 13.1 „Snapshot-Versionen": Ein Beispiel-Schnappschuss JEDER formatVersion
 * rendert, mit Nummer, Summen und Steuer je Satz.
 *
 * Gegen die echte Vorlage und die echten Sprachdateien — eine Abschrift
 * enthielte den Fehler des Originals nicht (Regel 24). Der Übersetzer liest
 * $GLOBALS['TL_LANG'] wie Contaos eigener, damit ein fehlender Schlüssel als
 * Schlüssel im HTML stehen bleibt und der Test ihn findet.
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
        // ICU trennt mit geschütztem Leerzeichen, damit „20 %" nicht umbricht.
        self::assertMatchesRegularExpression('/10[\x{00A0}\x{202F} ]%/u', $html);
        self::assertMatchesRegularExpression('/20[\x{00A0}\x{202F} ]%/u', $html);
        self::assertStringContainsString('Schwedenbitter', $html);
    }

    /**
     * Jeder Schlüssel der Vorlage steht in der Sprachdatei. Fehlt einer,
     * druckte die Rechnung „CONSHO.invoice.…" — sichtbar für den Kunden.
     */
    #[DataProvider('everyVersion')]
    public function testKeinUnuebersetzterSchluesselBleibtStehen(array $payload): void
    {
        $html = $this->renderer()->html($this->document($payload, regenerated: true), true)['html'];

        self::assertStringNotContainsString('CONSHO.', $html);
    }

    /**
     * C-10: Der Storno JEDER Fassung rendert — er übernimmt die Form der
     * Rechnung, die er storniert, also auch eine von vor C-8. Er nennt sie,
     * trägt ihre Beträge mit umgekehrtem Vorzeichen und fordert keine Zahlung.
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
     * Abnahme D-3: „Rechnungsnummer GS-00001" über „Korrigiert Rechnung
     * RE-00037" las sich widersprüchlich. Michael, 2026-09-27: eigene
     * Beschriftung „nur für die Korrektur" — der Storno behält sie.
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
     * Und dasselbe für Englisch: Beide Sprachdateien tragen alle Schlüssel.
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
     * Fassung 1 wird NIE aus dem heutigen Shop ergänzt: Kein Absender, weil
     * keiner im Schnappschuss steht.
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
     * Der Hinweis „neu erzeugt" (8.3) steht nur, wenn er gilt.
     */
    public function testNeuErzeugtStehtNurWennEsGilt(): void
    {
        self::assertStringNotContainsString('Neu erzeugt', $this->renderer()->html($this->document(self::versionTwo()))['html']);
        self::assertStringContainsString('Neu erzeugt', $this->renderer()->html($this->document(self::versionTwo()), true)['html']);
    }

    /**
     * Fassung 1 speicherte die Steuer je Satz roh vierstellig. Die Ansicht
     * rechnet sie nach der Regel von C-8 neu — sonst stünde neben 3,32 eine
     * Übersicht mit 3,33.
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
     * C-8, Liefertag: Der Zeitraum steht als Datum im Schnappschuss und wird
     * so gedruckt — in einer Zeitzone gelesen und geschrieben. Wien liegt vor
     * UTC; ein Datum, das als Wiener Mitternacht gelesen und in UTC gedruckt
     * wird, wäre der Vortag.
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
     * Michael, 2026-09-24: Die UID des Empfängers steht auf der Rechnung —
     * außer VIES hat sie als ungültig gemeldet. Nicht erreichbar: gedruckt.
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
     * E-2: „Auf Rechnung" nennt den Tag, bis zu dem zu zahlen ist — als
     * Datum aus dem Schnappschuss (Fassung 4), in derselben Zone gedruckt.
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
     * WG12 (Fassung 9): Was vor der Rechnung bezahlt war, steht unter dem
     * Gesamtbetrag, dazu der offene Betrag — und neben dem Giro-Code steht
     * der Betrag, den er verlangt, nicht der ganze.
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
     * E-6b: Nach dem Versand das Lieferdatum statt des Zeitraums — bei einer
     * Teillieferung mit dem Tag, an dem der Rest voraussichtlich folgt.
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
     * Abo-2 (P13): Die Bedingungen eines Abos stehen an seiner Zeile — so,
     * wie sie beim Kauf eingefroren wurden.
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
     * Abo-4 (P31): Die Rechnung einer Folgebestellung nennt Abo und
     * Leistungszeitraum — Fassung 7. Ohne: keine Zeile.
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
     * DL4, Fassung 8: Mit zwei Steuerländern steht das Land vor dem Satz — an
     * der Zeile und in der Übersicht. Mit einem Land wie bisher.
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
     * WG3: Ein Gutschein, der nicht steuerbar verkauft wurde, steht nicht als
     * „0 %" auf der Rechnung, sondern als „nicht steuerbar" — als eigene Zeile
     * der Übersicht nach den Sätzen, an der Position ohne Satz.
     */
    public function testDerGutscheinStehtAlsNichtSteuerbarAufDerRechnung(): void
    {
        $payload = self::versionTwo();
        $payload['formatVersion'] = 9;
        $payload['items'][] = ['productName' => 'Gutschein 50 €', 'sku' => 'GS-50', 'quantity' => '1.0000', 'unitPrice' => '50.0000', 'taxRate' => '0.0000', 'taxAmount' => '0.0000', 'lineTotal' => '50.0000', 'priceSource' => 'notTaxable', 'lineType' => 'product', 'taxCountry' => ''];

        $html = str_replace(["\u{a0}", "\u{202f}"], ' ', $this->renderer()->html($this->document($payload))['html']);

        self::assertStringContainsString('<th scope="row">nicht steuerbar</th>', $html);
        self::assertStringNotContainsString('<th scope="row">0 %</th>', $html);
        // Nach den beiden Sätzen.
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
     * Und als PDF — mit dem echten Renderer, einmal.
     */
    public function testDieRechnungWirdZumPdf(): void
    {
        $cache = sys_get_temp_dir().'/consho-invoice-'.bin2hex(random_bytes(4));

        try {
            // Fassung 3 mit Giro-Code: das PNG muss durch den PDF/A-Modus.
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
         * have caught, and c5 answered with a 500 (2026-09-24). Contao keeps
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
                 * while c5 threw "The arguments array must contain 2 items"
                 * on every cancellation (acceptance C-10, 2026-09-25): the
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
     * Wie die sieben Rechnungen auf c5 vor C-8: ohne formatVersion, ohne
     * lineType, Steuer je Satz roh vierstellig.
     *
     * @return array<string, mixed>
     */
    private static function versionOne(): array
    {
        return [
            'order' => [
                'orderNumber' => 'WEB-00042', 'currency' => 'EUR', 'firstname' => 'Michael', 'lastname' => 'Morgenbesser',
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
     * Fassung 3: wie 2, dazu der fertige Giro-Code-Inhalt — so, wie
     * DocumentIssuer ihn nach der Nummernvergabe einfriert.
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
     * Giro-Code (C-8): Das Bild steht neben denselben Angaben im Klartext —
     * Betrag und Verwendungszweck eingeschlossen (EPC069-12). Und 🔴 der
     * gedruckte Verwendungszweck ist der, den das Bild beim Scannen liefert:
     * Das Bild wird aus dem HTML geholt und zurückgelesen.
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
     * Fable-Review: Lässt sich der eingefrorene Code nicht zeichnen, erscheint
     * die Rechnung ohne ihn — mit den Bankdaten im Klartext — und das
     * Systemlog sagt es. Vorher verschwand er wortlos.
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
     * Für Screenreader ist die Beschriftung der Alternativtext des Bildes; die
     * sichtbare Zeile darunter ist ausgeblendet, sonst käme sie doppelt.
     */
    public function testDerCodeHatDieBeschriftungAlsAlternativtext(): void
    {
        $html = $this->renderer()->html($this->document(self::versionThree()))['html'];

        self::assertMatchesRegularExpression('#<img src="data:image/png;base64,[^"]+" alt="Zum Bezahlen mit der Banking-App scannen">#', $html);
        self::assertStringContainsString('<span aria-hidden="true">Zum Bezahlen mit der Banking-App scannen</span>', $html);
    }

    /**
     * Michael, 2026-09-24: „Kann der Block Payment unten sitzen?" — im PDF
     * ja: Zahlung und Fußzeile stehen am Fuß der Seite, eine unsichtbare
     * Kopie im Fluss hält ihnen den Platz frei. Die HTML-Fassung hat keine
     * Seite und behält sie im Fluss, ohne Kopie (ein Screenreader läse sonst
     * alles zweimal). Geprüft am HTML, das der Renderer dem PDF-Werkzeug
     * wirklich übergibt.
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
     * Ohne Zahlungsangaben und Fußzeile gibt es keinen Abschluss — auch
     * keine leere unsichtbare Kopie, die Platz belegte.
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
     * Block R (R9): Jedes PDF eines Belegs geht durch die E-Rechnung — sie
     * entscheidet am Schnappschuss, ob sie das XML einbettet.
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
     * Der Block mit Rechnungsnummer und Daten sitzt rechtsbündig wie Absender
     * und Summen (Michael, 2026-09-24).
     */
    public function testDieRechnungsdatenSitzenRechtsbuendig(): void
    {
        $html = $this->renderer()->html($this->document(self::versionThree()))['html'];

        self::assertMatchesRegularExpression('#<td class="facts-cell">\s*<table class="facts facts-right">\s*<tr><th scope="row">Rechnungsnummer#', $html);
        self::assertStringContainsString('.facts-right { width: auto; margin-left: auto;', $html);
    }

    /**
     * Gegenprobe: Fassung 2 kennt keinen Giro-Code — keine zweite Grafik,
     * keine Zeile „Verwendungszweck", die Bankangaben wie bisher.
     */
    public function testOhneGiroCodeBleibtDieBankverbindungWieBisher(): void
    {
        $html = $this->renderer()->html($this->document(self::versionTwo()))['html'];

        self::assertStringNotContainsString('data:image/png', $html);
        self::assertStringNotContainsString('Verwendungszweck', $html);
        self::assertStringContainsString('AT61 1904 3002 3457 3201', $html);
    }
}

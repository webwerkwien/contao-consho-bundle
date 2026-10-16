<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\GiroCode;

/**
 * Michael, 2026-09-24: Giro-QR-Code auf der Rechnung, zuschaltbar. Die
 * Regeln stehen in EPC069-12 v3.1, Abschnitt 2 — die Tests prüfen sie einzeln
 * und scannen das Bild zurück, statt nur zu prüfen, dass ein PNG entsteht.
 */
class GiroCodeTest extends TestCase
{
    private const DETAILS = ['accountHolder' => 'Wanderstock GmbH', 'iban' => 'AT611904300234573201', 'bic' => 'BKAUATWW', 'bankName' => 'Bank Austria'];

    public function testDerInhaltFolgtDerReihenfolgeDerRichtlinie(): void
    {
        self::assertSame(
            "BCD\n002\n1\nSCT\nBKAUATWW\nWanderstock GmbH\nAT611904300234573201\nEUR13.90\n\n\nRE-00013",
            GiroCode::payload(self::DETAILS, '13.9000', 'EUR', 'RE-00013'),
        );
    }

    /**
     * „The last populated element is not followed by any character or
     * element separator." Kein Zeilenumbruch am Ende.
     */
    public function testNachDemLetztenElementStehtNichts(): void
    {
        self::assertStringEndsWith("\nRE-00013", (string) GiroCode::payload(self::DETAILS, '13.90', 'EUR', 'RE-00013'));
    }

    public function testDieBicIstInVersion002Freiwillig(): void
    {
        $details = ['bic' => ''] + self::DETAILS;

        self::assertStringContainsString("SCT\n\nWanderstock GmbH", (string) GiroCode::payload($details, '13.90', 'EUR', 'RE-00013'));
    }

    /**
     * Eine falsche BIC würde die Bank zum Leiten benutzen — sie fällt weg,
     * der Code bleibt. Gegenprobe: eine elfstellige bleibt drin.
     */
    public function testEineUngueltigeBicFaelltWegEineElfstelligeBleibt(): void
    {
        self::assertStringContainsString("SCT\n\nWanderstock", (string) GiroCode::payload(['bic' => 'BKAU'] + self::DETAILS, '13.90', 'EUR', 'RE-00013'));
        self::assertStringContainsString("SCT\nBKAUATWWXXX\nWanderstock", (string) GiroCode::payload(['bic' => 'bkauatwwxxx'] + self::DETAILS, '13.90', 'EUR', 'RE-00013'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string, string}>
     */
    public static function keinCode(): iterable
    {
        yield 'nicht in Euro' => [self::DETAILS, '13.90', 'CHF', 'RE-00013'];
        yield 'Betrag null' => [self::DETAILS, '0.00', 'EUR', 'RE-00013'];
        yield 'Betrag über dem Höchstwert' => [self::DETAILS, '1000000000.00', 'EUR', 'RE-00013'];
        yield 'mehr als zwei Nachkommastellen' => [self::DETAILS, '13.905', 'EUR', 'RE-00013'];
        yield 'kein Betrag' => [self::DETAILS, '', 'EUR', 'RE-00013'];
        yield 'keine IBAN' => [['iban' => ''] + self::DETAILS, '13.90', 'EUR', 'RE-00013'];
        yield 'keine IBAN-Form' => [['iban' => 'Konto 4711'] + self::DETAILS, '13.90', 'EUR', 'RE-00013'];
        yield 'kein Kontoinhaber' => [['accountHolder' => ' '] + self::DETAILS, '13.90', 'EUR', 'RE-00013'];
        yield 'Kontoinhaber mit 71 Zeichen' => [['accountHolder' => str_repeat('ä', 71)] + self::DETAILS, '13.90', 'EUR', 'RE-00013'];
        yield 'Zeilenumbruch im Namen' => [['accountHolder' => "Wanderstock\nGmbH"] + self::DETAILS, '13.90', 'EUR', 'RE-00013'];
        yield 'keine Rechnungsnummer' => [self::DETAILS, '13.90', 'EUR', ''];
        // Fable-Review: Unicode-Steuerzeichen und Zeilentrenner, nicht nur ASCII.
        yield 'NEL (U+0085) im Namen' => [['accountHolder' => "Wanderstock\u{0085}GmbH"] + self::DETAILS, '13.90', 'EUR', 'RE-00013'];
        yield 'Zeilentrenner (U+2028) im Namen' => [['accountHolder' => "Wanderstock\u{2028}GmbH"] + self::DETAILS, '13.90', 'EUR', 'RE-00013'];
        yield 'Absatztrenner (U+2029) in der Referenz' => [self::DETAILS, '13.90', 'EUR', "RE-\u{2029}00013"];
        yield 'ungültiges UTF-8 im Namen' => [['accountHolder' => "Wanderstock \xC3"] + self::DETAILS, '13.90', 'EUR', 'RE-00013'];
    }

    /**
     * 🔴 Fable-Review: Der Aufruf steht NACH der Nummernvergabe. Ein
     * Nicht-Text aus einer fremden Quelle darf dort keinen TypeError werfen —
     * er verbrennte eine Rechnungsnummer. Er zählt als fehlend.
     */
    public function testNichtTextInDenBankdatenZaehltAlsFehlendUndWirftNicht(): void
    {
        self::assertNull(GiroCode::payload(['accountHolder' => 5] + self::DETAILS, '13.90', 'EUR', 'RE-00013'));
        self::assertNull(GiroCode::payload(['iban' => null] + self::DETAILS, '13.90', 'EUR', 'RE-00013'));
        self::assertNull(GiroCode::payload(['accountHolder' => ['Wanderstock']] + self::DETAILS, '13.90', 'EUR', 'RE-00013'));
        // Die freiwillige BIC als Zahl fällt weg, der Code bleibt.
        self::assertStringContainsString("SCT\n\nWanderstock", (string) GiroCode::payload(['bic' => 12345678] + self::DETAILS, '13.90', 'EUR', 'RE-00013'));
    }

    /**
     * dataUri() schluckt nichts mehr: Was sich nicht zeichnen lässt, wirft —
     * InvoiceView entscheidet und protokolliert.
     */
    public function testEinUnzeichenbarerInhaltWirft(): void
    {
        $this->expectException(\Throwable::class);

        GiroCode::dataUri(str_repeat('x', 400));
    }

    /**
     * @param array<string, string> $details
     */
    #[DataProvider('keinCode')]
    public function testOhneGueltigeDatenGibtEsKeinenCode(array $details, string $amount, string $currency, string $reference): void
    {
        self::assertNull(GiroCode::payload($details, $amount, $currency, $reference));
    }

    /**
     * Gegenprobe zu den Grenzen: genau 70 Zeichen (Umlaute zählen als
     * Zeichen, nicht als Bytes), 0,01 € und der Höchstbetrag gehen durch.
     */
    public function testDieGrenzwerteSelbstSindErlaubt(): void
    {
        self::assertNotNull(GiroCode::payload(['accountHolder' => str_repeat('ä', 70)] + self::DETAILS, '13.90', 'EUR', 'RE-00013'));
        self::assertStringContainsString("\nEUR0.01\n", (string) GiroCode::payload(self::DETAILS, '0.01', 'EUR', 'RE-00013'));
        self::assertStringContainsString("\nEUR999999999.99\n", (string) GiroCode::payload(self::DETAILS, '999999999.99', 'EUR', 'RE-00013'));
    }

    /**
     * 🔴 Der eigentliche Beweis: Das Bild wird wieder eingescannt, und
     * heraus kommt Byte für Byte der Inhalt — mit Umlaut, also UTF-8 wie in
     * Zeile drei angesagt —, bei Fehlerkorrektur M und höchstens Version 13.
     */
    public function testDasBildLiefertBeimScannenGenauDenInhalt(): void
    {
        $payload = (string) GiroCode::payload(['accountHolder' => 'Grüner Weg Wanderbedarf e.U.'] + self::DETAILS, '1234.50', 'EUR', 'RE-00013');
        $uri = (string) GiroCode::dataUri($payload);

        self::assertStringStartsWith('data:image/png;base64,', $uri);

        $png = (string) base64_decode(substr($uri, \strlen('data:image/png;base64,')), true);
        $result = (new QRCode(new QROptions()))->readFromBlob($png);

        self::assertSame($payload, $result->data);
        self::assertSame('M', (string) $result->eccLevel);
        self::assertLessThanOrEqual(13, $result->version->getVersionNumber());

        // PNG-Farbtyp 2 = RGB ohne Alphakanal: nichts, was PDF/A beanstanden könnte.
        self::assertSame(2, \ord($png[25]));
    }

    /**
     * Längster zulässiger Inhalt: 70 Umlaute (140 Bytes) und 140 Zeichen
     * Verwendungszweck ergeben mehr als 331 Bytes — kein Code. Die Grenze
     * gilt den Bytes, nicht den Zeichen.
     */
    public function testMehrAls331BytesErgebenKeinenCode(): void
    {
        self::assertNull(GiroCode::payload(['accountHolder' => str_repeat('ä', 70)] + self::DETAILS, '13.90', 'EUR', str_repeat('R', 140)));
        self::assertNotNull(GiroCode::payload(self::DETAILS, '13.90', 'EUR', str_repeat('R', 140)));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\GiroCode;

/**
 * Giro QR code on the invoice, optional. The rules are in EPC069-12 v3.1,
 * section 2 — the tests check them one by one and scan the image back,
 * instead of only checking that a PNG comes out.
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
     * "The last populated element is not followed by any character or
     * element separator." No line break at the end.
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
     * A wrong BIC would be used by the bank for routing — it is dropped, the
     * code stays. Control case: an eleven-character one stays in.
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
        // Unicode control characters and line separators, not only ASCII.
        yield 'NEL (U+0085) im Namen' => [['accountHolder' => "Wanderstock\u{0085}GmbH"] + self::DETAILS, '13.90', 'EUR', 'RE-00013'];
        yield 'Zeilentrenner (U+2028) im Namen' => [['accountHolder' => "Wanderstock\u{2028}GmbH"] + self::DETAILS, '13.90', 'EUR', 'RE-00013'];
        yield 'Absatztrenner (U+2029) in der Referenz' => [self::DETAILS, '13.90', 'EUR', "RE-\u{2029}00013"];
        yield 'ungültiges UTF-8 im Namen' => [['accountHolder' => "Wanderstock \xC3"] + self::DETAILS, '13.90', 'EUR', 'RE-00013'];
    }

    /**
     * 🔴 The call comes AFTER the number is assigned. A non-string from a
     * foreign source must not throw a TypeError there — it would burn an
     * invoice number. It counts as missing.
     */
    public function testNichtTextInDenBankdatenZaehltAlsFehlendUndWirftNicht(): void
    {
        self::assertNull(GiroCode::payload(['accountHolder' => 5] + self::DETAILS, '13.90', 'EUR', 'RE-00013'));
        self::assertNull(GiroCode::payload(['iban' => null] + self::DETAILS, '13.90', 'EUR', 'RE-00013'));
        self::assertNull(GiroCode::payload(['accountHolder' => ['Wanderstock']] + self::DETAILS, '13.90', 'EUR', 'RE-00013'));
        // The optional BIC as a number is dropped, the code stays.
        self::assertStringContainsString("SCT\n\nWanderstock", (string) GiroCode::payload(['bic' => 12345678] + self::DETAILS, '13.90', 'EUR', 'RE-00013'));
    }

    /**
     * dataUri() no longer swallows anything: what cannot be drawn throws —
     * InvoiceView decides and logs.
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
     * Control case for the limits: exactly 70 characters (umlauts count as
     * characters, not as bytes), € 0.01 and the maximum amount get through.
     */
    public function testDieGrenzwerteSelbstSindErlaubt(): void
    {
        self::assertNotNull(GiroCode::payload(['accountHolder' => str_repeat('ä', 70)] + self::DETAILS, '13.90', 'EUR', 'RE-00013'));
        self::assertStringContainsString("\nEUR0.01\n", (string) GiroCode::payload(self::DETAILS, '0.01', 'EUR', 'RE-00013'));
        self::assertStringContainsString("\nEUR999999999.99\n", (string) GiroCode::payload(self::DETAILS, '999999999.99', 'EUR', 'RE-00013'));
    }

    /**
     * 🔴 The actual proof: the image is scanned back in, and out comes the
     * content byte for byte — with an umlaut, so UTF-8 as announced in line
     * three —, at error correction M and at most version 13.
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

        // PNG colour type 2 = RGB without alpha channel: nothing PDF/A could object to.
        self::assertSame(2, \ord($png[25]));
    }

    /**
     * Longest permitted content: 70 umlauts (140 bytes) and 140 characters of
     * remittance information make more than 331 bytes — no code. The limit
     * applies to the bytes, not to the characters.
     */
    public function testMehrAls331BytesErgebenKeinenCode(): void
    {
        self::assertNull(GiroCode::payload(['accountHolder' => str_repeat('ä', 70)] + self::DETAILS, '13.90', 'EUR', str_repeat('R', 140)));
        self::assertNotNull(GiroCode::payload(self::DETAILS, '13.90', 'EUR', str_repeat('R', 140)));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * The Giro-Code (EPC QR code) on an invoice: a banking app scans it and fills
 * in the transfer.
 *
 * Built after EPC069-12 v3.1 ("Quick Response Code — Guidelines to Enable the
 * Data Capture for the Initiation of an SCT", 19 March 2024), section 2:
 * error correction M, at most QR version 13 and 331 bytes, version 002 (BIC
 * optional within the EEA), character set 1 = UTF-8, name 1..70, IBAN 1..34,
 * amount in euro from 0.01 to 999999999.99, and EITHER a structured OR an
 * unstructured remittance. The guideline is written for exactly this case:
 * the data in the code is also printed as plain text on the invoice, so the
 * payer can compare — which is why the template prints the reference next to
 * the code.
 *
 * Not tied to prepayment: the code needs a bank connection, an amount and a
 * document number, nothing else. A later "purchase on account" takes over the
 * bank section of the payment method and gets the code with it.
 *
 * No code rather than a wrong one: every rule the data breaks makes payload()
 * return null, and the invoice goes out without the code, otherwise the same.
 */
final class GiroCode
{
    private const MAX_BYTES = 331;

    private const MAX_VERSION = 13;

    /**
     * @param array<string, mixed> $details bank details from the snapshot (accountHolder, iban, bic, …)
     * @param string                $reference the document number, sent as unstructured remittance
     */
    public static function payload(array $details, string $amount, string $currency, string $reference): string|null
    {
        if ('EUR' !== $currency) {
            return null;
        }

        /*
         * Strings only — anything else counts as missing. DocumentIssuer calls
         * this AFTER the invoice number is allocated, and DocumentSourceInterface
         * is open to other sources: a number or null reaching trim() under
         * strict_types would be a TypeError there, and a burnt number in a
         * sequence that must stay unbroken.
         */
        $text = static fn (mixed $value): string => \is_string($value) ? $value : '';

        $name = trim($text($details['accountHolder'] ?? null));
        $iban = strtoupper(preg_replace('/\s+/', '', $text($details['iban'] ?? null)) ?? '');
        $bic = strtoupper(trim($text($details['bic'] ?? null)));
        $reference = trim($reference);

        if ('' === $name || mb_strlen($name) > 70 || !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/', $iban)) {
            return null;
        }

        if ('' === $reference || mb_strlen($reference) > 140) {
            return null;
        }

        // The BIC is optional in version 002; a malformed one is left out
        // rather than sent to a bank that would try to route by it.
        if (!preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic)) {
            $bic = '';
        }

        // Every element is one line. A line break inside a value would shift
        // every element after it — the amount would land in the purpose. With
        // /u the class also covers the Unicode control characters (NEL
        // U+0085 among them) and the line and paragraph separators; a banking
        // app would not split on those, but they have no place in a name.
        // Invalid UTF-8 makes preg_match() return false: no code either.
        foreach ([$name, $reference] as $value) {
            if (1 !== preg_match('/^[^\p{Cc}\p{Zl}\p{Zp}]*$/u', $value)) {
                return null;
            }
        }

        try {
            // Unnecessary: an amount with more than two decimals is not a euro
            // amount the invoice printed, and rounding it here would put a
            // different figure into the code than onto the paper.
            $euro = BigDecimal::of($amount)->toScale(2, RoundingMode::Unnecessary);
        } catch (MathException) {
            return null;
        }

        if ($euro->isLessThan('0.01') || $euro->isGreaterThan('999999999.99')) {
            return null;
        }

        $payload = implode("\n", [
            'BCD',
            '002',
            '1',
            'SCT',
            $bic,
            $name,
            $iban,
            'EUR'.$euro,
            '',          // purpose
            '',          // structured remittance — only one of the two
            $reference,  // unstructured remittance, the last populated element
        ]);

        return \strlen($payload) <= self::MAX_BYTES && mb_check_encoding($payload, 'UTF-8') ? $payload : null;
    }

    /**
     * The code as a PNG data: URI — DompdfRenderer loads nothing from outside.
     * GD writes it without an alpha channel, so it is valid in PDF/A.
     *
     * Throws when it cannot be drawn — GD missing, or the payload beyond
     * version 13 at level M (payload() keeps it within 331 bytes, so that
     * would be a library change). The caller decides: InvoiceView logs it and
     * prints the invoice without the code, instead of this method swallowing
     * it — a code that vanished silently would be
     * noticed by nobody.
     */
    public static function dataUri(string $payload): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => EccLevel::M,
            'versionMax' => self::MAX_VERSION,
            'scale' => 8,
            'outputBase64' => true,
        ]);

        // A byte segment, not render($payload): render() picks the first mode
        // whose validator accepts the string, and the EPC payload is bytes in
        // the character set the third line names.
        $uri = (new QRCode($options))->addByteSegment($payload)->render();

        if (!\is_string($uri) || !str_starts_with($uri, 'data:image/png;base64,')) {
            throw new \UnexpectedValueException('The QR library did not return a PNG data URI.');
        }

        return $uri;
    }
}

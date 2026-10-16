<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Address;

/**
 * Brings phone numbers into E.164 form.
 *
 * Deliberately conservative: it normalises FORMAT, it does not validate that a
 * number exists. Where the country is unknown or unsupported it returns the
 * input unchanged — a wrong country code is invisible and makes the number
 * unreachable, while an un-normalised number merely looks unfinished.
 *
 * The dialling codes cover the countries a DACH shop actually ships to. For
 * worldwide coverage the upgrade path is giggsey/libphonenumber-for-php; it is
 * not pulled in here because it would be a large dependency for a handful of
 * countries.
 */
final class PhoneNormalizer
{
    private const DIALLING_CODES = [
        'AT' => '43', 'DE' => '49', 'CH' => '41', 'LI' => '423',
        'IT' => '39', 'FR' => '33', 'NL' => '31', 'BE' => '32',
        'LU' => '352', 'CZ' => '420', 'SK' => '421', 'SI' => '386',
        'HU' => '36', 'PL' => '48', 'DK' => '45', 'ES' => '34',
    ];

    public function normalize(string $phone, string $country): string
    {
        $trimmed = trim($phone);

        if ('' === $trimmed) {
            return '';
        }

        /*
         * "+43 (0) 664 …" is a common way of writing a number that works both
         * nationally and from abroad. The bracketed trunk zero belongs to the
         * national form only — stripping non-digits without removing it first
         * produces +430664…, which looks right and does not connect.
         */
        $trimmed = (string) preg_replace('/\(\s*0\s*\)/', '', $trimmed);

        // Already international: keep it. The customer may well have a number
        // in a country other than their address.
        if (str_starts_with($trimmed, '+')) {
            return '+'.preg_replace('/\D/', '', substr($trimmed, 1));
        }

        // 00 is the international prefix in every country this ships to.
        $digitsOnly = (string) preg_replace('/\D/', '', $trimmed);

        if (str_starts_with($digitsOnly, '00')) {
            return '+'.substr($digitsOnly, 2);
        }

        $code = self::DIALLING_CODES[strtoupper($country)] ?? null;

        if (null === $code) {
            return trim($phone);
        }

        // National form: drop the trunk prefix 0, prepend the dialling code.
        if (str_starts_with($digitsOnly, '0')) {
            return '+'.$code.substr($digitsOnly, 1);
        }

        return trim($phone);
    }
}

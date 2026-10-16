<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

/**
 * The code of a voucher (block Wertgutscheine, WG5).
 *
 * 16 characters from an alphabet without the ones people mix up — no 0/O,
 * no 1/I/L — so a code read off paper is typed right the first time. 31
 * characters to the power of 16 is about 7 × 10²³ codes; with the brake on
 * the basket (CodeAttemptLimit, ten wrong ones per quarter of an hour) nobody
 * guesses one.
 *
 * Stored bare, shown in groups of four. What a customer types is brought into
 * the stored shape first (Michael, 2026-10-06: „ja, Großbuchstaben
 * umwandeln"): upper case, without spaces and dashes. That costs nothing —
 * the alphabet has no lower case to tell apart. Discount codes of block H keep
 * W6, case and all; this is for vouchers only.
 */
final class VoucherCode
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 16;

    public static function generate(): string
    {
        $max = \strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * What was typed, in the stored shape — or '' when it cannot be a voucher
     * code at all, so nobody needs to look it up.
     */
    public static function normalise(string $typed): string
    {
        $code = strtoupper(str_replace([' ', '-', "\u{a0}"], '', trim($typed)));

        if (self::LENGTH !== \strlen($code) || \strlen($code) !== strspn($code, self::ALPHABET)) {
            return '';
        }

        return $code;
    }

    public static function format(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    /**
     * The last group only, for places that name a voucher without handing it
     * out: a payment row, a log line.
     */
    public static function masked(string $code): string
    {
        return '…'.substr($code, -4);
    }
}

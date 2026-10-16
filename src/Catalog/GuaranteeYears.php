<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * The duration of the manufacturer's durability guarantee (block GARAN, GA9).
 *
 * Whole years, or half ones: "The duration in years should be in full numbers
 * … However, if needed, also 0,5 (half) years can be displayed (for example:
 * 2,5 or 4,5 …). No other decimals different than 0,5 can be used"
 * (Commission guidelines, April 2026, 3.1 vi).
 *
 * Canonical form "5" or "2.5" — what the arrays carry and the DB column
 * (decimal(3,1)) takes. The label itself writes "2,5"; the text follows the
 * language's decimal separator (MSC.decimalSeparator), the way the sentence
 * next to it is translated.
 */
final class GuaranteeYears
{
    public const NONE = '0';

    /**
     * Input or a stored value — "2,5", "2.5", "5", "5.0" (MySQL's decimal) —
     * in canonical form; null when it is not whole or half years.
     */
    public static function parse(mixed $value): string|null
    {
        if (null === $value || \is_bool($value) || \is_array($value) || \is_object($value)) {
            return null === $value ? self::NONE : null;
        }

        $text = str_replace(',', '.', trim((string) $value));

        if ('' === $text) {
            return self::NONE;
        }

        if (1 !== preg_match('/^(\d{1,2})(?:\.(0+|50*))?$/', $text, $match)) {
            return null;
        }

        $whole = (string) (int) $match[1];

        return isset($match[2]) && str_starts_with($match[2], '5') ? $whole.'.5' : $whole;
    }

    /**
     * A stored value in canonical form; anything unreadable counts as none.
     */
    public static function of(mixed $stored): string
    {
        return self::parse($stored) ?? self::NONE;
    }

    /**
     * "mehr als zwei Jahre" (§ 4 Abs. 1 Z 12a FAGG) — 2.5 is.
     */
    public static function qualifies(string $canonical): bool
    {
        return (float) $canonical > 2;
    }

    public static function isNone(string $canonical): bool
    {
        return 0.0 === (float) $canonical;
    }

    public static function display(string $canonical, string $decimalSeparator): string
    {
        return str_replace('.', $decimalSeparator, $canonical);
    }
}

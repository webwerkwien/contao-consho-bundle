<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * The global trade item number, checked rather than merely counted.
 *
 * ⚠️ A mistyped GTIN is worse than none at all. It looks valid, travels into
 * the JSON-LD, into price comparison sites and onto the delivery note — and it
 * identifies SOMEBODY ELSE'S article. The check digit is what catches that, and
 * a regular expression cannot compute one.
 *
 * One value, four lengths. Schema.org has carried a collective `gtin` property
 * since 2021, so the length decides the kind instead of the operator being
 * asked a question they could read off the number.
 */
final class Gtin
{
    /**
     * The lengths the standard knows. Ten digits is not a GTIN, however well
     * the check digit adds up.
     *
     * @var array<int, string>
     */
    private const KINDS = [
        8 => 'gtin8',
        12 => 'gtin12',
        13 => 'gtin13',
        14 => 'gtin14',
    ];

    /**
     * Printed numbers are grouped, and an operator copies what they see.
     * Refusing "4 006381 333931" would be pedantry, not a check.
     */
    public static function normalise(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    /**
     * An empty value counts as valid: not every article has a GTIN, and absent
     * is a legitimate state. Only a WRONG one is a problem.
     */
    public static function isValid(string $value): bool
    {
        // Letters are dropped by normalise(), so "abc" would otherwise pass as
        // empty. Anything that is not digits and separators is a typo, not a
        // number formatted differently.
        if ('' !== trim($value) && !preg_match('/^[0-9 \-.]+$/', trim($value))) {
            return false;
        }

        $digits = self::normalise($value);

        if ('' === $digits) {
            return true;
        }

        if (!isset(self::KINDS[\strlen($digits)])) {
            return false;
        }

        return (int) $digits[-1] === self::checkDigit(substr($digits, 0, -1));
    }

    /**
     * Which schema.org property Block B should emit.
     */
    public static function kind(string $value): ?string
    {
        return self::KINDS[\strlen(self::normalise($value))] ?? null;
    }

    /**
     * Modulo 10: from the right, alternating weights three and one.
     */
    private static function checkDigit(string $body): int
    {
        $sum = 0;
        $weight = 3;

        for ($i = \strlen($body) - 1; $i >= 0; --$i) {
            $sum += (int) $body[$i] * $weight;
            $weight = 4 - $weight;
        }

        return (10 - $sum % 10) % 10;
    }
}

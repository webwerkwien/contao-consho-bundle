<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart\Discount;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * A cart rule that states an amount — a fixed discount or a minimum order
 * value — needs its currency (H): CartDiscounts compares the
 * rule's currency with the basket's, and '' matches none.
 *
 * The rule itself, without a request (Connect Stufe 2, CW-4b, H3): the mask
 * asks it with the posted form (CartRuleCurrencyListener), a command with the
 * record as it will be stored.
 */
final class CartRuleCurrency
{
    public static function isMissing(string $currency, string $effect, mixed $minimumOrder): bool
    {
        if ('' !== trim($currency)) {
            return false;
        }

        return 'amount' === $effect || self::positive($minimumOrder);
    }

    private static function positive(mixed $value): bool
    {
        if (!\is_scalar($value) || '' === trim((string) $value)) {
            return false;
        }

        try {
            return BigDecimal::of(str_replace(',', '.', trim((string) $value)))->isPositive();
        } catch (MathException) {
            return false;
        }
    }
}

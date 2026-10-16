<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Turns what a visitor typed into a quantity the basket can store, or null.
 *
 * Rounding UP onto the step rather than refusing: somebody who types 7 where
 * the step is 2 gets 8 and sees it in the basket. An error message would not
 * tell them anything they could not read off the corrected number.
 *
 * The step counts FROM the minimum, not from zero: minimum 5 with step 3
 * allows 5, 8, 11 — the pack sizes a seller means when they write that down.
 *
 * Deliberately NOT a check of stock. "Sold out" is not a quantity rule and is
 * not a visibility rule either (see VisibilityCriteria); Consho has no stock.
 */
final class Quantity
{
    /**
     * The column is decimal(10,4) — six digits before the point and four
     * after (contao/dca/tl_consho_cart_item.php, tl_consho_order_item.php,
     * tl_consho_product.minPurchase). A number the column cannot hold is not
     * a quantity, so it is refused rather than truncated by the database.
     *
     * Public because the same ceiling has to hold for a SUM: DbalCartRepository
     * puts it into the WHERE of its adding UPDATE, so that several individually
     * valid adds cannot walk past it together. One ceiling, read twice — a
     * second copy of the number would be the one that stays wrong.
     */
    public const MAX = '999999';

    private const SCALE = 4;

    public static function normalise(string $input, string $minimum, string $step): string|null
    {
        try {
            $quantity = BigDecimal::of(trim($input));
            // tl_consho_product.minPurchase/purchaseSteps are NOT NULL DEFAULT
            // '1.0000' (see CatalogSchema), so an empty string never actually
            // arrives here from PurchaseCheck's callers today. The fallback to
            // '0' stays as this method's own contract for OTHER callers of
            // this public API that may not have a value at all — not dead
            // code, just currently unreached by the one caller in this bundle.
            $min = BigDecimal::of('' === trim($minimum) ? '0' : trim($minimum));
            $increment = BigDecimal::of('' === trim($step) ? '0' : trim($step));

            if ($quantity->isLessThanOrEqualTo(0) || $quantity->isGreaterThan(self::MAX)) {
                return null;
            }

            if ($min->isPositive() && $quantity->isLessThan($min)) {
                return null;
            }

            if ($increment->isPositive()) {
                $base = $min->isPositive() ? $min : $increment;
                $steps = $quantity->minus($base)->dividedBy($increment, 0, RoundingMode::Ceiling);

                // Unreachable given the checks above: when $min is positive,
                // $quantity >= $min is already enforced, so $quantity - $base
                // cannot be negative; otherwise $base = $increment and
                // $quantity > 0 keeps ($quantity - $base) / $increment above
                // -1, and RoundingMode::Ceiling never rounds that below 0.
                // Kept as a guard rather than removed: it costs nothing here,
                // and it is the one line that would catch a future change to
                // either check above silently breaking that invariant.
                if ($steps->isNegative()) {
                    return null;
                }

                $quantity = $base->plus($increment->multipliedBy($steps));

                if ($quantity->isGreaterThan(self::MAX)) {
                    // Rounding up may cross the column's ceiling on its own.
                    return null;
                }
            }

            // RoundingMode::Unnecessary throws instead of rounding: a quantity
            // like 1.00005 must not quietly become 1.0000. The catch below
            // turns that into null — the caller shows "invalid quantity", not
            // a server error.
            return (string) $quantity->toScale(self::SCALE, RoundingMode::Unnecessary);
        } catch (MathException) {
            // Not only $input comes from a visitor: $minimum and $step are
            // read out of tl_consho_product (minPurchase/purchaseSteps) by the
            // caller and parsed in this same try block. A single catch is
            // kept deliberately rather than splitting it in two — the caller
            // (PurchaseCheck) turns either failure into the same
            // INVALID_QUANTITY notice and has no separate handling for "the
            // product's own data is broken" today, so two branches would not
            // let it react any differently. What matters is only recorded
            // here in words: a MathException from this block can mean a
            // malformed product row, not just a visitor's typo.
            return null;
        }
    }
}

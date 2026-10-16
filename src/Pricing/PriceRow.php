<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * One row of tl_consho_price, as the calculator sees it.
 *
 * `customerGroup` is null for a price that applies to everyone, rather than 0
 * as the column stores it — the calculator asks "is this row group-specific?",
 * and null answers that without anyone remembering what 0 means.
 *
 * No gross. The column still exists until C-1b, but gross depends on a country
 * the price row does not know — see GrossCalculator (C22, C25).
 *
 * The validity window since block H: it decides a tie of two rows of equal
 * rank (H2) and makes a row a promotion (H1). Null is "no boundary", as in
 * the column.
 */
final class PriceRow
{
    public function __construct(
        public readonly string $net,
        public readonly string $currency,
        public readonly int|null $customerGroup,
        public readonly string $minQuantity,
        public readonly int|null $validFrom = null,
        public readonly int|null $validTo = null,
    ) {
    }

    /**
     * A row with a start or an end — a promotion or a planned price change,
     * as opposed to the standing price.
     */
    public function isWindowed(): bool
    {
        return null !== $this->validFrom || null !== $this->validTo;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

use Brick\Math\BigDecimal;

/**
 * What a single-purpose voucher may pay of a basket (spec Wertgutscheine,
 * EZ-1, F2, F4): the gross of its articles of one tax class, taxed in one
 * country at one rate, after the cart rule's discount — no shipping, no fee.
 */
final class CoverablePot
{
    public function __construct(
        public readonly int $taxClass,
        public readonly string $taxCountry,
        public readonly string $taxRate,
        public readonly string $amount,
    ) {
    }

    /**
     * Same class, same country, and the rate of today the rate its sale was
     * taxed at (F4) — a changed rate is no longer the tax fixed at the issue.
     */
    public function fits(SinglePurpose $voucher): bool
    {
        return $this->taxClass === $voucher->taxClass
            && strtoupper($this->taxCountry) === $voucher->taxCountry
            && BigDecimal::of($this->taxRate)->isEqualTo($voucher->taxRate);
    }
}

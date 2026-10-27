<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What makes a voucher single-purpose (spec Wertgutscheine, EZ-1, EZ-5):
 * the tax class it pays for and the rate and country its sale was taxed at,
 * frozen when it is issued.
 *
 * Only a sale that was taxed makes one — a class above 0 and a rate above
 * 0 —, so a voucher that carries this never lets a purchase leave out tax
 * that nobody charged.
 */
final class SinglePurpose
{
    public readonly string $taxRate;

    public readonly string $taxCountry;

    public function __construct(
        public readonly int $taxClass,
        string $taxRate,
        string $taxCountry,
    ) {
        if ($taxClass < 1 || !BigDecimal::of($taxRate)->isPositive() || '' === trim($taxCountry)) {
            throw new \InvalidArgumentException('A single-purpose voucher needs a tax class, a rate above 0 and a country.');
        }

        $this->taxRate = (string) BigDecimal::of($taxRate)->toScale(4, RoundingMode::HalfUp);
        $this->taxCountry = strtoupper(trim($taxCountry));
    }

    /**
     * The tax an amount paid with this voucher already carried (EZ-4): the
     * gross less its net, the net rounded to the currency's places. A line
     * is split the same way (net rounded, tax the rest), so a voucher that
     * covers a line exactly carries that line's tax to the cent — rounding
     * the tax instead put the two a cent apart on a half cent (11.25 at 20 %:
     * 1.88 against the line's 1.87).
     */
    public function taxIn(string $amount, int $digits): string
    {
        $rate = BigDecimal::of($this->taxRate);
        $gross = BigDecimal::of($amount);

        return (string) $gross
            ->minus($gross->multipliedBy(100)->dividedBy($rate->plus(100), $digits, RoundingMode::HalfUp))
            ->toScale(4)
        ;
    }

    /**
     * From a voucher row; null for a multi-purpose one.
     *
     * @param array<string, mixed> $row
     */
    public static function of(array $row): self|null
    {
        $class = (int) ($row['taxClass'] ?? 0);

        return $class > 0 ? new self($class, (string) ($row['taxRate'] ?? '0'), (string) ($row['taxCountry'] ?? '')) : null;
    }
}

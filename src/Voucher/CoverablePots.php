<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

use Brick\Math\BigDecimal;
use Webwerkwien\ContaoConshoBundle\Cart\CartLine;
use Webwerkwien\ContaoConshoBundle\Checkout\ShippingShare;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;

/**
 * What single-purpose vouchers may pay of a basket (spec Wertgutscheine,
 * EZ-1, F2, F4): per tax class and country, the gross of the articles, less
 * the cart rule's discount on them, at today's rate.
 *
 * Not shipping, not a payment fee (F2), not a voucher line (WG8). Under
 * reverse charge nothing: no rate was taxed that a voucher could stand for.
 */
class CoverablePots
{
    public function __construct(private readonly TaxResolver $taxes)
    {
    }

    /**
     * @param list<CartLine>      $lines
     * @param list<ShippingShare> $discountShares the cart rule's shares, negative, with their gross
     * @param string              $country        where a share without a country of its own is taxed
     *
     * @return list<CoverablePot>
     */
    public function of(array $lines, array $discountShares, int $now, bool $reverseCharge, string $country = ''): array
    {
        if ($reverseCharge) {
            return [];
        }

        $amounts = [];

        foreach ($lines as $line) {
            if ($line->kind->isVoucher() || $line->taxClassId < 1 || '' === $line->taxCountry) {
                continue;
            }

            $key = $line->taxClassId.'|'.strtoupper($line->taxCountry);
            $amounts[$key] = ($amounts[$key] ?? BigDecimal::zero())->plus($line->lineGross);
        }

        foreach ($discountShares as $share) {
            $key = $share->taxClassId.'|'.strtoupper('' !== $share->taxCountry ? $share->taxCountry : $country);

            if (isset($amounts[$key])) {
                $amounts[$key] = $amounts[$key]->plus($share->gross);
            }
        }

        $pots = [];

        foreach ($amounts as $key => $amount) {
            [$class, $country] = explode('|', (string) $key, 2);

            try {
                $rate = $this->taxes->resolveRate((int) $class, $country, $now);
            } catch (TaxRateNotFound) {
                continue;
            }

            $pots[] = new CoverablePot((int) $class, $country, $rate, (string) $amount->toScale(4));
        }

        return $pots;
    }

    /**
     * The cart rule's net shares as of() takes them: grossed at the rate of
     * what they reduce, and negative — the same as the checkout makes them
     * (CheckoutCalculator), so that the basket's preview of a single-purpose
     * voucher does not promise more than the checkout then takes.
     *
     * @param list<ShippingShare> $netShares
     * @param string              $country   where a share without a country of its own is taxed
     *
     * @return list<ShippingShare>
     */
    public function discountShares(array $netShares, string $country, int $now): array
    {
        $gross = new GrossCalculator($this->taxes);

        return array_map(
            static function (ShippingShare $share) use ($gross, $country, $now): ShippingShare {
                $at = '' !== $share->taxCountry ? $share->taxCountry : $country;

                try {
                    $amount = '' === $at ? $share->net : $gross->gross($share->net, $share->taxClassId, $at, $now);
                } catch (TaxRateNotFound) {
                    // As the checkout: without a rate the amount stays as it is.
                    $amount = $share->net;
                }

                return new ShippingShare($share->taxClassId, (string) BigDecimal::of($share->net)->negated(), (string) BigDecimal::of($amount)->negated(), $share->taxCountry);
            },
            $netShares,
        );
    }
}

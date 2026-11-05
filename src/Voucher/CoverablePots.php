<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Webwerkwien\ContaoConshoBundle\Cart\CartLine;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
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
     * @param string              $country         where a share without a country of its own is taxed
     * @param string              $deliveryCountry where the goods go; empty where no address is known yet
     * @param array<int, string>  $lineDiscounts   the rule's net discount per line, by its key in $lines
     *                                             (CartDiscount::$lines), positive
     *
     * @return list<CoverablePot>
     */
    public function of(array $lines, array $discountShares, int $now, bool $reverseCharge, string $country = '', string $deliveryCountry = '', array $lineDiscounts = []): array
    {
        if ($reverseCharge) {
            return [];
        }

        $amounts = [];
        // The gross of each class and country before the delivery country
        // leaves lines out, and the lines kept, by their position.
        $totals = [];
        $kept = [];
        $deliveryCountry = strtoupper(trim($deliveryCountry));

        foreach ($lines as $position => $line) {
            if ($line->kind->isVoucher() || $line->taxClassId < 1 || '' === $line->taxCountry) {
                continue;
            }

            $key = $line->taxClassId.'|'.strtoupper($line->taxCountry);
            $totals[$key] = ($totals[$key] ?? BigDecimal::zero())->plus($line->lineGross);

            /*
             * A single-purpose voucher fixes the place of supply at its issue
             * (Art. 30a no. 2 VAT Directive; AT VAT guidelines, para. 4: one
             * valid "in ganz Österreich" is the example). Goods or a download
             * taxed in the shop's country only because the distance sale stays
             * below the threshold are not supplied there when they go abroad,
             * so no voucher of that country pays for them. A service is taxed
             * where it is performed (ProductKind), wherever the customer lives.
             */
            if ('' !== $deliveryCountry && ProductKind::Service !== $line->kind && strtoupper($line->taxCountry) !== $deliveryCountry) {
                continue;
            }

            $amounts[$key] = ($amounts[$key] ?? BigDecimal::zero())->plus($line->lineGross);
            $kept[$key][$position] = $line;
        }

        foreach ($discountShares as $share) {
            $key = $share->taxClassId.'|'.strtoupper('' !== $share->taxCountry ? $share->taxCountry : $country);

            if (!isset($amounts[$key])) {
                continue;
            }

            $amounts[$key] = $amounts[$key]->plus(
                $amounts[$key]->isEqualTo($totals[$key])
                    ? BigDecimal::of($share->gross)
                    : $this->partOnKept($share, $kept[$key], $amounts[$key], $totals[$key], $lineDiscounts, $now),
            );
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
     * What of the cart rule's share falls on the lines a pot kept, where the
     * delivery country left others of its class and country out: the whole
     * share would charge the discount of goods no voucher pays for to this pot.
     *
     * Exact with the rule's split per line — a rule may reach only some
     * products, or skip promotion prices, so the share is not spread evenly.
     * Without it, by the gross kept, rounded towards the larger discount so the
     * pot never promises more.
     *
     * @param array<int, CartLine> $kept          position => line
     * @param array<int, string>   $lineDiscounts position => net, positive
     */
    private function partOnKept(ShippingShare $share, array $kept, BigDecimal $amount, BigDecimal $total, array $lineDiscounts, int $now): BigDecimal
    {
        if ([] === $lineDiscounts) {
            return $total->isZero()
                ? BigDecimal::of($share->gross)
                : BigDecimal::of($share->gross)->multipliedBy($amount)->dividedBy($total, 4, RoundingMode::Floor);
        }

        $gross = new GrossCalculator($this->taxes);
        $part = BigDecimal::zero();

        foreach ($kept as $position => $line) {
            $net = $lineDiscounts[$position] ?? '0';

            try {
                $part = $part->plus($gross->gross($net, $line->taxClassId, $line->taxCountry, $now));
            } catch (TaxRateNotFound) {
                $part = $part->plus($net);
            }
        }

        return $part->negated();
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

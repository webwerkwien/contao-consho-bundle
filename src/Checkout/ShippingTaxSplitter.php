<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Webwerkwien\ContaoConshoBundle\Cart\CartLine;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;

/**
 * Splits a shipping charge across the tax rates in the basket (C4).
 *
 * Shipping is an ancillary service to the goods, so it is taxed the way the
 * goods are: a basket holding books at 10 % and wine at 20 % gets TWO shipping
 * lines, each at its own rate, weighted by what the goods in it are worth.
 * That is also why tl_consho_order_item allows one shipping line per rate
 * (spec 5.2) rather than one line with a blended rate — a blended rate is not
 * a rate anyone may put on an invoice.
 *
 * `fixed` is the other mode: one line at the method's own tax class, for
 * operators who would rather answer for one simple rule than for the correct
 * one.
 *
 * ⚠️ The SHARES land on the currency's places; the AMOUNT does not.
 *
 * Both halves were learned the hard way. Splitting on four
 * places gave 2,4750 twice for a 4,95 charge, which the totals rounded to
 * 2,48 each — a bill of 4,96 for a charge quoted at 4,95. But rounding the
 * amount ITSELF broke the other end: a shipping price typed as 4,95 GROSS is
 * stored as 4,1250 net (PriceEntry, spec 5.1), and rounding that to 4,13 put
 * 4,96 in front of the customer again, from the other direction.
 *
 * So: the amount keeps its four places, the shares get the currency's, and
 * the remainder goes back onto the first share at four places. That is
 * exactly what CartPricer does with the goods — four-place line nets, rounded
 * only as they enter a sum (CartPricer::price(), the note on $net).
 *
 * ⚠️ The split is done on NET amounts and the remainder goes to the largest
 * share. Splitting gross would break the net anchor (C22); handing the
 * remainder to the last line would make the result depend on basket order,
 * so two identical baskets could produce different invoices.
 */
final class ShippingTaxSplitter
{
    /**
     * @param list<CartLine> $lines     the priced basket
     * @param int            $digits    the currency's decimal places
     * @param bool           $byCountry a reduction (H): one share per tax class AND
     *                                  country of the lines, each carrying that country — shipping
     *                                  and fees follow the delivery and stay per class
     *
     * @return list<ShippingShare> one share per tax class, biggest first; empty
     *                             for a zero charge or an empty basket
     */
    public function split(string $net, ShippingTaxMode $mode, int $methodTaxClassId, array $lines, int $digits = 4, bool $byCountry = false): array
    {
        $amount = BigDecimal::of($net);

        if ($amount->isZero()) {
            return [];
        }

        if (ShippingTaxMode::Fixed === $mode) {
            // One share, so it IS the amount: nothing to divide and nothing to
            // round. Rounding here is what turned 4,1250 into 4,13.
            return [new ShippingShare($methodTaxClassId, (string) $amount)];
        }

        $byClass = $this->goodsValuePerClass($lines, $byCountry);

        if ([] === $byClass) {
            /*
             * Nothing priced to follow. Falling back to the method's own class
             * is the only answer left, and it is the honest one: the operator
             * had to choose a class for it anyway (mandatory in both modes),
             * so this is not an invented rate.
             */
            return [new ShippingShare($methodTaxClassId, (string) $amount)];
        }

        $total = BigDecimal::zero();

        foreach ($byClass as $value) {
            $total = $total->plus($value);
        }

        if ($total->isZero()) {
            // Free goods still have a rate; with no value to weigh by, the
            // largest class by line count would be arbitrary. One line, the
            // method's class.
            return [new ShippingShare($methodTaxClassId, (string) $amount)];
        }

        /*
         * Biggest share first, and on a tie the lower tax class id. NOT
         * arsort(): PHP's sort is stable, so equal values would keep the order
         * the basket happened to have them in — and the remainder below goes
         * to whoever is first. Two identical baskets filled in a different
         * order would then produce invoices that differ by a hundredth of a
         * cent, which is the kind of difference nobody can explain later.
         */
        uksort($byClass, static function (int|string $a, int|string $b) use ($byClass): int {
            $compared = BigDecimal::of($byClass[$b])->compareTo(BigDecimal::of($byClass[$a]));

            return 0 !== $compared ? $compared : $a <=> $b;
        });

        $shares = [];
        $handedOut = BigDecimal::zero();

        foreach ($byClass as $key => $value) {
            $share = $amount
                ->multipliedBy(BigDecimal::of($value))
                ->dividedBy($total, $digits, RoundingMode::HalfUp)
            ;

            [$taxClassId, $country] = \is_int($key) ? [$key, ''] : [(int) explode('|', $key)[0], explode('|', $key)[1]];
            $shares[] = new ShippingShare($taxClassId, (string) $share, '0.0000', $country);
            $handedOut = $handedOut->plus($share);
        }

        /*
         * Splitting a charge into shares almost never adds up exactly. The
         * difference — at most one unit of the last place per
         * class — goes to the biggest share, first after the sort above
         * (uksort, not arsort — see the note there).
         * The shipping lines then total the amount the visitor was quoted, and
         * the invoice adds up.
         */
        $rest = $amount->minus($handedOut);

        if (!$rest->isZero()) {
            /*
             * FOUR places, not the currency's: the remainder has to land
             * exactly, or the shares no longer add up to the charge. The line
             * then carries four places like a product line does, and the
             * totals round it as they add it up.
             */
            $first = $shares[0];
            $shares[0] = new ShippingShare(
                $first->taxClassId,
                (string) BigDecimal::of($first->net)->plus($rest)->toScale(4, RoundingMode::HalfUp),
                '0.0000',
                $first->taxCountry,
            );
        }

        return $shares;
    }

    /**
     * @param list<CartLine> $lines
     *
     * @return array<int|string, string> tax class (or "class|country") => net value of the goods in it
     */
    private function goodsValuePerClass(array $lines, bool $byCountry = false): array
    {
        $byClass = [];

        foreach ($lines as $line) {
            // A voucher is no goods (WG8): neither shipping's tax nor a payment
            // discount follows it — the share would land on "nicht steuerbar".
            if ($line->kind->isVoucher()) {
                continue;
            }

            $key = $byCountry ? $line->taxClassId.'|'.strtoupper($line->taxCountry) : $line->taxClassId;
            $current = BigDecimal::of($byClass[$key] ?? '0');
            $byClass[$key] = (string) $current->plus($line->lineNet);
        }

        return $byClass;
    }
}

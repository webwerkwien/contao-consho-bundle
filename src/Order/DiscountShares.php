<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What each goods line of an order carries of the order's reductions — the
 * cart rule's `discount` and the payment method's `paymentDiscount` (block H,
 * H-5, R1).
 *
 * A withdrawn line credits what it COST, not its list amount: its gross less
 * its part of every reduction line in its tax class. ONE place for it, asked
 * by everything that credits a withdrawal — the correction lines and the
 * refund (WithdrawalProcessing), the balance before the refund
 * (WithdrawalCredit) and the value compensation of a service (ValueCompensation).
 * Until H-5 each credited the full `lineTotal`, and a partial withdrawal of an
 * order paid in advance refunded more than the line had cost.
 *
 * How a reduction line is spread over the goods of its class:
 *
 *  - `discount`: by the net each line was given at the purchase (column
 *    `discountNet`, frozen from the cart rule's own split) — a rule with
 *    product conditions reduced only the lines it named, and the order alone
 *    no longer knows which;
 *  - `paymentDiscount`, and a `discount` with nothing frozen: by the line's
 *    net, over all goods of the class — the split the checkout made
 *    (ShippingTaxSplitter). A later change of payment method replaces those
 *    lines (PaymentChange), so nothing about them can be frozen.
 *
 * Net and gross are each split by the same weights, the remainder on the last
 * line, so the parts add up to the reduction line exactly. The reduction is
 * taken out, never claimed back (R1): what is left of the order keeps its
 * reduction, minimum order value or not.
 */
final class DiscountShares
{
    /**
     * @param array<int, array<string, string>> $items every line of one order, id => row
     *
     * @return array<int, list<array{line: int, net: BigDecimal, gross: BigDecimal}>> goods item id => its
     *         parts of the reduction lines for its WHOLE quantity, negative like those lines
     */
    public static function of(array $items): array
    {
        $parts = [];

        foreach ($items as $lineId => $line) {
            $type = OrderLineType::stored($line['lineType'] ?? '');

            if (OrderLineType::Discount !== $type && OrderLineType::PaymentDiscount !== $type) {
                continue;
            }

            $class = (int) ($line['taxClass'] ?? 0);

            /*
             * Block H: since then a reduction has one line per class AND
             * country, and gives back only to the goods of its country. An
             * order from before has one line per class, at the delivery's
             * country, for all of the class — it still goes over all of them.
             */
            $countries = array_unique(array_map(
                static fn (array $row): string => strtoupper($row['taxCountry'] ?? ''),
                array_filter($items, static fn (array $row): bool => ($row['lineType'] ?? '') === ($line['lineType'] ?? '') && (int) ($row['taxClass'] ?? 0) === $class),
            ));
            $country = \count($countries) > 1 ? strtoupper($line['taxCountry'] ?? '') : null;

            $goods = array_filter($items, static fn (array $row): bool => OrderLineType::stored($row['lineType'] ?? '')->isGoods()
                && (int) ($row['taxClass'] ?? 0) === $class
                && (null === $country || strtoupper($row['taxCountry'] ?? '') === $country)
                && BigDecimal::of($row['quantity'] ?? '0')->isPositive());

            $frozen = OrderLineType::Discount === $type
                ? array_filter(array_map(static fn (array $row): BigDecimal => BigDecimal::of('' !== ($row['discountNet'] ?? '') ? $row['discountNet'] : '0'), $goods), static fn (BigDecimal $net): bool => !$net->isZero())
                : [];

            $weights = [] !== $frozen
                ? $frozen
                : array_map(static fn (array $row): BigDecimal => BigDecimal::of($row['lineTotal'])->minus($row['taxAmount']), $goods);

            $total = BigDecimal::zero();

            foreach ($weights as $weight) {
                $total = $total->plus($weight);
            }

            if ($total->isZero()) {
                continue;
            }

            $net = BigDecimal::of($line['lineTotal'])->minus($line['taxAmount']);
            $gross = BigDecimal::of($line['lineTotal']);
            $netLeft = $net;
            $grossLeft = $gross;
            $last = array_key_last($weights);

            foreach ($weights as $itemId => $weight) {
                $partNet = $itemId === $last ? $netLeft : $net->multipliedBy($weight)->dividedBy($total, 4, RoundingMode::HalfUp);
                $partGross = $itemId === $last ? $grossLeft : $gross->multipliedBy($weight)->dividedBy($total, 4, RoundingMode::HalfUp);
                $netLeft = $netLeft->minus($partNet);
                $grossLeft = $grossLeft->minus($partGross);

                $parts[$itemId][] = ['line' => (int) $lineId, 'net' => $partNet, 'gross' => $partGross];
            }
        }

        return $parts;
    }

    /**
     * What a goods line credits for the quantity withdrawn: its gross less its
     * parts of the reductions, both for that share of the line.
     *
     * @param array<string, string>                                    $item
     * @param list<array{line: int, net: BigDecimal, gross: BigDecimal}> $parts
     */
    public static function credit(array $item, array $parts, BigDecimal|string $accepted): BigDecimal
    {
        $quantity = BigDecimal::of($item['quantity']);

        if (!$quantity->isPositive()) {
            return BigDecimal::zero();
        }

        $gross = BigDecimal::of($item['lineTotal']);

        foreach ($parts as $part) {
            $gross = $gross->plus($part['gross']);
        }

        return $gross->multipliedBy($accepted)->dividedBy($quantity, 4, RoundingMode::HalfUp);
    }
}

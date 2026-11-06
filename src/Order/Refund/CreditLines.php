<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order\Refund;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Order\DiscountShares;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineType;
use Webwerkwien\ContaoConshoBundle\Order\ReductionShares;

/**
 * The lines an order gives back when part of it goes back: what each order
 * item credits, the lines of an invoice correction, their total, and the
 * delivery costs still refundable.
 *
 * Shared by everything that takes goods back by quantity — a withdrawal, a
 * complaint's rescission — so the credit of a line is worked out in one place.
 * The caller says which quantities of which order items go back.
 */
class CreditLines
{
    private readonly ReductionShares $reductions;

    public function __construct(
        private readonly Connection $connection,
        private readonly DocumentPayloadBuilder $payloads,
    ) {
        $this->reductions = new ReductionShares($connection);
    }

    /**
     * What a line is still worth to the customer, gross (RK-4, L3): its units
     * not gone back, after the order's reductions, less the price reductions
     * those units carry. The most a new price reduction may take.
     *
     * @param int $exceptComplaint a complaint being decided anew: neither its reduction nor its rescission counts
     */
    public function worth(int $orderId, int $itemId, int $exceptComplaint = 0): BigDecimal
    {
        $items = $this->items($orderId);
        $item = $items[$itemId] ?? null;

        if (null === $item || !OrderLineType::stored($item['lineType'])->isGoods()) {
            return BigDecimal::zero();
        }

        $left = BigDecimal::of($item['quantity'])->minus($this->reductions->returned($orderId, $itemId, $exceptComplaint));

        if (!$left->isPositive()) {
            return BigDecimal::zero();
        }

        $parts = DiscountShares::of($items);
        $worth = DiscountShares::credit($item, $parts[$itemId] ?? [], $left)
            ->minus($this->reductions->carried($orderId, $itemId, $exceptComplaint));

        return BigDecimal::max(BigDecimal::zero(), $worth);
    }

    /**
     * The lines of an invoice correction for price reductions (RK-4, L3): one
     * line per order item under its product name, quantity 1, at its rate and
     * country — the amount negative, net and tax worked out from the gross.
     *
     * @param array<int|string, mixed> $amounts order item id => gross reduction
     *
     * @return list<array<string, string>>
     */
    public function reductionLines(int $orderId, array $amounts): array
    {
        $items = $this->items($orderId);
        $lines = [];

        foreach ($amounts as $itemId => $amount) {
            $item = $items[(int) $itemId] ?? null;
            $gross = BigDecimal::of((string) $amount);

            if (null === $item || !$gross->isPositive()) {
                continue;
            }

            $net = self::net($gross, $item['taxRate'] ?? '');

            $lines[] = self::line($item, '1', [
                'unitPrice' => (string) $net,
                'taxAmount' => (string) $gross->minus($net),
                'lineTotal' => (string) $gross,
            ]);
        }

        return $lines;
    }

    /**
     * @return array<int, array<string, string>> order item id => row, every value a string
     */
    public function items(int $orderId): array
    {
        $items = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_consho_order_item WHERE pid = ? ORDER BY id', [$orderId]) as $row) {
            $items[(int) $row['id']] = array_map(static fn ($v): string => (string) $v, $row);
        }

        return $items;
    }

    /**
     * @return array<int, array<string, string>> the goods lines of the order
     */
    public function goods(int $orderId): array
    {
        return array_filter(
            $this->items($orderId),
            static fn (array $row): bool => OrderLineType::stored($row['lineType'])->isGoods(),
        );
    }

    /**
     * The delivery costs of the order not yet refunded, by a withdrawal or a
     * complaint.
     */
    public function shippingLeft(int $orderId): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($this->items($orderId) as $row) {
            if (OrderLineType::Shipping === OrderLineType::stored($row['lineType'])) {
                $total = $total->plus($row['lineTotal']);
            }
        }

        $refunded = (string) ($this->connection->fetchOne(
            "SELECT SUM(refundShipping) FROM tl_consho_withdrawal WHERE pid = ? AND refundedAt IS NOT NULL AND state = 'accepted'",
            [$orderId],
        ) ?: '0');

        // RK-4 (RK-E2): what a complaint's refund gave back of them.
        $byComplaints = (string) ($this->connection->fetchOne(
            'SELECT SUM(refundShipping) FROM tl_consho_complaint WHERE pid = ? AND refundedAt IS NOT NULL',
            [$orderId],
        ) ?: '0');

        return BigDecimal::max(BigDecimal::zero(), $total->minus($refunded)->minus($byComplaints));
    }

    /**
     * What each line going back credits, gross: its share of what the line
     * cost, its part of the order's reductions taken out (H-5, R1).
     *
     * With `$at`, the return's decision: the price reductions decided
     * before it that the units take along come off (RK-4, L3).
     *
     * @param array<int|string, mixed> $quantities order item id => quantity going back
     *
     * @return array<int, BigDecimal> order item id => credit
     */
    public function credits(int $orderId, array $quantities, ProductKind|null $kind = null, int|null $at = null): array
    {
        $items = $this->items($orderId);
        $parts = DiscountShares::of($items);
        $credits = [];

        foreach ($quantities as $itemId => $accepted) {
            $item = $items[(int) $itemId] ?? null;

            if (null === $item || !BigDecimal::of((string) $accepted)->isPositive() || (null !== $kind && $kind->value !== ($item['kind'] ?? ''))) {
                continue;
            }

            $credit = DiscountShares::credit($item, $parts[(int) $itemId] ?? [], (string) $accepted);

            if (null !== $at) {
                $credit = BigDecimal::max(BigDecimal::zero(), $credit->minus($this->reductions->taken($orderId, (int) $itemId, (string) $accepted, $at)));
            }

            $credits[(int) $itemId] = $credit;
        }

        return $credits;
    }

    /**
     * The lines of an invoice correction: the goods going back, and the
     * delivery costs spread over the shipping lines by their share — every
     * amount negative, every quantity positive (the cancellation's rule). The
     * value compensation (P79) lowers the credit of the service lines, in
     * their order; a service line kept back whole is left out.
     *
     * The reductions of the order go back with the goods (H-5, R1): each
     * reduction line gives back the parts of the goods going back, as one line
     * of its own after the goods — positive, under its own title, rate and
     * country, the cancellation's rule turned round. What a service line keeps
     * back is taken from what it cost after its reductions.
     *
     * With `$at`, the return's decision: the price reductions decided before
     * it that the units take along come off their goods line (RK-4, L3), the
     * way the value compensation does.
     *
     * @param array<int|string, mixed> $quantities order item id => quantity going back, in the order the lines take
     *
     * @return list<array<string, string>>
     */
    public function lines(int $orderId, array $quantities, BigDecimal $shipping, BigDecimal|null $kept = null, int|null $at = null): array
    {
        $items = $this->items($orderId);
        $parts = DiscountShares::of($items);
        $lines = [];
        $kept ??= BigDecimal::zero();
        /** @var array<int, array{net: BigDecimal, gross: BigDecimal}> $reductions */
        $reductions = [];

        foreach ($quantities as $itemId => $accepted) {
            $item = $items[(int) $itemId] ?? null;
            $share = BigDecimal::of((string) $accepted);

            if (null === $item || !$share->isPositive() || !BigDecimal::of($item['quantity'])->isPositive()) {
                continue;
            }

            $quantity = BigDecimal::of($item['quantity']);
            $gross = BigDecimal::of($item['lineTotal'])->multipliedBy($share)->dividedBy($quantity, 4, RoundingMode::HalfUp);
            $tax = BigDecimal::of($item['taxAmount'])->multipliedBy($share)->dividedBy($quantity, 4, RoundingMode::HalfUp);
            $unit = BigDecimal::of($item['unitPrice']);

            $credit = DiscountShares::credit($item, $parts[(int) $itemId] ?? [], $share);
            $off = null === $at ? BigDecimal::zero() : BigDecimal::min($credit, $this->reductions->taken($orderId, (int) $itemId, $share, $at));

            if ($kept->isPositive() && ProductKind::Service->value === ($item['kind'] ?? '') && $credit->minus($off)->isPositive()) {
                $compensation = BigDecimal::min($kept, $credit->minus($off));
                $kept = $kept->minus($compensation);
                $off = $off->plus($compensation);
            }

            if ($off->isPositive()) {
                if ($off->isGreaterThanOrEqualTo($credit)) {
                    continue;
                }

                $left = $gross->minus($off);

                // Net and tax from what is left, like the delivery share below.
                $net = self::net($left, $item['taxRate'] ?? '');
                $tax = $left->minus($net);
                $unit = $net->dividedBy($share, 4, RoundingMode::HalfUp);
                $gross = $left;
            }

            $lines[] = self::line($item, (string) $share, [
                'unitPrice' => (string) $unit,
                'taxAmount' => (string) $tax,
                'lineTotal' => (string) $gross,
            ]);

            foreach ($parts[(int) $itemId] ?? [] as $part) {
                $reduction = $reductions[$part['line']] ?? ['net' => BigDecimal::zero(), 'gross' => BigDecimal::zero()];
                $reductions[$part['line']] = [
                    'net' => $reduction['net']->plus($part['net']->multipliedBy($share)->dividedBy($quantity, 4, RoundingMode::HalfUp)),
                    'gross' => $reduction['gross']->plus($part['gross']->multipliedBy($share)->dividedBy($quantity, 4, RoundingMode::HalfUp)),
                ];
            }
        }

        foreach ($reductions as $lineId => $reduction) {
            if ($reduction['gross']->isZero() && $reduction['net']->isZero()) {
                continue;
            }

            // Negative here, so line() turns them positive.
            $lines[] = self::line($items[$lineId], '1', [
                'unitPrice' => (string) $reduction['net'],
                'taxAmount' => (string) $reduction['gross']->minus($reduction['net']),
                'lineTotal' => (string) $reduction['gross'],
            ]);
        }

        if ($shipping->isPositive()) {
            $shippingLines = array_values(array_filter($items, static fn (array $row): bool => OrderLineType::Shipping === OrderLineType::stored($row['lineType'])));
            $total = BigDecimal::zero();

            foreach ($shippingLines as $row) {
                $total = $total->plus($row['lineTotal']);
            }

            $left = $shipping;

            foreach ($shippingLines as $index => $row) {
                $gross = $index === \count($shippingLines) - 1 || !$total->isPositive()
                    ? $left
                    : $shipping->multipliedBy($row['lineTotal'])->dividedBy($total, 4, RoundingMode::HalfUp);
                $left = $left->minus($gross);

                $net = self::net($gross, $row['taxRate']);

                $lines[] = self::line($row, '1', [
                    'unitPrice' => (string) $net,
                    'taxAmount' => (string) $gross->minus($net),
                    'lineTotal' => (string) $gross,
                ]);
            }
        }

        return $lines;
    }

    /**
     * The grand total of these lines exactly as the document will state it:
     * the tax summary's gross, rounded to the currency per rate.
     *
     * @param list<array<string, string>> $lines
     */
    public function total(array $lines, string $currency): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($this->payloads->summariseTax($lines, $currency) as $entry) {
            $total = $total->plus($entry['gross']);
        }

        return $total;
    }

    private static function net(BigDecimal $gross, string $taxRate): BigDecimal
    {
        $rate = BigDecimal::of('' !== $taxRate ? $taxRate : '0');

        return $gross->dividedBy(BigDecimal::one()->plus($rate->dividedBy(100, 6, RoundingMode::HalfUp)), 4, RoundingMode::HalfUp);
    }

    /**
     * @param array<string, string> $item
     * @param array{unitPrice: string, taxAmount: string, lineTotal: string} $amounts positive for a credit, negative
     *                                                                       for a reduction given back
     *
     * @return array<string, string>
     */
    private static function line(array $item, string $quantity, array $amounts): array
    {
        return [
            'productName' => $item['productName'] ?? '',
            'sku' => $item['sku'] ?? '',
            'quantity' => $quantity,
            // Four places like every amount on an invoice (decimal(12,4)),
            // whatever scale the driver or the operator's input brought.
            'unitPrice' => (string) BigDecimal::of($amounts['unitPrice'])->negated()->toScale(4, RoundingMode::HalfUp),
            'taxRate' => $item['taxRate'] ?? '',
            'taxAmount' => (string) BigDecimal::of($amounts['taxAmount'])->negated()->toScale(4, RoundingMode::HalfUp),
            'lineTotal' => (string) BigDecimal::of($amounts['lineTotal'])->negated()->toScale(4, RoundingMode::HalfUp),
            'priceSource' => $item['priceSource'] ?? '',
            'lineType' => $item['lineType'] ?? '',
            // DL4: the correction sums per country and rate like the invoice
            // it corrects (DocumentPayloadBuilder::summariseTax()).
            'taxCountry' => $item['taxCountry'] ?? '',
            // Block R (R-2): a service stays out of the OSS threshold, on a
            // correction too.
            'kind' => $item['kind'] ?? '',
            // EZ-4: which pot a single-purpose voucher may take back from
            // (VoucherReturn); not part of the document.
            'taxClass' => (string) ($item['taxClass'] ?? ''),
        ];
    }
}

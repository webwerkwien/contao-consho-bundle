<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * How an order's three totals come out of its lines — in ONE place.
 *
 * OrderFactory sums them at the purchase, and since C-10 a change of payment
 * method sums them again after replacing the payment lines. Two copies of the
 * rule would be free to drift, and the one that drifted would put a total on
 * an invoice that the lines above it do not add up to.
 *
 * The rule itself is set out at OrderFactory::create(): each line's net and
 * gross are rounded to the currency's places as they ENTER the sum, grandTotal
 * is the sum of rounded grosses (the figure the buyer agreed to), and the tax
 * is the difference — not a sum of its own.
 */
final class OrderTotals
{
    private BigDecimal $net;

    private BigDecimal $gross;

    public function __construct(private readonly int $digits)
    {
        $this->net = BigDecimal::zero();
        $this->gross = BigDecimal::zero();
    }

    public function add(BigDecimal|string $lineNet, BigDecimal|string $lineGross): void
    {
        $this->net = $this->net->plus(BigDecimal::of($lineNet)->toScale($this->digits, RoundingMode::HalfUp));
        $this->gross = $this->gross->plus(BigDecimal::of($lineGross)->toScale($this->digits, RoundingMode::HalfUp));
    }

    public function net(): string
    {
        return (string) $this->net;
    }

    public function tax(): string
    {
        return (string) $this->gross->minus($this->net);
    }

    public function gross(): string
    {
        return (string) $this->gross;
    }

    public function grossDecimal(): BigDecimal
    {
        return $this->gross;
    }
}

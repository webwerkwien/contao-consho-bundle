<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;

/**
 * Payment state follows from the sum of the payment records.
 *
 * Refunds are rows with a negative amount, which is why instalments, part
 * payments and refunds all fall out of one calculation rather than needing
 * three concepts.
 *
 * The arithmetic runs on brick/math, and that is not fastidiousness: summed as
 * floats, three payments of 33.3333 against a total of 99.9999 come to
 * 99.99990000000001 and the order reads as overpaid. Nobody would find the
 * cause of that from the back end.
 */
final class PaymentStateCalculator
{
    /**
     * @param string       $grandTotal what is owed — the grand total, or the claim of OrderBalance (0 once cancelled)
     * @param list<string> $amounts    decimal strings; negative values are refunds
     */
    public function calculate(string $grandTotal, array $amounts): PaymentState
    {
        $total = BigDecimal::of($grandTotal);

        if ([] === $amounts) {
            return $total->isZero() ? PaymentState::NothingDue : PaymentState::Open;
        }
        $sum = BigDecimal::zero();
        $hasRefund = false;

        foreach ($amounts as $amount) {
            $value = BigDecimal::of($amount);

            if ($value->isNegative()) {
                $hasRefund = true;
            }

            $sum = $sum->plus($value);
        }

        /*
         * Refunded, not Open: the money came and went again, and an order that
         * reverted to "open" would look like one nobody has paid yet.
         */
        if ($hasRefund && $sum->isLessThanOrEqualTo(BigDecimal::zero())) {
            return PaymentState::Refunded;
        }

        if ($sum->isZero()) {
            return PaymentState::Open;
        }

        if ($sum->isGreaterThan($total)) {
            return PaymentState::Overpaid;
        }

        return $sum->isEqualTo($total) ? PaymentState::Paid : PaymentState::Partial;
    }
}

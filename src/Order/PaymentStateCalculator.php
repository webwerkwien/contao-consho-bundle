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
 * Chargebacks are negative too, but not refunds (spec 9, P2): the bank took
 * the money back, the shop gave nothing, and the customer owes it again. They
 * come separately, count towards the sum and never make an order "refunded".
 * A full chargeback leaves it Open, a partial one Partial.
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
     * @param list<string> $amounts     decimal strings; negative values are refunds
     * @param list<string> $chargedBack the chargeback rows and their reversals (ChargebackRow)
     */
    public function calculate(string $grandTotal, array $amounts, array $chargedBack = []): PaymentState
    {
        $total = BigDecimal::of($grandTotal);

        if ([] === $amounts && [] === $chargedBack) {
            return $total->isZero() ? PaymentState::NothingDue : PaymentState::Open;
        }
        $sum = BigDecimal::zero();
        $received = BigDecimal::zero();
        $hasRefund = false;

        foreach ($amounts as $amount) {
            $value = BigDecimal::of($amount);

            if ($value->isNegative()) {
                $hasRefund = true;
            } else {
                $received = $received->plus($value);
            }

            $sum = $sum->plus($value);
        }

        $chargebacks = BigDecimal::zero();

        foreach ($chargedBack as $amount) {
            $chargebacks = $chargebacks->plus($amount);
        }

        $sum = $sum->plus($chargebacks);

        /*
         * Refunded, not Open: the money came and went again, and an order that
         * reverted to "open" would look like one nobody has paid yet.
         * Not while a chargeback stands: then the customer, not the shop,
         * took the money back, and it is owed again.
         */
        if ($hasRefund && !$chargebacks->isNegative() && $sum->isLessThanOrEqualTo(BigDecimal::zero())) {
            return PaymentState::Refunded;
        }

        if ($sum->isZero()) {
            return PaymentState::Open;
        }

        if ($sum->isGreaterThan($total)) {
            return PaymentState::Overpaid;
        }

        // The full amount came, and the shop gave part of it back. Not while a
        // chargeback stands: then part of it is owed again (P2).
        if ($hasRefund && !$chargebacks->isNegative() && $sum->isLessThan($total) && $received->isGreaterThanOrEqualTo($total)) {
            return PaymentState::PartlyRefunded;
        }

        return $sum->isEqualTo($total) ? PaymentState::Paid : PaymentState::Partial;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order\Refund;

/**
 * What OrderRefund booked under the order lock. Amounts are strings in the
 * order's currency, rounded to it.
 */
final class RefundBooking
{
    /**
     * @param string     $amount     what the occasion credits: the grand total on a cancellation, else the
     *                               correction's total
     * @param string     $payout     what goes back to the customer — never money that never came in
     * @param string     $remaining  what is still to pay after this
     * @param string     $vouchers   the part of the payout that went back onto the order's vouchers
     * @param int        $document   the cancellation or correction, 0 without one
     * @param int|null   $invoice    the invoice issued with this refund, null if there was one already
     * @param int        $payment    the first payment row booked, 0 without one
     * @param list<int>  $payoutRows the rows the payment method pays out after the commit
     */
    public function __construct(
        public readonly string $amount,
        public readonly string $payout,
        public readonly string $remaining,
        public readonly string $vouchers,
        public readonly string $currency,
        public readonly int $document,
        public readonly int|null $invoice,
        public readonly int $payment,
        public readonly array $payoutRows,
    ) {
    }
}

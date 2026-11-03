<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Brick\Math\BigDecimal;

/**
 * The markers a chargeback leaves in tl_consho_payment (spec 9.2).
 *
 * A chargeback is a negative row with `method` = METHOD, tied to the payment
 * it took back by `refundOf` — so refundable() never offers the same money a
 * second time. A chargeback undone (N4) is a positive row with REVERSAL.
 * Both carry the provider's ID of the chargeback in `payoutReference`, which
 * is what keeps a resent notification from booking twice.
 *
 * Neither is a refund: the shop gave nothing back, the customer owes the
 * money again (P2).
 */
final class ChargebackRow
{
    public const METHOD = 'chargeback';
    public const REVERSAL = 'chargebackReversal';
    public const METHODS = [self::METHOD, self::REVERSAL];

    public static function is(string $method): bool
    {
        return \in_array($method, self::METHODS, true);
    }

    /**
     * Whether a chargeback stands, from the amounts of an order's rows of
     * both kinds: a chargeback's are negative, a reversal's positive.
     *
     * @param list<string> $amounts
     */
    public static function stands(array $amounts): bool
    {
        $sum = BigDecimal::zero();

        foreach ($amounts as $amount) {
            $sum = $sum->plus($amount);
        }

        return $sum->isNegative();
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order\Refund;

use Brick\Math\BigDecimal;

/**
 * What a refund is booked for: a withdrawal, or a complaint's rescission or
 * price reduction. The occasion has worked out its lines; OrderRefund books
 * the money and the document.
 */
final class RefundOccasion
{
    public const WITHDRAWAL = 'withdrawal';

    public const COMPLAINT = 'complaint';

    /**
     * @param array<string, mixed>        $order      the order row, read under its lock
     * @param string                      $reference  the occasion's number: the payment reference and the
     *                                                correction's reference
     * @param list<array<string, string>> $lines      the correction lines (CreditLines), negative
     * @param bool                        $everything everything goes back in this one step: the invoice is
     *                                                cancelled and the grand total refunded
     * @param BigDecimal                  $credited   what the balance already takes off the claim for this
     *                                                occasion before it is refunded — added back, so it is
     *                                                not subtracted twice
     * @param string                     $about      what the reference numbers — self::WITHDRAWAL or
     *                                                self::COMPLAINT; the correction names it so
     */
    public function __construct(
        public readonly array $order,
        public readonly string $reference,
        public readonly array $lines,
        public readonly bool $everything,
        public readonly BigDecimal $credited,
        public readonly string $about = self::WITHDRAWAL,
    ) {
    }
}

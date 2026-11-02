<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Refund;

/**
 * What a refund through the provider came to (block Zahlung Mollie, 4.1).
 */
final class RefundResult
{
    private function __construct(
        public readonly RefundOutcome $outcome,
        public readonly string $reference = '',
        public readonly string $reason = '',
    ) {
    }

    public static function done(string $reference): self
    {
        return new self(RefundOutcome::Done, trim($reference));
    }

    /**
     * The provider pays later. The reference is what refundState() is asked
     * about, so it cannot be empty — as for ChargeResult::pending().
     */
    public static function pending(string $reference): self
    {
        if ('' === trim($reference)) {
            throw new \InvalidArgumentException('A pending refund needs the provider reference to ask about.');
        }

        return new self(RefundOutcome::Pending, trim($reference));
    }

    /**
     * @param string $reason for the log, never shown to the customer
     */
    public static function failed(string $reason = ''): self
    {
        return new self(RefundOutcome::Failed, '', $reason);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a charge without the customer came to (Abo-4b, P92, P94).
 */
final class ChargeResult
{
    private function __construct(
        public readonly ChargeOutcome $outcome,
        public readonly string $amount = '',
        public readonly string $reference = '',
        public readonly string $reason = '',
        public readonly string $for = '',
        public readonly bool $mandateGone = false,
    ) {
    }

    /**
     * @param string $reference the payment's, as refunds need it
     * @param string $for       what the provider's later notifications name,
     *                          kept on the attempt; '': the reference
     */
    public static function paid(string $amount, string $reference, string $for = ''): self
    {
        $scaled = BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp);

        if (!$scaled->isPositive()) {
            throw new \InvalidArgumentException(\sprintf('A payment received is positive, got "%s".', $amount));
        }

        return new self(ChargeOutcome::Paid, (string) $scaled, $reference, '', trim($for));
    }

    /**
     * The provider answers later, through the attempt's notify URL — about
     * this reference, which the core keeps on the attempt (NotificationResult).
     * It has to name this one charge — an order number shared by several
     * attempts would let a notification about one settle another.
     */
    public static function pending(string $reference): self
    {
        if ('' === trim($reference)) {
            throw new \InvalidArgumentException('A pending charge needs the provider reference the answer will name.');
        }

        return new self(ChargeOutcome::Pending, '', trim($reference));
    }

    /**
     * @param string $reason      for the log, never shown to the customer
     * @param bool   $mandateGone the provider confirmed the mandate itself no
     *                            longer exists — the customer cancelled it
     *                            there. The core drops it, and later periods
     *                            go "on account" without a charge. Only for
     *                            that confirmation: a refusal that may pass
     *                            (no funds, a switch at the merchant) keeps it.
     */
    public static function failed(string $reason = '', bool $mandateGone = false): self
    {
        return new self(ChargeOutcome::Failed, '', '', $reason, '', $mandateGone);
    }
}

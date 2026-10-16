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
    ) {
    }

    public static function paid(string $amount, string $reference): self
    {
        $scaled = BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp);

        if (!$scaled->isPositive()) {
            throw new \InvalidArgumentException(\sprintf('A payment received is positive, got "%s".', $amount));
        }

        return new self(ChargeOutcome::Paid, (string) $scaled, $reference);
    }

    /**
     * The provider answers later, through the attempt's notify URL.
     */
    public static function pending(string $reference = ''): self
    {
        return new self(ChargeOutcome::Pending, '', $reference);
    }

    /**
     * @param string $reason for the log, never shown to the customer
     */
    public static function failed(string $reason = ''): self
    {
        return new self(ChargeOutcome::Failed, '', '', $reason);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a verified provider notification says (spec 6.2). The handler checks
 * authenticity; the core writes the payment row from `paid`, once (spec 7.4).
 *
 * `mandate` (Abo-4b, P93): what the provider kept for charging follow-up
 * orders, as the handler builds it. Stored on the order's subscriptions only
 * when the handler can charge (RecurringPaymentHandlerInterface); '' for none.
 */
final class NotificationResult
{
    private function __construct(
        public readonly NotificationOutcome $outcome,
        public readonly string $amount = '',
        public readonly string $reference = '',
        public readonly string $mandate = '',
    ) {
    }

    public static function paid(string $amount, string $reference, string $mandate = ''): self
    {
        $scaled = BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp);

        if (!$scaled->isPositive()) {
            throw new \InvalidArgumentException(\sprintf('A payment received is positive, got "%s".', $amount));
        }

        return new self(NotificationOutcome::Paid, (string) $scaled, $reference, trim($mandate));
    }

    public static function failed(): self
    {
        return new self(NotificationOutcome::Failed);
    }

    public static function ignored(): self
    {
        return new self(NotificationOutcome::Ignored);
    }
}

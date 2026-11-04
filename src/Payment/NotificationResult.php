<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a verified provider notification says (spec 6.2). The handler checks
 * authenticity; the core writes the payment row from `paid`, once (spec 7.4).
 *
 * `for` is the provider's reference of what the message is about — the one
 * start() or a pending charge handed over (PaymentStart, ChargeResult). The
 * core compares it, and the currency, with the attempt and refuses a message
 * about anything else: a signature proves who wrote the message, not which
 * attempt it was meant for, and a module that forgot to check would let a
 * genuine notice of one payment pay another.
 *
 * `reference` is the payment's own, booked with it (it may differ from `for`,
 * as a capture differs from its order at some providers).
 *
 * `events` (spec 9): what the provider reports about the payment after it
 * succeeded — chargebacks, refunds made at the provider. Optional, added with
 * withEvents(); the core books them even when the attempt is long settled.
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
        public readonly string $for = '',
        public readonly string $currency = '',
        /** @var list<ProviderEvent> */
        public readonly array $events = [],
        public readonly string $because = '',
    ) {
    }

    public function withEvents(ProviderEvent ...$events): self
    {
        return new self($this->outcome, $this->amount, $this->reference, $this->mandate, $this->for, $this->currency, array_values($events), $this->because);
    }

    public static function paid(string $amount, string $currency, string $for, string $reference, string $mandate = ''): self
    {
        $scaled = BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp);

        if (!$scaled->isPositive()) {
            throw new \InvalidArgumentException(\sprintf('A payment received is positive, got "%s".', $amount));
        }

        return new self(NotificationOutcome::Paid, (string) $scaled, $reference, trim($mandate), self::forOf($for), strtoupper(trim($currency)));
    }

    public static function failed(string $for): self
    {
        return new self(NotificationOutcome::Failed, for: self::forOf($for));
    }

    private static function forOf(string $for): string
    {
        if ('' === trim($for)) {
            throw new \InvalidArgumentException('A notification names the provider reference it is about.');
        }

        return trim($for);
    }

    /**
     * The payment is under way at the provider — a capture held for review,
     * a bank payment not cleared yet. Nothing is booked; asked by the cleanup
     * cron (PollingPaymentHandlerInterface), the attempt stays open and is
     * asked again on the next run.
     */
    public static function pending(string $for): self
    {
        return new self(NotificationOutcome::Pending, for: self::forOf($for));
    }

    /**
     * Nothing to act on. `because` goes to the log (info), so that a
     * notification the shop dropped can be told from one that never came.
     */
    public static function ignored(string $because = ''): self
    {
        return new self(NotificationOutcome::Ignored, because: trim($because));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Something the provider reports about a payment after it succeeded: money
 * the customer's bank took back, or a refund made outside Consho (spec 9.2).
 * A module hands these over with its notification result
 * (NotificationResult::withEvents()).
 *
 * `reference` is the provider's own ID of the event and the only key Consho
 * matches it by — never the amount (N3): two refunds of the same amount are
 * not the same refund. `amount` is always positive; the direction follows
 * from the kind.
 *
 * `reversedAt` (chargebacks): when the chargeback was undone and the money
 * came back (N4); 0 while it stands. `refundState` (refunds): the provider's
 * state as the module maps it — 'pending', 'processing', 'refunded' or
 * 'failed'. Consho records a foreign refund only once money is on its way.
 */
final class ProviderEvent
{
    public const REFUND_STATES = ['pending', 'processing', 'refunded', 'failed'];

    private function __construct(
        public readonly ProviderEventKind $kind,
        public readonly string $reference,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $reason,
        public readonly int $occurredAt,
        public readonly int $reversedAt = 0,
        public readonly string $refundState = '',
    ) {
    }

    /**
     * @param string $reason what the bank gave as the reason (SEPA return
     *                       codes come with one, card disputes mostly not)
     */
    public static function chargeback(string $reference, string $amount, string $currency, string $reason, int $occurredAt, int $reversedAt = 0): self
    {
        return new self(
            ProviderEventKind::Chargeback,
            self::referenceOf($reference),
            self::amountOf($amount),
            self::currencyOf($currency),
            mb_substr(trim($reason), 0, 255),
            max(0, $occurredAt),
            max(0, $reversedAt),
        );
    }

    public static function refund(string $reference, string $amount, string $currency, string $state, int $occurredAt): self
    {
        if (!\in_array($state, self::REFUND_STATES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown refund state "%s".', $state));
        }

        return new self(
            ProviderEventKind::Refund,
            self::referenceOf($reference),
            self::amountOf($amount),
            self::currencyOf($currency),
            '',
            max(0, $occurredAt),
            0,
            $state,
        );
    }

    private static function referenceOf(string $reference): string
    {
        if ('' === trim($reference)) {
            throw new \InvalidArgumentException('A provider event names its provider reference.');
        }

        return mb_substr(trim($reference), 0, 255);
    }

    private static function amountOf(string $amount): string
    {
        $scaled = BigDecimal::of(trim($amount))->abs()->toScale(4, RoundingMode::HalfUp);

        if ($scaled->isZero()) {
            throw new \InvalidArgumentException('A provider event moves money.');
        }

        return (string) $scaled;
    }

    private static function currencyOf(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException(\sprintf('A provider event names its currency, got "%s".', $currency));
        }

        return $currency;
    }
}

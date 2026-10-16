<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

/**
 * What a customer may use, from one order line (F-4; F1, F7).
 *
 * ONE entitlement per line with its quantity, not one per piece: an agency
 * buying for twenty installations gets one entitlement, one invoice and twenty
 * keys or tokens (F7). Whatever exists per piece belongs to the handler.
 *
 * `validUntil` null is permanent; otherwise the core only stores the date —
 * whether its end stops updates or use is the product's business (F1).
 */
final class Entitlement
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly int $orderItemId,
        public readonly int $memberId,
        public readonly int $productId,
        public readonly string $quantity,
        public readonly string $deliveryType,
        public readonly int $validFrom,
        public readonly int|null $validUntil,
        public readonly bool $revoked = false,
        // Abo-3: the line's subscription, 0 for a one-time purchase.
        public readonly int $subscription = 0,
        // Abo-5 (P33): resting since, while an invoice of the subscription is
        // overdue beyond grace; null when not.
        public readonly int|null $suspendedAt = null,
    ) {
    }

    /**
     * Whether it may be used at this moment: not revoked by the operator, not
     * resting for an unpaid invoice, and not past its end.
     */
    public function isActive(int $now): bool
    {
        return !$this->revoked && null === $this->suspendedAt && (null === $this->validUntil || $now <= $this->validUntil);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['pid'],
            (int) $row['orderItem'],
            (int) $row['member'],
            (int) $row['product'],
            (string) $row['quantity'],
            (string) $row['deliveryType'],
            (int) $row['validFrom'],
            null === $row['validUntil'] ? null : (int) $row['validUntil'],
            (bool) $row['revoked'],
            (int) ($row['subscription'] ?? 0),
            null === ($row['suspendedAt'] ?? null) ? null : (int) $row['suspendedAt'],
        );
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * The system flags of an order status (spec A 5.4, D 5.2).
 *
 * The operator names a status; the system reads only these.
 */
final class StatusFlags
{
    public function __construct(
        public readonly int $id,
        public readonly int $shop,
        public readonly string $title,
        public readonly bool $isPaid = false,
        public readonly bool $isCancelled = false,
        public readonly bool $isShipped = false,
        public readonly bool $isCompleted = false,
        public readonly bool $locksChanges = false,
    ) {
    }

    /**
     * @param array<string, mixed> $row a row of tl_consho_order_status
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) ($row['pid'] ?? 0),
            (string) ($row['title'] ?? ''),
            (bool) ($row['isPaid'] ?? false),
            (bool) ($row['isCancelled'] ?? false),
            (bool) ($row['isShipped'] ?? false),
            (bool) ($row['isCompleted'] ?? false),
            (bool) ($row['locksChanges'] ?? false),
        );
    }

    /**
     * Whether an order in this status must no longer be changed by the
     * system — no payment method change, no automatic status change.
     */
    public function holds(): bool
    {
        return $this->locksChanges || $this->isCancelled || $this->isCompleted;
    }
}

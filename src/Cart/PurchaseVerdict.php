<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

/**
 * The answer of PurchaseCheck: may this article go into this shop's basket,
 * in this quantity?
 *
 * The reasons map one to one onto CartNotice, so the route can hand the
 * visitor the right sentence without a second table of cases.
 *
 * When $allowed is false, $quantity and $mainProductId are meaningless and
 * therefore null — not a placeholder like '0.0000' or 0 that a caller could
 * mistake for a real, if oddly small, cart line. Reading either without
 * checking $allowed first is a PHPStan error, not a silent trap.
 */
final class PurchaseVerdict
{
    public const UNAVAILABLE = 'unavailable';
    public const NEEDS_CHOICE = 'needs_choice';
    public const INVALID_QUANTITY = 'invalid_quantity';
    // The chosen plan is not offered to this visitor (Abo-2, P11).
    public const NOT_OFFERED = 'not_offered';

    private function __construct(
        public readonly bool $allowed,
        public readonly string|null $reason,
        public readonly string|null $quantity,
        public readonly int|null $mainProductId,
    ) {
    }

    public static function allow(string $quantity, int $mainProductId): self
    {
        return new self(true, null, $quantity, $mainProductId);
    }

    public static function refuse(string $reason): self
    {
        return new self(false, $reason, null, null);
    }

    public function notice(): CartNotice
    {
        return match ($this->reason) {
            self::NEEDS_CHOICE => CartNotice::NeedsChoice,
            self::INVALID_QUANTITY => CartNotice::InvalidQuantity,
            self::NOT_OFFERED => CartNotice::NotOffered,
            default => CartNotice::Unavailable,
        };
    }
}

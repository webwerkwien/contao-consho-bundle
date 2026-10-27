<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

/**
 * One voucher of a basket and what it pays (block Wertgutscheine, 6.5).
 *
 * `applied` is what it takes off this order, at the currency's places; the
 * rest of the balance stays on the voucher (`after`). Not usable: nothing
 * applied, and the status says why.
 */
final class AppliedVoucher
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly VoucherStatus $status,
        public readonly string $balance,
        public readonly string $applied,
        public readonly string $after,
        // EZ-1: pays only for articles of its tax class.
        public readonly bool $singlePurpose = false,
    ) {
    }

    public function masked(): string
    {
        return VoucherCode::masked($this->code);
    }
}

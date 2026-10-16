<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax\Oss;

/**
 * One line of a supply, or of its correction, with the dates the OSS needs
 * (block R, R7, R14). Amounts are net and tax at four places, negative for a
 * correction.
 */
final class OssEntry
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly int $shopId,
        /** The invoice of a supply, the credit note of a correction — '' where none was issued. */
        public readonly string $document,
        public readonly bool $correction,
        /** When this entry happened: the supply (R14), a cancellation, a credit note. */
        public readonly int $at,
        /** When the supply it belongs to happened (R14) — equal to $at for a supply. */
        public readonly int $supplyAt,
        public readonly int|null $paidAt,
        public readonly int|null $deliveredAt,
        public readonly string $destinationCountry,
        public readonly string $taxCountry,
        public readonly string $taxRate,
        public readonly string $net,
        public readonly string $tax,
        public readonly string $currency,
        public readonly string $description,
        /** Counts towards the 10,000 euro threshold (O2). */
        public readonly bool $threshold,
        /** Taxed in another member state through the OSS (taxCountry = destination). */
        public readonly bool $oss,
    ) {
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * One article row as the detail page reads it — main product or variant, not
 * yet combined.
 *
 * Deliberately without the safety texts: they follow a different language rule
 * (no fallback, docs/mehrsprachigkeit.md, 4) and are read by
 * ProductSafetyResolver. A field that is here gets read with coalesce() one day.
 *
 * Decimal columns stay the strings the database returns; nothing here is a
 * float.
 */
final class ProductDetailRow
{
    /**
     * @param list<string> $images UUIDs in the order maintained on the article
     */
    public function __construct(
        public readonly int $id,
        public readonly int $pid,
        public readonly string $title,
        public readonly string $teaser,
        // For the page head only (ProductHead) — nothing in the page body shows it.
        public readonly string $metaDescription,
        public readonly string $description,
        public readonly string $sku,
        public readonly string $gtin,
        public readonly string $mpn,
        public readonly array $images,
        public readonly string $unit,
        public readonly string $purchaseUnit,
        public readonly string $referenceUnit,
        public readonly string $minPurchase,
        public readonly string $purchaseSteps,
        public readonly int $minimumAge,
        public readonly int $manufacturer,
        public readonly int $productType,
        public readonly string $productTypeTitle,
        public readonly string $detailPartial,
    ) {
    }
}

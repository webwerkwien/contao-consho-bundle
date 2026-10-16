<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * What the product detail page shows about the article, with the inheritance
 * from main product to variant already applied (ProductDetailAssembler).
 *
 * Every field is filled — no nulls to distinguish in a template. The same rule
 * as ProductTile and ProductSafetyInfo.
 */
final class ProductDetail
{
    /**
     * @param list<string>       $images     UUIDs in the order maintained on the article
     * @param list<PropertyView> $properties
     */
    public function __construct(
        public readonly int $productId,
        /**
         * The article that would be bought: the chosen variant, or the product
         * itself when no variant is chosen.
         */
        public readonly int $articleId,
        public readonly string $title,
        public readonly string $teaser,
        public readonly string $description,
        public readonly string $sku,
        public readonly string $gtin,
        public readonly string $mpn,
        public readonly array $images,
        public readonly int $manufacturerId,
        public readonly string $productTypeTitle,
        public readonly string $detailPartial,
        public readonly int $minimumAge,
        public readonly string $unit,
        public readonly string $purchaseUnit,
        public readonly string $referenceUnit,
        public readonly string $minPurchase,
        public readonly string $purchaseSteps,
        public readonly array $properties,
    ) {
    }
}

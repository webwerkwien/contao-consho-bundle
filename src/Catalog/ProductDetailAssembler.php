<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * What a variant inherits from its main product on the detail page — in one
 * place, as rules in PHP rather than joins in SQL.
 *
 * The same split as ProductSafetyRepositoryInterface/ProductSafetyResolver: the
 * repository reads rows, this class decides. Expressed in SQL the rules would
 * sit where the next person changes them by accident.
 *
 *   | field                            | variant chosen                        |
 *   |----------------------------------|---------------------------------------|
 *   | title, teaser, description       | always the main product's             |
 *   | product type, partial, properties| always the main product's             |
 *   | images, manufacturer             | own, otherwise the main product's     |
 *   | unit, purchaseUnit, referenceUnit| own SET if the unit is set, otherwise |
 *   |                                  | the main product's set                |
 *   | sku, gtin, mpn                   | own — NEVER inherited                 |
 *   | minPurchase, purchaseSteps       | own (the columns default to 1)        |
 *   | minimumAge                       | the higher of both                    |
 */
final class ProductDetailAssembler
{
    /**
     * @param list<PropertyView> $properties the main product's
     */
    public static function assemble(ProductDetailRow $main, ?ProductDetailRow $variant, array $properties): ProductDetail
    {
        if (null !== $variant && $variant->pid !== $main->id) {
            // Texts of one article and the GTIN of another would look perfectly
            // plausible on the page. Refuse rather than mix.
            throw new \InvalidArgumentException(\sprintf('Article %d is not a variant of product %d.', $variant->id, $main->id));
        }

        $article = $variant ?? $main;

        // The unit fields describe one content — 250 ml and 750 ml. Taken as a
        // set, never field by field, or a base price would mix two articles.
        $units = '' !== $article->unit ? $article : $main;

        return new ProductDetail(
            productId: $main->id,
            articleId: $article->id,
            // The page is the main product's; a variant shows itself through
            // its axis labels. Its title is a back end label.
            title: $main->title,
            teaser: $main->teaser,
            description: $main->description,
            // Per ARTICLE, never inherited (A-12, tl_consho_product.php): an
            // inherited GTIN is a false statement, not a convenience.
            sku: $article->sku,
            gtin: $article->gtin,
            mpn: $article->mpn,
            images: [] !== $article->images ? $article->images : $main->images,
            manufacturerId: $article->manufacturer ?: $main->manufacturer,
            productTypeTitle: $main->productTypeTitle,
            detailPartial: $main->detailPartial,
            // The column defaults to 0, so "not set" and "suitable for all ages"
            // cannot be told apart. A main product from 18 does not become
            // harmless because a variant says 0.
            minimumAge: max($main->minimumAge, $article->minimumAge),
            unit: $units->unit,
            purchaseUnit: $units->purchaseUnit,
            referenceUnit: $units->referenceUnit,
            minPurchase: $article->minPurchase,
            purchaseSteps: $article->purchaseSteps,
            properties: $properties,
        );
    }
}

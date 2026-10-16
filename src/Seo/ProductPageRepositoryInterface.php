<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

interface ProductPageRepositoryInterface
{
    /**
     * The product detail page below the given root page, or null if there is none.
     */
    public function findForRootPage(int $rootPageId): ?int;

    /**
     * @return array<string, string> alias, sku, manufacturer, type — every value a string, never null
     *
     * alias, manufacturer and type come back translated into $language, with
     * a fallback to the record's own value when a translation is missing; sku
     * is language-neutral and never translated. $language === $fallback (or
     * $language === '') asks for the fallback language, unchanged from before
     * M-3.
     */
    public function urlValues(int $productId, string $language = '', string $fallback = ''): array;

    /**
     * The root page a page belongs to, or null if the tree does not lead to one.
     */
    public function rootPageOf(int $pageId): ?int;

    /**
     * Every main product a shop carries, with the values its address is built from.
     *
     * Deliberately not filtered by visibility: publication changes over time,
     * the shape of the address did not. Recording an address for a product that
     * is currently switched off costs a row; missing one costs a dead link once
     * it is switched on again.
     *
     * Same translation rule as urlValues(): alias, manufacturer and type fall
     * back to the record's own value; sku never translates.
     *
     * @return array<int, array<string, string>> product id => url values
     */
    public function urlValuesForShop(int $shopId, string $language = '', string $fallback = ''): array;

    /**
     * urlValuesForShop() narrowed to one product.
     *
     * For an alias change of a single product (RedirectRecorder): renaming one
     * article must not read a catalogue of 100,000. Same rules as the shop-wide
     * list — main products only, only when assigned to the shop, not filtered
     * by visibility — so a product answers here exactly when it would appear
     * there.
     *
     * @return array<int, array<string, string>> product id => url values; empty for a variant or a product outside the shop
     */
    public function urlValuesForProductInShop(int $productId, int $shopId, string $language = '', string $fallback = ''): array;

    /**
     * urlValuesForShop() narrowed to the main products of one manufacturer.
     *
     * For a changed manufacturer alias (RedirectRecorder): it moves the address
     * of every product of that manufacturer, and of no other. Same rules as the
     * shop-wide list. A variant's own manufacturer plays no part — a variant
     * has no address.
     *
     * @return array<int, array<string, string>> product id => url values
     */
    public function urlValuesForManufacturerInShop(int $manufacturerId, int $shopId, string $language = '', string $fallback = ''): array;

    /**
     * urlValuesForShop() narrowed to the main products of one product type.
     * Same reasoning as urlValuesForManufacturerInShop().
     *
     * @return array<int, array<string, string>> product id => url values
     */
    public function urlValuesForProductTypeInShop(int $productTypeId, int $shopId, string $language = '', string $fallback = ''): array;
}

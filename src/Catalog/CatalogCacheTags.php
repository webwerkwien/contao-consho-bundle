<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\CoreBundle\Cache\CacheTagManager;
use Doctrine\DBAL\Connection;

/**
 * The cache tags of Consho's catalogue pages (plan B-7b).
 *
 * Right after the page cache was switched on: a product title changed in the back end, the sitemap came back
 * fresh, the listing and the product page kept the old title. No Consho page
 * tagged its response.
 *
 * ── Coarse for listings, per product for the product page ─────────────────
 *
 * | LISTINGS                  | listing, filter    | any product, price or landing page |
 * | CATALOG                   | + product page     | manufacturer, type, property, shop |
 * | contao.db.tl_consho_product.<main> | product page | the product, a variant, a price |
 *
 * A listing is invalidated by EVERY catalogue change (E1). Only that
 * brings in a product just published: a page cannot carry the tag of a product
 * it does not show yet.
 *
 * The product page uses the core's own record tag of its MAIN product. Contao
 * adds it when the main product is saved; for a variant (no ptable, list mode
 * 1) and for a price of a variant (ptable → the variant) it does not, which is
 * what forProduct() and forPrice() add.
 */
class CatalogCacheTags
{
    public const LISTINGS = 'consho.listings';

    public const CATALOG = 'consho.catalog';

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheTagManager|null $tagManager = null,
    ) {
    }

    public static function product(int $mainProductId): string
    {
        return 'contao.db.tl_consho_product.'.$mainProductId;
    }

    /**
     * What a change to this product or variant invalidates.
     *
     * @return list<string>
     */
    public function forProduct(int $productId): array
    {
        $main = $this->mainOf($productId);

        return null === $main ? [self::LISTINGS] : [self::LISTINGS, self::product($main)];
    }

    /**
     * What a change to this price row invalidates.
     *
     * @return list<string>
     */
    public function forPrice(int $priceId): array
    {
        $articleId = $this->connection->fetchOne('SELECT pid FROM tl_consho_price WHERE id = ?', [$priceId]);

        return false === $articleId ? [self::LISTINGS] : $this->forProduct((int) $articleId);
    }

    /**
     * The main product of a product or variant row, read from a row already in
     * hand — the recycle bin passes the restored row itself.
     *
     * @param array<string, mixed> $row
     */
    public static function mainOfRow(array $row): int
    {
        return (int) ($row['pid'] ?? 0) ?: (int) ($row['id'] ?? 0);
    }

    /**
     * @param list<string> $tags
     */
    public function invalidate(array $tags): void
    {
        if ([] !== $tags) {
            $this->tagManager?->invalidateTags($tags);
        }
    }

    /**
     * @param list<string> $tags
     * @param list<string> $more
     *
     * @return list<string>
     */
    public static function merge(array $tags, array $more): array
    {
        return array_values(array_unique([...$tags, ...$more]));
    }

    private function mainOf(int $productId): int|null
    {
        if ($productId < 1) {
            return null;
        }

        $pid = $this->connection->fetchOne('SELECT pid FROM tl_consho_product WHERE id = ?', [$productId]);

        if (false === $pid) {
            return null;
        }

        return (int) $pid ?: $productId;
    }
}

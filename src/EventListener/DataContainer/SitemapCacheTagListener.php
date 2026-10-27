<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Webwerkwien\ContaoConshoBundle\Seo\SitemapCacheTags;

/**
 * Keeps Contao's sitemap current when a back end change moves a Consho address
 * into or out of it (plan B-6, decision 3).
 *
 * Contao caches /sitemap.xml for a MONTH in the shared cache. It invalidates it
 * per start point for its own records (tl_page, news, events — see
 * SitemapCacheTags), but knows nothing of products. A product published,
 * switched off or given its first price would otherwise stay wrong in the
 * sitemap for up to thirty days.
 *
 * The same mechanism as tl_page: DC_Table::invalidateCacheTags() runs on save,
 * delete, copy, undo and version restore, and hands the tags through this
 * callback. Translations and the shop assignment are saved through the
 * product's own form, so the product covers them. Changes that bypass the back
 * end invalidate themselves (ListPriceBoundaryCron, consho:price:rebuild).
 *
 * Several methods rather than one __invoke: a shop narrows the start points to
 * its own, everything else concerns every start point with a shop — and the
 * recycle bin needs callbacks of its own (onUndo()).
 */
class SitemapCacheTagListener
{
    public function __construct(private readonly SitemapCacheTags $sitemaps)
    {
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.oninvalidate_cache_tags')]
    #[AsCallback(table: 'tl_consho_price', target: 'config.oninvalidate_cache_tags')]
    #[AsCallback(table: 'tl_consho_facet_page', target: 'config.oninvalidate_cache_tags')]
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'config.oninvalidate_cache_tags')]
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.oninvalidate_cache_tags')]
    public function __invoke(DataContainer $dc, array $tags): array
    {
        return self::merge($tags, $this->sitemaps->forShops());
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_shop', target: 'config.oninvalidate_cache_tags')]
    public function onShop(DataContainer $dc, array $tags): array
    {
        return self::merge($tags, $this->sitemaps->forShops((int) $dc->id));
    }

    /**
     * 🔴 Restoring from the recycle bin collects no tags for the restored
     * record: DC_Table::undo() calls invalidateCacheTags() on tl_undo itself,
     * so the oninvalidate_cache_tags callbacks above never run (Contao 5.7,
     * DC_Table.php). A price restored from
     * the recycle bin left the cached sitemap without its product.
     *
     * onundo runs once per restored row instead — the sitemaps are invalidated
     * from there, directly.
     *
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_price', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_facet_page', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'config.onundo')]
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.onundo')]
    public function onUndo(string $table, array $row, DataContainer $dc): void
    {
        $this->sitemaps->invalidateShopSitemaps();
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_shop', target: 'config.onundo')]
    public function onShopUndo(string $table, array $row, DataContainer $dc): void
    {
        $shopId = (int) ($row['id'] ?? 0);

        // Without an id, forShops(0) would name the start points WITHOUT a shop.
        if ($shopId > 0) {
            $this->sitemaps->invalidateShopSitemaps($shopId);
        }
    }

    /**
     * @param list<string> $tags
     * @param list<string> $sitemapTags
     *
     * @return list<string>
     */
    private static function merge(array $tags, array $sitemapTags): array
    {
        return array_values(array_unique([...$tags, ...$sitemapTags]));
    }
}

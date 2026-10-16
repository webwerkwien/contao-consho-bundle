<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Contao\CoreBundle\Cache\CacheTagManager;
use Doctrine\DBAL\Connection;

/**
 * The cache tags of the sitemaps a Consho change can move (plan B-6, decision 3).
 *
 * Contao tags /sitemap.xml with contao.sitemap AND contao.sitemap.<root>
 * (SitemapController) and invalidates per start point: tl_page, tl_news,
 * tl_news_archive, tl_calendar and tl_calendar_events each add
 * contao.sitemap.<root of the page concerned>. Only the Automator drops all of
 * them, and only when asked without a root.
 *
 * Consho follows that: the start points WITH a shop, never the whole tag. On an
 * installation carrying several sites, a price change must not rebuild the
 * sitemaps of sites that have nothing to do with the shop.
 *
 * Why not the exact roots of one product: a deleted product's shop assignment
 * may already be gone when the tags are collected, and a product rarely
 * belongs to fewer than all shops. Every start point with a shop is always
 * right and costs one indexed query.
 */
class SitemapCacheTags
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CacheTagManager|null $tagManager = null,
    ) {
    }

    /**
     * @param int|null $shopId only the start points of this shop; null for every start point with a shop
     *
     * @return list<string>
     */
    public function forShops(int|null $shopId = null): array
    {
        $rootIds = null === $shopId
            ? $this->connection->fetchFirstColumn("SELECT id FROM tl_page WHERE type = 'root' AND consho_shop > 0 ORDER BY id")
            : $this->connection->fetchFirstColumn("SELECT id FROM tl_page WHERE type = 'root' AND consho_shop = ? ORDER BY id", [$shopId]);

        return array_map(static fn (mixed $id): string => 'contao.sitemap.'.(int) $id, $rootIds);
    }

    /**
     * For changes where no DCA callback collects tags: those that bypass the
     * back end, and restoring from the recycle bin — DC_Table::undo() calls
     * invalidateCacheTags() on tl_undo itself, never on the restored table.
     *
     * @param int|null $shopId only the start points of this shop; null for every start point with a shop
     */
    public function invalidateShopSitemaps(int|null $shopId = null): void
    {
        $tags = $this->forShops($shopId);

        if ([] !== $tags) {
            $this->tagManager?->invalidateTags($tags);
        }
    }
}

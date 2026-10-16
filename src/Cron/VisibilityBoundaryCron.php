<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Seo\SitemapCacheTags;

/**
 * Applies the start and stop of products and variants to the visibility
 * projection (B-10). Nobody saves anything at that moment, so no back end
 * trigger fires.
 *
 * ── Why a marker of its own ──────────────────────────────────────────────
 *
 * ListPriceBoundaryCron compares a boundary with the tstamp of the projected
 * row. That does not work here: a product that starts but stays invisible for
 * another reason — no shop, no price — never gets a row, and would be due
 * every minute forever. So the moment up to which every boundary has been
 * applied is kept in tl_consho_projection_state, and each run takes the
 * boundaries in (marker, now]. A cron that did not run for a week catches up
 * on the week; a run that fails leaves the marker where it was and the next
 * one tries again.
 *
 * No marker at all means nobody ever built the projection: then everything is
 * built, which sets the marker. See docs/block-b-katalog-frontend.md, 5.7.
 */
#[AsCronJob('minutely')]
class VisibilityBoundaryCron
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly VisibilityProjector $projector,
        private readonly ProjectionState $state,
        private readonly SitemapCacheTags|null $sitemaps = null,
        private readonly CatalogCacheTags|null $catalog = null,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function __invoke(): void
    {
        $now = null !== $this->clock ? ($this->clock)() : time();
        $last = $this->state->get(ProjectionState::VISIBILITY_BOUNDARIES);

        if (null === $last) {
            $this->projector->rebuild($now);
            $this->invalidate();

            return;
        }

        if ($last >= $now) {
            return;
        }

        $productIds = $this->productIdsDue($last, $now);

        if ([] !== $productIds && $this->projector->refresh($productIds, $now)) {
            $this->invalidate();
        }

        // Only after the refresh: a failure above leaves the boundaries due.
        $this->state->set(ProjectionState::VISIBILITY_BOUNDARIES, $now);
    }

    /**
     * Main products with a start or stop — of their own or of a variant — in
     * (last, now].
     *
     * start/stop are varchar(10) holding a Unix timestamp; compared as strings
     * on both engines, as VisibilityCriteria::published() explains. Published
     * or not does not matter: a boundary of an unpublished product changes
     * nothing, and refreshing it costs next to nothing.
     *
     * @return list<int>
     */
    public function productIdsDue(int $last, int $now): array
    {
        $ids = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT CASE WHEN pid = 0 THEN id ELSE pid END
             FROM tl_consho_product
             WHERE (start <> '' AND start > :last AND start <= :now)
                OR (stop <> '' AND stop > :last AND stop <= :now)
             ORDER BY 1",
            ['last' => (string) $last, 'now' => (string) $now],
        );

        return array_map(intval(...), $ids);
    }

    private function invalidate(): void
    {
        // A product that starts or stops enters or leaves the sitemap, which
        // Contao caches for a month, and every listing (B-7b).
        $this->sitemaps?->invalidateShopSitemaps();
        $this->catalog?->invalidate([CatalogCacheTags::LISTINGS]);
    }
}

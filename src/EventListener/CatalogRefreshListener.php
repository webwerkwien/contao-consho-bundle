<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Seo\SitemapCacheTags;
use Webwerkwien\ContaoConshoBundle\Stock\SoldOutMarks;

/**
 * Recalculates what the request's triggers reported: list prices, the markets
 * of the shops (C25), then visibility, then the comparison of shops and
 * languages (B-10).
 *
 * 🔴 The order is fixed. The visibility projection takes "priced" and the
 * "from" price from the list price projection; calculated first, it would
 * store the prices from before the change.
 *
 * On the RESPONSE, not on kernel.terminate: the back end redirects after
 * saving, and a failure there should reach the editor rather than vanish after
 * the response has been sent. Every write of the request has happened by then
 * — DC_Table deletes, copies and restores before it redirects. On
 * console.terminate for the same reason on the command line.
 *
 * The DCA already invalidated the cache tags of the saved record — during the
 * request, before this runs. A listing requested in between could have been
 * cached with the old projection, so a change here invalidates the listings
 * and sitemaps once more.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
#[AsEventListener(event: ConsoleEvents::TERMINATE)]
class CatalogRefreshListener
{
    public function __construct(
        private readonly CatalogRefreshQueue $queue,
        private readonly ListPriceProjector $prices,
        private readonly VisibilityProjector $visibility,
        private readonly CatalogCacheTags|null $catalog = null,
        private readonly SitemapCacheTags|null $sitemaps = null,
        /*
         * Block Verfügbarkeit, V1: a saved product may change what is sold
         * out — stock kept or not, "sell without stock", codes pasted into
         * its pool, a new variant. Optional for the tests.
         */
        private readonly SoldOutMarks|null $soldOut = null,
        private readonly Connection|null $connection = null,
    ) {
    }

    public function __invoke(): void
    {
        $productIds = $this->queue->take();
        $shopSync = $this->queue->takeShopSync();

        if ([] === $productIds && !$shopSync) {
            return;
        }

        if ([] !== $productIds) {
            $this->prices->refresh($productIds);
        }

        // A changed home country or currency changes the list prices of the
        // whole shop (C25) — and the visibility reads them, so this comes
        // before both visibility steps.
        $changed = $shopSync && $this->prices->syncMarkets();

        if ([] !== $productIds && $this->visibility->refresh($productIds)) {
            $changed = true;
        }

        if ($shopSync && $this->visibility->syncShops()) {
            $changed = true;
        }

        if ($changed) {
            $this->catalog?->invalidate([CatalogCacheTags::LISTINGS]);
            $this->sitemaps?->invalidateShopSitemaps();
        }

        // After the rest, and without the sitemaps: being sold out moves a
        // listing, never an address.
        // prune(): a deleted main product took its variants along, unreported.
        if ([] !== $productIds && null !== $this->soldOut) {
            $marked = $this->soldOut->refresh($this->withVariants($productIds));
            $pruned = $this->soldOut->prune();

            if (($marked || $pruned) && !$changed) {
                $this->catalog?->invalidate([CatalogCacheTags::LISTINGS]);
            }
        }
    }

    /**
     * The saved products and the variants below them: the marks sit on
     * articles, and a main product's switches decide its variants' state.
     *
     * @param list<int> $productIds
     *
     * @return list<int>
     */
    private function withVariants(array $productIds): array
    {
        if (null === $this->connection) {
            return $productIds;
        }

        $variants = [];

        foreach (array_chunk($productIds, 500) as $block) {
            $variants = [...$variants, ...array_map(intval(...), $this->connection->fetchFirstColumn(
                'SELECT id FROM tl_consho_product WHERE pid IN (?)',
                [$block],
                [ArrayParameterType::INTEGER],
            ))];
        }

        return array_values(array_unique([...$productIds, ...$variants]));
    }
}

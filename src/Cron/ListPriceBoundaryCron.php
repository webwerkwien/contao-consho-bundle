<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\CatalogPriceRules;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketCriteria;
use Webwerkwien\ContaoConshoBundle\Seo\SitemapCacheTags;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;

/**
 * Switches the list price projection when a price's validity begins or ends
 * (A-4b, decision 5). Nobody saves anything at that moment, so no back end
 * trigger fires.
 *
 * ── No marker for "since the last run" ───────────────────────────────────
 *
 * Contao sets tl_cron_job.lastRun to now BEFORE it runs a job and hands the job
 * nothing but the scope (Cron::doRun(), CronJob::__invoke(), Contao 5.7). A
 * marker row of our own in tl_cron_job would be removed by purgeOldRecords().
 *
 * The projection carries the marker instead: every row has the tstamp it was
 * written at. A boundary that passed after that moment makes the row stale —
 * whether the cron ran a minute ago or was down for a week.
 *
 * ── When a price row is due ──────────────────────────────────────────────
 *
 * Only rows that can be a list price: no customer group, threshold up to 1.
 *
 * - It began and still applies, and its product's row in a market of that
 *   currency is older than the beginning — or missing. After the refresh the
 *   row exists with a newer tstamp, so it is due exactly once.
 * - It ended, and a row older than the end exists. After the refresh that row
 *   is newer or gone.
 *
 * Deliberately NOT due: a price that began and ended without a row ever being
 * written. It would be recalculated every minute and change nothing.
 *
 * ── Markets (C25) ────────────────────────────────────────────────────────
 *
 * Rows exist per market — a currency chosen on some start page together with
 * the home country of that start page's shop (C-1c, spec 4.0). A price in a
 * currency no start page sells in, or in a shop without a home country, never
 * gets a row, so the query joins over the markets.
 *
 * One case stays open on purpose: a market whose country has no rate for the
 * article's tax class never gets a row either, and a dated price of such an
 * article stays due every minute — rewritten on every run, because refresh()
 * always writes, but invalidating nothing any more: refresh() only counts an
 * amount that actually changed (I-1), and "still no row" is not a change.
 * Excluding it here would state the rate rule a second time, in SQL;
 * TaxRateMissing names the cause on the start page, but only when the article
 * is assigned to a shop of that country — otherwise not at all.
 *
 * ── Tax rates (C25) ──────────────────────────────────────────────────────
 *
 * The projection stores gross, so a rate that begins or ends switches it too.
 * Here the row's tstamp is no marker: an article that had no row before the
 * rate began — no rate, no gross — would never become due. So the moment up to
 * which every rate boundary has been applied is kept in
 * tl_consho_projection_state, as VisibilityBoundaryCron does, and each run
 * takes the classes with a boundary in (marker, now].
 *
 * A change of the standard rate reports every article of that class once —
 * the same trade-off as TaxRateRefreshTriggerListener. Should such a run fail
 * (time limit of a web cron), the marker stays and it is retried every
 * minute, so run Contao's cron from the command line.
 */
#[AsCronJob('minutely')]
class ListPriceBoundaryCron
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ListPriceProjector $projector,
        private readonly SitemapCacheTags|null $sitemaps = null,
        private readonly CatalogCacheTags|null $catalog = null,
        private readonly VisibilityProjector|null $visibility = null,
        private readonly ProjectionState|null $state = null,
        private readonly TaxRateRepositoryInterface|null $rates = null,
        private readonly \Closure|null $clock = null,
        // Block H (H-3): a catalogue price rule that begins or ends.
        private readonly CatalogPriceRules|null $priceRules = null,
    ) {
    }

    public function __invoke(): void
    {
        $now = null !== $this->clock ? ($this->clock)() : time();
        $last = $this->state?->get(ProjectionState::LIST_PRICE_TAX_BOUNDARIES);

        $taxBoundaryIds = null !== $last ? $this->productIdsWithTaxBoundary($last, $now) : [];

        // Block H (H-3): catalogue price rules carry no row in the projection
        // whose tstamp could mark them, so they ride on the tax marker — the
        // same window (last, now], the same reason. And like a tax boundary
        // they switch the product pages too, which read prices themselves.
        // A worker running the cron keeps its services: read the rules anew.
        $this->priceRules?->reset();
        $ruleBoundaryIds = null !== $last && null !== $this->priceRules && $last < $now ? $this->priceRules->productIdsWithBoundary($last, $now) : [];
        $pageBoundaryIds = [...$taxBoundaryIds, ...$ruleBoundaryIds];

        $productIds = array_values(array_unique([
            ...$this->productIdsDue($now),
            ...$pageBoundaryIds,
        ]));

        // A price that ends can take a product out of the sitemap, one that
        // begins can bring it in. Nobody saves anything in the back end at that
        // moment, so the DCA callback that invalidates the sitemap never runs
        // (SitemapCacheTagListener) — and Contao keeps it cached for a month.
        //
        // A tax boundary counts even when refresh() changed nothing: a run that
        // failed after writing the list prices (time limit in the visibility
        // refresh below) leaves the marker behind, and the retry finds the new
        // amounts already written. Only this branch brings the tiles and caches
        // up to date then. A hanging market without a rate (see above) has no
        // tax boundary and still invalidates nothing.
        if ([] !== $productIds && ($this->projector->refresh($productIds, $now) > 0 || [] !== $pageBoundaryIds)) {
            // The visibility projection carries "priced" and the "from" price,
            // both calculated from the list prices just switched (B-10) — so
            // after them, never before.
            $this->visibility?->refresh($productIds, $now);

            $this->sitemaps?->invalidateShopSitemaps();

            // The listings show, filter and sort by the projection (B-7b).
            $tags = [CatalogCacheTags::LISTINGS];

            // A tax boundary also switches the gross on the DETAIL page (I-2):
            // it tags CATALOG and its own product tag, not LISTINGS. A price
            // boundary alone does not need this — the product page reads the
            // prices itself and sets its own lifetime up to the next boundary.
            if ([] !== $pageBoundaryIds) {
                $tags[] = CatalogCacheTags::CATALOG;
            }

            $this->catalog?->invalidate($tags);
        }

        // Only after the refresh: a failure above leaves the boundaries due.
        // Without a marker nothing is known to have become due since — on a
        // fresh installation there is nothing before the first run but saves,
        // and every save of a price, product or tax rate is recalculated by
        // its trigger listener at the end of the request; on an existing one
        // the state before is ListPriceMarketMigration's rebuild. One gap
        // stays: a rate that begins or ends between being saved (or that
        // rebuild) and the first run is applied by neither — usually a minute,
        // longer where the cron is not set up yet — until something is saved.
        //
        // Only move the marker forward. A clock jump or overlapping runs could
        // otherwise make $last >= $now above and write an older value here —
        // harmless either way, but consistent with VisibilityBoundaryCron.
        // Unlike there, this method does NOT return early when $last >= $now:
        // the price boundaries above hang on each row's tstamp, not on this
        // marker, and must be applied regardless.
        if (null === $last || $now > $last) {
            $this->state?->set(ProjectionState::LIST_PRICE_TAX_BOUNDARIES, $now);
        }
    }

    /**
     * Every article — main product or variant — whose tax class has a rate
     * beginning or ending in (last, now].
     *
     * @return list<int>
     */
    public function productIdsWithTaxBoundary(int $last, int $now): array
    {
        if (null === $this->rates || $last >= $now) {
            return [];
        }

        $classes = $this->rates->findClassesWithBoundaryIn($last, $now);

        if ([] === $classes) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_consho_product WHERE taxClass IN (:classes) ORDER BY id',
            ['classes' => $classes],
            ['classes' => ArrayParameterType::INTEGER],
        ));
    }

    /**
     * @return list<int>
     */
    public function productIdsDue(int $now): array
    {
        // Joined over the markets (C25): a row exists per currency AND home
        // country of some shop, so a price in a currency no start page sells
        // in, or in a shop without a home country, never gets one and must not
        // count as due.
        //
        // The markets come from MarketCriteria, not from DbalMarketRepository:
        // this has to stay ONE statement, because pulling the markets into PHP
        // first would mean filtering the price rows there too. So the rule is
        // shared as SQL rather than copied — including the missing published
        // filter, whose reasoning is over there.
        //
        // p.tstamp > 0: see DbalPriceRepository::findCandidates() — a price
        // mask opened and never saved is never due. Subscription prices count
        // since Abo-2b: a product not sold once is listed with its first plan
        // (P88), and that price has boundaries too. A plan price that is not
        // the figure costs one refresh that changes nothing.
        $productIds = $this->connection->fetchFirstColumn(
            \sprintf(
                "SELECT DISTINCT p.pid
             FROM tl_consho_price p
             JOIN (%s) m
               ON m.currency = p.currency
             LEFT JOIN tl_consho_product_list_price lp ON lp.pid = p.pid AND lp.currency = m.currency AND lp.country = m.country
             WHERE p.customerGroup = 0
               AND p.tstamp > 0
               AND p.minQuantity <= 1
               AND (
                   (p.validFrom IS NOT NULL AND p.validFrom <= :now
                    AND (p.validTo IS NULL OR p.validTo > :now)
                    AND (lp.id IS NULL OR lp.tstamp < p.validFrom))
                   OR
                   (p.validTo IS NOT NULL AND p.validTo <= :now
                    AND lp.id IS NOT NULL AND lp.tstamp < p.validTo)
               )
             ORDER BY p.pid",
                MarketCriteria::sql(),
            ),
            ['now' => $now],
        );

        return array_map('intval', $productIds);
    }
}

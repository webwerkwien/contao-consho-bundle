<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Catalogue pages set to "always load from cache" in a shop that prices per
 * customer (C-4).
 *
 * ── Why this is not covered by Contao making the page private ─────────────
 *
 * Two mechanisms, and only one of them is about storing:
 *
 *   - STORING — MakeResponsePrivateListener marks the response of a logged-in
 *     member private, so a personal price never enters the shared cache. That
 *     works, and nothing here doubts it.
 *   - SERVING — the member only gets their own page because Contao sends
 *     `Vary: Cookie`, and it sends that only while `alwaysLoadFromCache` is
 *     OFF (AbstractController::addSharedMaxAgeToResponse). Contao's own
 *     comment names the purpose: "serving public pages from cache to logged
 *     in members".
 *
 * With the box ticked, a member is handed the page a guest filled the cache
 * with: gross tiles saying "incl. VAT" and no group hint, beside an ESI mini
 * basket — which is never cached — counting in net and saying "plus VAT". The
 * same page contradicts itself, and nothing errors.
 *
 * ── Why it is a note and not a warning ────────────────────────────────────
 *
 * The operator ticked the box themselves, it is off by default, and every
 * price shown is still a real price of this shop — the wrong one for this
 * visitor. Blocking a checkout over a page setting would be the heavier
 * mistake. But it has to be SAID: before C-4 the box was harmless here,
 * everybody saw the same catalogue, and nothing in its description hints that
 * customer prices changed that.
 *
 * ── Every such page, without asking which shop it belongs to ──────────────
 *
 * Deliberately blunt. `tl_page` is a nested set with no `rootId` column, so
 * walking to the start point in SQL means either a recursive query or a join
 * that reaches one level and pretends to be complete — and a finding that
 * misses the page three levels down is worse than one that names a page too
 * many. Which of them shows a price is the same problem again: an element sits
 * in an article, in a layout section, behind an alias or in a nested fragment.
 *
 * So the sentence carries the limit instead of the query: it names the pages
 * and says what to check — including BELOW them, because a child without cache
 * settings of its own inherits the tick (PageModel::loadDetails(), 991-994).
 * The operator ticked those boxes and knows where their catalogue is.
 */
final class DbalCachedCatalogPageIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        /*
         * Only for shops that actually price per customer. Without a net price
         * group and without a single group price row the finding would be
         * noise: every visitor sees the same amount, which is exactly what the
         * shared cache is for.
         */
        $pricesPerCustomer = (bool) $this->connection->fetchOne(
            "SELECT 1
             WHERE EXISTS (SELECT 1 FROM tl_consho_shop WHERE netPriceGroups IS NOT NULL AND netPriceGroups <> '')
                OR EXISTS (SELECT 1 FROM tl_consho_price WHERE customerGroup > 0 AND tstamp > 0)",
        );

        if (!$pricesPerCustomer) {
            return [];
        }

        /*
         * `includeCache` as well, and not for tidiness: loadDetails() reads
         * `alwaysLoadFromCache = includeCache ? alwaysLoadFromCache : false`
         * (PageModel, 933). A tick left behind on a page whose "override cache
         * settings" was switched off again does nothing at all, and reporting
         * it would send the operator after a box that is already harmless.
         *
         * The pair is only ever set on the row the operator edited — pages
         * below inherit it at runtime without carrying it — so these are
         * exactly the boxes there are to untick.
         */
        $pages = $this->connection->fetchFirstColumn(
            'SELECT title FROM tl_page WHERE alwaysLoadFromCache = 1 AND includeCache = 1 ORDER BY title',
        );

        if ([] === $pages) {
            return [];
        }

        return [new ConfigurationIssue(
            ConfigurationFinding::CatalogPageAlwaysCached,
            ['pages' => implode(', ', array_map(strval(...), $pages))],
        )];
    }
}

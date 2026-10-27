<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Published products that no visitor will ever see in a listing (plan B-8).
 *
 * "Nothing fails, nothing sells": the operator creates a product, publishes it
 * and waits. Two causes are cheap to count and certain, so they go to the start
 * page (B-8, E1):
 *
 * | ProductShopMissing  | assigned to no shop at all — no listing, no product page |
 * | ProductPriceMissing | visible in a shop, but no price in one of the currencies that shop sells in — no listing, no sitemap |
 *
 * Since C-1c a shop sells in as many currencies as its start pages have picked
 * (spec 4.0), so the price question is asked once per currency and answered
 * once per currency: a product priced in euro but not in pounds is a finding
 * about pounds, and the euro side of the shop is in order.
 *
 * Whether a product with a shop and a price stands in NO listing depends on
 * every listing's preset and pins; that has to compute and belongs to
 * consho:catalog:verify, not here.
 *
 * ── The rule comes from the listing, not from here ────────────────────────
 *
 * "Without a price" is not restated: it is what VisibilityCriteria makes
 * visible minus what ProductQuery, the listing's own query, finds. A second
 * copy of "priceable" would drift from the first — variants carrying the price,
 * the projection, whatever comes next.
 *
 * Only "published within its window" is written out for the shop finding: a
 * product without a shop fails VisibilityCriteria at once, so there is nothing
 * of it to subtract from.
 *
 * Variants are not counted: they are never listed on their own, and one that
 * narrows its shops only narrows its main product (A-12, same as the GTIN).
 *
 * ⚠️ Runs on every call of the start page. One aggregate query, one per shop
 * for its start pages, and — only for a shop that sells in at least one
 * currency — one for its default language, one for the count to subtract from,
 * and one per currency. A shop with no home country stops before its start
 * pages are read at all; one without a currency stops after them, with
 * CurrencyMissing in its place.
 */
final class DbalCatalogCompletenessIssueSource implements ConfigurationIssueSource
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly VisibilityCriteria $criteria,
        private readonly VisibilityRows $rows,
        private readonly ShopLanguages $languages,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function issues(): array
    {
        $now = null !== $this->clock ? ($this->clock)() : time();
        $issues = [];

        $withoutShop = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_consho_product p
             WHERE p.pid = 0 AND p.published = 1
               AND (p.start = '' OR p.start <= :now) AND (p.stop = '' OR p.stop > :now)
               AND NOT EXISTS (SELECT 1 FROM tl_consho_product_shop ps WHERE ps.pid = p.id)",
            ['now' => (string) $now],
        );

        if ($withoutShop > 0) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::ProductShopMissing, ['count' => (string) $withoutShop]);
        }

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, country FROM tl_consho_shop ORDER BY id') as $shop) {
            $shopId = (int) $shop['id'];
            $country = (string) $shop['country'];

            // Without a home country nothing in the shop has a gross, so every
            // product would count as unpriced. HomeCountryMissing names the
            // one cause instead of this finding counting its effect.
            if ('' === $country) {
                continue;
            }

            $currencies = $this->currenciesOf($shopId);

            // The counterpart of the home country above: no currency, no
            // market, no list price, no listing — every product would count as
            // unpriced and the big number would name the effect. So the cause
            // gets named and the counting is skipped.
            //
            // ⚠️ Only the finding is load-bearing here, not the `continue`: the
            // loop below would run no round on an empty list anyway, so no test
            // can tell the two apart — the same observation the comment on the
            // loop used to make about a guard that "could never turn red". It
            // stays for the shape, in the same form as the home country above,
            // where it IS what stops the counting.
            if ([] === $currencies) {
                // With the shop: since block I this finding stops "ready" —
                // of this shop, not of every shop (I).
                $issues[] = new ConfigurationIssue(ConfigurationFinding::CurrencyMissing, ['shop' => (string) $shop['title']], $shopId);

                continue;
            }

            $fallback = null;
            $visibleCount = null;

            // The two reads below sit INSIDE the loop on purpose — each costs
            // a query and this runs on every call of the start page, so the
            // first currency pays for them and the rest do not. ??= keeps it
            // at one per shop rather than one per currency.
            foreach ($currencies as $currency) {
                // The default language: a translation gap is its own finding
                // (DbalTranslationGapIssueSource) and must not be counted twice.
                $fallback ??= $this->languages->fallbackOf($shopId);

                $visibleCount ??= $this->visibleCount($shopId, $now, $fallback);

                // 🔴 Both sides live, not the listing's count. ProductQuery reads
                // the visibility projection (B-10); a stale projection would show
                // up here as a missing price. A stale projection is its own
                // finding (VisibilityIssueSource), this one is about the source
                // data.
                $offered = $this->rows->mains($shopId, $fallback, $fallback, $currency, $country, $now);

                $missing = $visibleCount - (int) $this->connection->fetchOne(
                    \sprintf('SELECT COUNT(*) FROM (%s) offered', $offered->sql),
                    $offered->params,
                    $offered->types,
                );

                if ($missing > 0) {
                    $issues[] = new ConfigurationIssue(
                        ConfigurationFinding::ProductPriceMissing,
                        ['shop' => (string) $shop['title'], 'currency' => $currency, 'count' => (string) $missing],
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * How many main products this shop would show, if they were all priced.
     *
     * The subtrahend of the finding, read once per shop — not once per
     * currency, and not at all for a shop that sells in none. That the caller
     * remembers it is a cache and not a rule: recomputing it per currency
     * would give the same answer, so no test can tell the difference.
     */
    private function visibleCount(int $shopId, int $now, string $fallback): int
    {
        $visible = $this->criteria->mainProduct($shopId, $now, 'p', $fallback, $fallback);

        return (int) $this->connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM tl_consho_product p WHERE %s', $visible->sql),
            $visible->params,
        );
    }

    /**
     * The currencies this shop actually sells in — one per start page below it
     * (C-1c, spec 4.0), not one column on the shop.
     *
     * A start page without a currency is skipped rather than counted as a
     * nameless one: it shows nothing at all, so counting its products as
     * unpriced would name an effect and not the cause.
     *
     * Who names it depends on what is missing. An empty list here means the
     * shop sells in nothing at all, and the caller reports CurrencyMissing for
     * it — including for a shop no start page points at yet. The single start
     * page without a currency beside others that have one is named by
     * RootWithoutCurrency (DbalShopScopeIssueSource), which can say WHICH page
     * it is; this source never sees it, because its shop still has currencies.
     *
     * @return list<string> sorted, each once
     */
    private function currenciesOf(int $shopId): array
    {
        $currencies = [];

        foreach ($this->languages->audiencesOf($shopId) as $audience) {
            if ('' !== $audience['currency']) {
                $currencies[$audience['currency']] = true;
            }
        }

        $currencies = array_keys($currencies);
        sort($currencies);

        return $currencies;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * What a market is, as one SELECT (C-1c, spec 4.0).
 *
 * A market is a currency together with the home country its gross is
 * calculated for. The currency comes from the START PAGE and the home country
 * from its shop: the currency is a property of the audience, the home country
 * a property of the invoicing entity. A shop selling over /en/ in pounds is
 * therefore two markets, not two shops; two start pages of the same currency
 * are one.
 *
 * ── Why a class and not just the query in the repository ──────────────────
 *
 * Two readers need this, and one of them cannot go through
 * DbalMarketRepository: ListPriceBoundaryCron has to decide in ONE statement
 * which price rows are due, because pulling the markets into PHP first would
 * mean filtering the price rows there too. So it needs the SQL, not the
 * objects — and before this class it held a second copy of it.
 *
 * The copies had already begun to drift: the cron's differed in its table
 * alias (`p` was taken by tl_consho_price) and carried no ORDER BY. Same rule,
 * two spellings — the state the comment "keep it word for word" was trying to
 * hold together by hand. Same reasoning as VisibilityCriteria, which exists
 * because "four copies of the same condition are three too many".
 *
 * ⚠️ This is not the only place the rule is expressed, and it cannot be:
 * DbalCatalogCompletenessIssueSource needs the currencies OF ONE SHOP, and a
 * market carries no shop id — it rebuilds the rule per shop from
 * ShopLanguages::audiencesOf() plus its own home-country guard. Two spellings
 * remain, deliberately, for two different questions.
 */
final class MarketCriteria
{
    /**
     * The market pairs as `currency` and `country`, without an ORDER BY so a
     * caller can add one — or leave it off where the order cannot matter.
     *
     * Static and without arguments because there is nothing to configure and
     * nothing to substitute: the string IS the definition.
     *
     * ⚠️ The table aliases are spelled out rather than taken from the caller.
     * A caller never sees them — the query is self-contained, and both callers
     * wrap it (`ORDER BY …`, `JOIN (…) m`) instead of splicing it into a FROM
     * clause of their own, which is what VisibilityCriteria's $alias is for.
     * Long names because `p` would shadow the cron's `tl_consho_price p`:
     * legal, since nothing here correlates with the outer query, but exactly
     * the kind of thing a reader has to stop and check.
     */
    public static function sql(): string
    {
        /*
         * ⚠️ Deliberately NO filter on published.
         *
         * The list price projection has a row per market. With a published
         * filter, depublishing a start page would silently take every price
         * row of its currency out of the projection — the products would drop
         * out of the listings of a site that is still online in that currency,
         * and nothing would say why.
         *
         * It also closes the only way to prepare a currency before anyone sees
         * it: create the start page, pick GBP, enter the prices (the price
         * mask only offers currencies that are markets), publish.
         *
         * Block A made exactly this mistake once, deriving the delivery
         * countries, and dropped it — see Block A, section
         * 7: an invisible record must not quietly shrink the shop.
         *
         * ⚠️ Two of the five conditions are redundant — measured, by turning
         * each around on its own: `consho_shop > 0` (no shop has id 0, so the
         * join drops the row anyway) and the join being an INNER one (a
         * deleted shop yields country NULL, and NULL <> '' is false). Neither
         * turns a test red alone, so do not look for the one that covers it.
         * They stay because together the five say the rule in full, in the
         * same words as RootCurrencyMigration and SitemapCacheTags, which
         * have no join to lean on.
         *
         * DISTINCT likewise does nothing for the cron, whose own SELECT
         * DISTINCT collapses the duplicates — it is here for the repository,
         * which has none.
         */
        return "SELECT DISTINCT market_page.consho_currency AS currency, market_shop.country AS country
                  FROM tl_page market_page
                  JOIN tl_consho_shop market_shop ON market_shop.id = market_page.consho_shop
                 WHERE market_page.type = 'root'
                   AND market_page.consho_shop > 0
                   AND market_page.consho_currency <> ''
                   AND market_shop.country <> ''";
    }
}

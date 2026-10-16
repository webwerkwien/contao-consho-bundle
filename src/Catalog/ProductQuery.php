<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationJoin;

/**
 * Which products a listing shows.
 *
 * Reads the visibility projection, tl_consho_product_visibility — see
 * docs/block-b-katalog-frontend.md, section 5.7. One row per main product that
 * a listing of this shop, language and currency may show, visible AND priced,
 * with its "from" price; one row per visible variant.
 *
 * Why a projection: B-9 measured that evaluating visibility inside the listing
 * query grows linearly with the catalogue — every listing query scanned
 * tl_consho_product and ran the rules per product row. The rules themselves did
 * not move here: they live in VisibilityCriteria, and VisibilityRows turns them
 * into the rows this class reads. Nothing here decides what is visible or
 * priced; it only asks the table.
 *
 * Consequently there is no `$now` here. The moment is the one the projection
 * was calculated at. A parameter that changes nothing would only mislead.
 *
 * `$currency`, on the other hand, came back with C-1c task 5b — not as a
 * calculation but as part of the key. The projection is unique on
 * (pid, shop, language, currency), because one shop can be reached in German
 * through example.de in euro and through example.ch in francs. Asking for
 * shop and language alone would return BOTH rows of every product, so every
 * listing would show each article twice.
 *
 * The language is concrete — a root page's language, the default language
 * included. An empty language has no rows and finds nothing; so has an empty
 * currency.
 *
 * ── The two places a filter value can live ────────────────────────────────
 *
 * `tl_consho_product_property` holds filter information about the main product
 * — "Bio", "Kräuterbitter". `tl_consho_product_option` holds the variant axes —
 * "Größe M" is not a statement about the main product, it is what makes one row
 * a particular variant.
 *
 * > 🔴 A filter has to search BOTH. "Größe M" hangs on no row of the main
 * > product, so a query that only reads the property table returns nothing at
 * > all — and nothing fails while it does. The main product qualifies when any
 * > of its VISIBLE variants qualifies — a variant row of the same shop and the
 * > same language.
 */
final class ProductQuery implements FilterCountsInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<int, list<int>> $groups   property group id => selected option ids.
     *                                        OR within a group, AND across groups.
     * @param list<int>             $excluded        products the listing already shows above the rule — its pins (B-7)
     * @param bool                  $availableOnly   the visitor's "Nur verfügbare" (block Verfügbarkeit, V5)
     * @param bool                  $unavailableLast the element's "Nicht Verfügbares ans Ende" (V4): before any order
     *
     * @return list<int> ids of the matching main products
     */
    public function find(
        int $shopId,
        array $groups,
        string $language,
        string $currency,
        string $fallback,
        string $sort = ProductSort::TITLE,
        bool $showsNet = false,
        int $offset = 0,
        int $limit = 0,
        array $excluded = [],
        bool $availableOnly = false,
        bool $unavailableLast = false,
    ): array {
        [$where, $params, $types, $keys] = $this->conditions($shopId, $groups, $language, $currency, $excluded, $availableOnly);

        // Alias `tr`, not `t` — as in DbalProductTileRepository, which builds
        // the same join for the same table.
        $join = new TranslationJoin('tl_consho_product', $language, $fallback, 'p', 'tr');

        /*
         * Sold out last (V4): available is what "Nur verfügbare" would keep —
         * with the visitor's filters, so a shirt whose red one is gone sorts
         * last under "rot" although its blue one is there. With the filter
         * itself on, everything left is available and the term would sort
         * nothing.
         */
        $first = $unavailableLast && !$availableOnly
            ? \sprintf('CASE WHEN %s THEN 0 ELSE 1 END, ', $this->matches($keys, true))
            : '';

        $sql = \sprintf(
            'SELECT m.main FROM tl_consho_product_visibility m
             JOIN tl_consho_product p ON p.id = m.main
             %s
             WHERE %s
             ORDER BY %s%s',
            $join->sql(),
            $where,
            $first,
            $this->orderBy($sort, $showsNet, $join),
        );

        if ($limit > 0) {
            $sql .= \sprintf(' LIMIT %d OFFSET %d', $limit, max(0, $offset));
        }

        $ids = $this->connection->fetchFirstColumn($sql, [...$params, ...$join->params()], $types);

        return array_map(intval(...), $ids);
    }

    /**
     * How many products the listing has in total — what pagination needs.
     *
     * @param array<int, list<int>> $groups
     * @param list<int>             $excluded
     */
    public function count(int $shopId, array $groups, string $language, string $currency, array $excluded = [], bool $availableOnly = false): int
    {
        // No join here — count() only counts, it doesn't sort. The unique key
        // (pid, shop, language, currency) already keeps one row per main
        // product.
        [$where, $params, $types] = $this->conditions($shopId, $groups, $language, $currency, $excluded, $availableOnly);

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_consho_product_visibility m WHERE '.$where,
            $params,
            $types,
        );
    }

    /**
     * Which of the given products the listing may show, in the given order.
     *
     * For the pins of a listing (B-7). The same condition as find() — shop,
     * visibility, a price — with the groups the CALLER passes: the visitor's
     * filter, not the editor's preset. A pin beats the preset, never
     * visibility, and it still has to match what the visitor narrowed to.
     *
     * The order is the editor's, dragged in the back end; the database is only
     * asked which ids pass. Twice the same product counts once, at its first
     * place.
     *
     * @param list<int>             $ids
     * @param array<int, list<int>> $groups
     *
     * @return list<int>
     */
    public function visibleAmong(array $ids, int $shopId, array $groups, string $language, string $currency, bool $availableOnly = false): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $ids)));

        if ([] === $ids) {
            return [];
        }

        // "Nur verfügbare" is the visitor's choice like a filter: a pin that
        // is sold out leaves the listing with it (rule 5, block B 3.1).
        [$where, $params, $types] = $this->conditions($shopId, $groups, $language, $currency, [], $availableOnly);

        $found = array_flip(array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT m.main FROM tl_consho_product_visibility m WHERE '.$where.' AND m.main IN (:consho_q_among)',
            [...$params, 'consho_q_among' => $ids],
            [...$types, 'consho_q_among' => ArrayParameterType::INTEGER],
        )));

        return array_values(array_filter($ids, static fn (int $id): bool => isset($found[$id])));
    }

    /**
     * How many products each option would leave, if it were chosen.
     *
     * ── 🔴 Self-exclusion, and why it is not optional ─────────────────────
     *
     * The numbers of a group are counted under every filter EXCEPT that
     * group's own. Without it, choosing "rot" puts a zero next to "blau" —
     * although eight blue articles exist — and the control the customer would
     * use to change their mind looks dead. That is worse than showing no
     * numbers at all.
     *
     * So the caller asks once per active group with `$without` set to that
     * group, and once with `$without = null` for every group the customer has
     * not touched.
     *
     * ── Why this shares its condition with find() ─────────────────────────
     *
     * Literally, not by resemblance. Two conditions that drift apart give a
     * filter whose number does not match what clicking it produces, and that is
     * the kind of defect nobody reports because it looks like an off-by-one in
     * somebody else's head.
     *
     * @param array<int, list<int>> $groups
     * @param int|null              $without the group to leave out of the condition
     *
     * @return array<int, int> option id => number of products
     */
    public function countsFor(int $shopId, array $groups, int|null $without, string $language, string $currency, bool $availableOnly = false): array
    {
        if (null !== $without) {
            unset($groups[$without]);
        }

        // No join — same reasoning as count(): countsFor() counts facets, it
        // doesn't sort.
        [$where, $params, $types, $keys] = $this->conditions($shopId, $groups, $language, $currency, [], $availableOnly);
        [$base] = $this->conditions($shopId, [], $language, $currency);

        /*
         * The matching products stay a subquery instead of a list of ids. A
         * list would be a second round trip and, with a large catalogue, an IN
         * clause of thousands of numbers.
         *
         * Both tables contribute: an option can describe the main product or be
         * a variant axis. A variant axis counts only through a variant row of
         * the same shop and language — a variant hidden there (B-8) has none.
         * UNION ALL rather than UNION, because COUNT(DISTINCT) below removes the
         * duplicates anyway and sorting them twice is waste.
         *
         * Aliases `fp`, `fv`, `fo` for the outer reads: `pp`, `mv`, `mn`, `po`
         * and `so` are taken by the group condition inside the subquery.
         *
         * 🔴 A variant axis counts through a variant that ITSELF meets every
         * other chosen group and, with "Nur verfügbare", is not sold out
         * (block Verfügbarkeit, F2/V6) — the same variant find() asks for.
         * Counted through the product, "M" would show the shirt under "rot"
         * whose red one exists only in S, and the click would find nothing.
         * A property of the main product counts through the product, which
         * carries it for every variant.
         *
         * ⚠️ `fv.currency` cannot change the answer, and is here on purpose.
         * Variant rows do not depend on the currency — VisibilityRows::
         * variants() asks no price — so one rebuild writes the same set under
         * every currency of a language, and COUNT(DISTINCT) would remove the
         * duplicates anyway. It earns its place twice over: it completes the
         * prefix of the index (shop, language, currency, variant, main), and it
         * keeps the read inside ONE of the projector's transactions, which are
         * one per target (VisibilityProjector). No unit test can kill it; that
         * is a statement about the line, not about the tests.
         */
        $sql = \sprintf(
            'SELECT o.optionId AS optionId, COUNT(DISTINCT o.productId) AS total
             FROM (
                 SELECT fp.optionId AS optionId, fp.pid AS productId
                 FROM tl_consho_product_property fp
                 WHERE fp.pid IN (SELECT m.main FROM tl_consho_product_visibility m WHERE %1$s)

                 UNION ALL

                 SELECT fo.optionId AS optionId, fv.main AS productId
                 FROM tl_consho_product_visibility fv
                 JOIN tl_consho_product_option fo ON fo.pid = fv.pid
                 WHERE fv.shop = :consho_q_shop AND fv.language = :consho_q_language AND fv.currency = :consho_q_currency AND fv.variant = 1
                   AND fv.main IN (SELECT m.main FROM tl_consho_product_visibility m WHERE %2$s)
                   AND %3$s
             ) o
             GROUP BY o.optionId',
            $where,
            $base,
            $this->articleMatches('fv.main', 'fv.pid', $keys, $availableOnly, true),
        );

        $counts = [];

        foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
            $counts[(int) $row['optionId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The WHERE clause all four public methods stand on, over the alias `m`.
     *
     * @param array<int, list<int>> $groups
     * @param list<int>             $excluded
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: list<string>} the last: the parameter names of the groups
     */
    private function conditions(int $shopId, array $groups, string $language, string $currency, array $excluded = [], bool $availableOnly = false): array
    {
        // Main product rows only: a variant row is never a listing's result.
        //
        // The currency is not a refinement but part of the key: without it a
        // shop reached in German in two currencies would return every article
        // twice (C-1c task 5b).
        $where = ['m.shop = :consho_q_shop', 'm.language = :consho_q_language', 'm.currency = :consho_q_currency', 'm.variant = 0'];
        $params = ['consho_q_shop' => $shopId, 'consho_q_language' => $language, 'consho_q_currency' => $currency];
        $types = ['consho_q_shop' => ParameterType::INTEGER];

        // "NOT IN ()" is invalid SQL, and an empty list excludes nothing anyway.
        if ([] !== $excluded) {
            $where[] = 'm.main NOT IN (:consho_q_excluded)';
            $params['consho_q_excluded'] = $excluded;
            $types['consho_q_excluded'] = ArrayParameterType::INTEGER;
        }

        $keys = [];

        foreach ($groups as $groupId => $optionIds) {
            if ([] === $optionIds) {
                // An empty group is not a filter. Treating it as one would ask
                // for "in ()" and return nothing at all.
                continue;
            }

            $key = 'consho_q_group_'.$groupId;
            $keys[] = $key;
            $params[$key] = $optionIds;
            $types[$key] = ArrayParameterType::INTEGER;
        }

        // Nothing to ask of an article: no subquery at all, the listing as it
        // was before block Verfügbarkeit.
        if ([] !== $keys || $availableOnly) {
            $where[] = $this->matches($keys, $availableOnly);
        }

        return [implode(' AND ', $where), $params, $types, $keys];
    }

    /**
     * The product has an ARTICLE that meets every group and, with
     * $availableOnly, is not sold out — over the alias `m`.
     *
     * ── 🔴 One article for all groups (block Verfügbarkeit, F2) ───────────
     *
     * Until 2026-10-09 every group looked for a variant of its own: "rot" and
     * "M" found the shirt whose red one exists only in S and whose M only in
     * blue — a product the visitor then cannot buy in what they asked for.
     * Now one visible variant has to carry them all, each either as its own
     * axis or through a property of its main product, which holds for every
     * variant. "Nur verfügbare" is one more condition on that same variant
     * (V6): red AND available, not red and something else available.
     *
     * Without visible variants the article is the main product itself, and
     * only its properties count — as before.
     *
     * Equal to the old condition for a single group: "property OR some
     * variant carries the option" is "some variant meets (property OR own
     * option)" as long as a visible variant exists, and a product with
     * variants has one or is not in the projection (VisibilityCriteria).
     *
     * @param list<string> $keys parameter names of the groups, bound by conditions()
     */
    private function matches(array $keys, bool $availableOnly): string
    {
        return \sprintf(
            '(
                EXISTS (SELECT 1 FROM tl_consho_product_visibility mv
                        WHERE mv.main = m.main AND mv.shop = m.shop AND mv.language = m.language AND mv.currency = m.currency AND mv.variant = 1
                          AND %s)
                OR (NOT EXISTS (SELECT 1 FROM tl_consho_product_visibility mn
                                WHERE mn.main = m.main AND mn.shop = m.shop AND mn.language = m.language AND mn.currency = m.currency AND mn.variant = 1)
                    AND %s)
            )',
            $this->articleMatches('m.main', 'mv.pid', $keys, $availableOnly, true),
            $this->articleMatches('m.main', 'm.main', $keys, $availableOnly, false),
        );
    }

    /**
     * One article against every group, and against tl_consho_sold_out.
     *
     * `mv.currency = m.currency` in matches() is the same deliberate,
     * untestable predicate as `fv.currency` in countsFor() — see the comment
     * there.
     *
     * @param string       $main    the column holding the main product's id
     * @param string       $article the column holding the article's id
     * @param list<string> $keys    parameter names of the groups
     * @param bool         $variant whether the article is a variant: only a variant carries axis options
     */
    private function articleMatches(string $main, string $article, array $keys, bool $availableOnly, bool $variant): string
    {
        $parts = [];

        foreach ($keys as $key) {
            $property = \sprintf('EXISTS (SELECT 1 FROM tl_consho_product_property pp WHERE pp.pid = %s AND pp.optionId IN (:%s))', $main, $key);

            $parts[] = $variant
                ? \sprintf('(%s OR EXISTS (SELECT 1 FROM tl_consho_product_option po WHERE po.pid = %s AND po.optionId IN (:%s)))', $property, $article, $key)
                : $property;
        }

        if ($availableOnly) {
            $parts[] = \sprintf('NOT EXISTS (SELECT 1 FROM tl_consho_sold_out so WHERE so.article = %s)', $article);
        }

        return [] === $parts ? '1 = 1' : implode(' AND ', $parts);
    }

    /**
     * The ORDER BY clause, and a deliberate tie-break on every one of them.
     *
     * Titles repeat, prices repeat, a manual order is full of zeros until
     * somebody drags something. Without `p.id` behind them the order is
     * whatever the engine finds cheapest — which means a product can appear on
     * page one and page two of the same listing, and on neither.
     *
     * The price is the row's "from" price (VisibilityRows): with variants the
     * cheapest visible one, without variants the product's own — the same figure
     * the tile prints. Net or gross depends on who is looking: since A-5 a
     * customer with a valid VAT ID is billed under reverse charge and sees net,
     * everybody else gross. Sorting by the other column would order the listing
     * by a number the visitor cannot see.
     */
    private function orderBy(string $sort, bool $showsNet, TranslationJoin $join): string
    {
        $price = $showsNet ? 'm.net' : 'm.gross';

        return match ($sort) {
            ProductSort::PRICE_ASC => $price.' ASC, p.id ASC',
            ProductSort::PRICE_DESC => $price.' DESC, p.id ASC',
            // Newest first. `id` rather than `tstamp`: tstamp moves when
            // somebody fixes a typo, and a corrected description is not a new
            // product.
            ProductSort::NEWEST => 'p.id DESC',
            ProductSort::MANUAL => 'p.sorting ASC, p.id ASC',
            ProductSort::ID => 'p.id ASC',
            // In the default language, coalesce() simply returns `p.title`.
            default => $join->coalesce('title').' ASC, p.id ASC',
        };
    }
}

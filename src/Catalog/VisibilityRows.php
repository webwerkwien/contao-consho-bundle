<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\ArrayParameterType;

/**
 * The rows tl_consho_product_visibility has to hold — asked live.
 *
 * The one place where "visible" (VisibilityCriteria) meets "priced" and the
 * "from" price. The projector writes these rows, verify() compares against
 * them, and the diagnosis counts them: three readers, one set of rules. See
 * Block B, section 5.7.
 *
 * Both statements select the columns pid, main, variant, net, gross — in that
 * order, so the projector can put `INSERT INTO … SELECT` in front of them.
 *
 * ── Priceable, not priced ─────────────────────────────────────────────────
 *
 * A product is offered when it has a price, or — having variants — when a
 * visible variant has one. The plan for B-3 said to INNER JOIN the price
 * projection onto the main product; that would have hidden every
 * variant-based article, because with variants the prices sit on the variants.
 * Block A puts it plainly: a price belongs to "product/variant".
 */
final class VisibilityRows
{
    public function __construct(private readonly VisibilityCriteria $criteria)
    {
    }

    /**
     * Main products that a listing of this shop and language may show: visible
     * and priced, with their "from" price.
     *
     * @param string         $country  the shop's home country — the list price rows carry the gross calculated for it (C25)
     * @param list<int>|null $mainIds only these main products; null = all
     */
    public function mains(int $shopId, string $language, string $fallback, string $currency, string $country, int $now, array|null $mainIds = null): SqlStatement
    {
        $visibility = $this->criteria->mainProduct($shopId, $now, 'p', $language, $fallback);

        // A variant hidden in this language has no price here (B-8) — neither
        // for "priced" nor for the "from" price.
        $forPriceable = $this->criteria->variant($shopId, $now, 'v', $language, $fallback);
        $forPrice = $this->criteria->variant($shopId, $now, 'vp', $language, $fallback);

        $sql = \sprintf(
            'SELECT p.id AS pid, p.id AS main, 0 AS variant, %1$s AS net, %2$s AS gross
             FROM tl_consho_product p
             WHERE %3$s AND %4$s',
            $this->cheapestPrice('net', $forPrice->sql),
            $this->cheapestPrice('gross', $forPrice->sql),
            $visibility->sql,
            $this->priceable($forPriceable->sql),
        );

        $params = [...$visibility->params, ...$forPriceable->params, ...$forPrice->params, 'currency' => $currency, 'consho_home_country' => $country];
        $types = [];

        if (null !== $mainIds) {
            $sql .= ' AND p.id IN (:consho_ids)';
            $params['consho_ids'] = $mainIds;
            $types['consho_ids'] = ArrayParameterType::INTEGER;
        }

        return new SqlStatement($sql, $params, $types);
    }

    /**
     * Variants visible in this shop and language, priced or not — filters on a
     * variant axis and their numbers need exactly these.
     *
     * No price and no check of the main product's own visibility beyond what
     * VisibilityCriteria::variant() asks: every reader reaches a variant row
     * through a main product row, which carries the rest.
     *
     * @param list<int>|null $mainIds only variants of these main products; null = all
     */
    public function variants(int $shopId, string $language, string $fallback, int $now, array|null $mainIds = null): SqlStatement
    {
        $variant = $this->criteria->variant($shopId, $now, 'v', $language, $fallback);

        $sql = \sprintf(
            'SELECT v.id AS pid, v.pid AS main, 1 AS variant, NULL AS net, NULL AS gross
             FROM tl_consho_product v
             WHERE %s',
            $variant->sql,
        );

        $params = $variant->params;
        $types = [];

        if (null !== $mainIds) {
            $sql .= ' AND v.pid IN (:consho_ids)';
            $params['consho_ids'] = $mainIds;
            $types['consho_ids'] = ArrayParameterType::INTEGER;
        }

        return new SqlStatement($sql, $params, $types);
    }

    /**
     * What the product costs — and it is not simply its own price.
     *
     * 🔴 With variants the prices sit on the variants, so the main product row
     * may carry none at all. Colours, say, make no price
     * difference and the price stays on the main product; volumes do and it
     * moves to the variants. Both shapes occur in one catalogue.
     *
     * The minimum across the product and its VISIBLE variants is therefore the
     * only figure that sorts both correctly — and it is the same figure a tile
     * has to print as "ab 12,60 €".
     *
     * Once a product has variants, only they count — its own row does not
     * (A-4b, E2). A variant without a price of its own already carries the
     * main product's in the list price projection; the main product's row on
     * top would put "from 10 €" on a tile whose page, asking only the variants,
     * says "from 15 €". Any variant counts for "has variants", visible or not:
     * VisibilityCriteria already hides a product whose variants are all hidden.
     *
     * 🔴 Two subqueries joined by COALESCE, not one with an OR. Measured in
     * B-9 (MariaDB 10.11, 5,000 products): the OR form
     * `sp.pid IN (variants) OR (sp.pid = p.id AND no variants)` kept the
     * optimiser off the then (pid, currency) index — (pid, currency, country)
     * since C-1a — EXPLAIN showed a full scan of
     * all 12,076 list prices per product, and sorting by price ran into the
     * 20-second statement limit. Behind a query cache it goes
     * unnoticed.
     */
    private function cheapestPrice(string $column, string $variantIsVisible): string
    {
        return \sprintf(
            'COALESCE(
                (SELECT MIN(sp.%1$s) FROM tl_consho_product vp
                   JOIN tl_consho_product_list_price sp ON sp.pid = vp.id AND sp.currency = :currency AND sp.country = :consho_home_country
                  WHERE vp.pid = p.id AND %2$s),
                (SELECT sp.%1$s FROM tl_consho_product_list_price sp
                  WHERE sp.pid = p.id AND sp.currency = :currency AND sp.country = :consho_home_country
                    AND NOT EXISTS (SELECT 1 FROM tl_consho_product lpc WHERE lpc.pid = p.id))
            )',
            $column,
            $variantIsVisible,
        );
    }

    /**
     * Has a visible variant with a price — or, without any variants, a price
     * of its own. The same split as cheapestPrice(), for the same reason.
     */
    private function priceable(string $variantIsVisible): string
    {
        return \sprintf(
            '(
                (NOT EXISTS (SELECT 1 FROM tl_consho_product lpc WHERE lpc.pid = p.id)
                 AND EXISTS (SELECT 1 FROM tl_consho_product_list_price lp
                             WHERE lp.pid = p.id AND lp.currency = :currency AND lp.country = :consho_home_country))
                OR EXISTS (SELECT 1 FROM tl_consho_product v
                           JOIN tl_consho_product_list_price lpv ON lpv.pid = v.id AND lpv.currency = :currency AND lpv.country = :consho_home_country
                           WHERE v.pid = p.id AND %s)
            )',
            $variantIsVisible,
        );
    }
}

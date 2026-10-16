<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockState;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationJoin;

/**
 * Reads what a listing prints, for a page of products at a time.
 *
 * One query for the whole page rather than one per tile: a listing of thirty
 * products is a listing, not thirty listings. The ids come from ProductQuery,
 * already filtered, sorted and cut to the page.
 */
final class DbalProductTileRepository
{
    public function __construct(
        private readonly Connection $connection,
        // Block G (G7): the state on the tile. Optional for the tests built
        // by hand; without it a tile says nothing about stock, as before.
        private readonly Availability|null $availability = null,
    ) {
    }

    /**
     * @param list<int> $ids      in the order the listing wants them
     * @param string    $currency the currency that applies here — since C-1c task 5b it also picks the projection's row, not only its "from" price
     * @param string    $country  the shop's home country — the list price rows of its market (C25)
     *
     * @return list<ProductTile>
     */
    public function forIds(array $ids, string $currency, string $country, bool $showsNet, int $shopId, string $language, string $fallback): array
    {
        if ([] === $ids) {
            return [];
        }

        $column = $showsNet ? 'net' : 'gross';

        // Alias `tr`, not TranslationJoin's default `t` — as in ProductQuery,
        // which builds the same join for the same table.
        $translation = new TranslationJoin('tl_consho_product', $language, $fallback, 'p', 'tr');

        /*
         * The tile shows the number the listing was sorted by: the "from" price
         * of the main product's row in the visibility projection (section 5.7),
         * the same row ProductQuery orders by. With variants that is the
         * cheapest visible one, without the product's own (A-4b, E2); a variant
         * hidden in this language (B-8) has no row and sets nothing.
         *
         * Whether "from" goes in front is counted over the variant rows of the
         * same shop, language and currency — at most one page of products,
         * indexed. The old OR form over tl_consho_product (B-9) is gone with
         * it.
         *
         * The currency is part of the join since C-1c task 5b, and not for
         * tidiness: a shop reached in one language in two currencies has two
         * projection rows per article, and a LEFT JOIN without the currency
         * would return the product twice — one tile, with whichever price the
         * engine put first.
         *
         * ⚠️ On `vv` it cannot change the answer: variant rows do not depend on
         * the currency, and the count is DISTINCT over prices already joined on
         * it. It completes the index prefix (shop, language, currency, variant,
         * main) and keeps the read inside one of the projector's transactions —
         * as in ProductQuery::countsFor(), where the same is written out.
         */
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT
                     p.id AS id,
                     %2$s AS title,
                     %3$s AS teaser,
                     p.images AS images,
                     p.minPurchase AS minPurchase,
                     /*
                      * Ob es überhaupt Varianten gibt — nicht, ob sie sich im
                      * Preis unterscheiden. Zwei gleich teure Varianten machen
                      * das Hauptprodukt genauso unkaufbar wie zwei verschieden
                      * teure.
                      */
                     (SELECT COUNT(*) FROM tl_consho_product v WHERE v.pid = p.id) AS variants,
                     pv.%1$s AS price,
                     (SELECT COUNT(DISTINCT lp.%1$s) FROM tl_consho_product_visibility vv
                        JOIN tl_consho_product_list_price lp ON lp.pid = vv.pid AND lp.currency = :currency AND lp.country = :consho_home_country
                      WHERE vv.main = p.id AND vv.variant = 1 AND vv.shop = :consho_shop AND vv.language = :consho_language AND vv.currency = :currency) AS variantPrices,
                     /*
                      * Abo-2b: which offer the printed figure is (P88) — the
                      * plan of the row that gave the "from" price, the
                      * one-time sale first on a tie — and whether any of the
                      * rows can also be had as a plan (P89). The same split
                      * as VisibilityRows::cheapestPrice(): visible variants,
                      * or the row of the product itself when it has none.
                      */
                     COALESCE(
                        (SELECT lp.subscriptionRule FROM tl_consho_product_visibility vv
                           JOIN tl_consho_product_list_price lp ON lp.pid = vv.pid AND lp.currency = :currency AND lp.country = :consho_home_country
                          WHERE vv.main = p.id AND vv.variant = 1 AND vv.shop = :consho_shop AND vv.language = :consho_language AND vv.currency = :currency
                          ORDER BY lp.%1$s, lp.subscriptionRule LIMIT 1),
                        (SELECT lp.subscriptionRule FROM tl_consho_product_list_price lp
                          WHERE lp.pid = p.id AND lp.currency = :currency AND lp.country = :consho_home_country
                            AND NOT EXISTS (SELECT 1 FROM tl_consho_product lpc WHERE lpc.pid = p.id))
                     ) AS subscriptionRule,
                     COALESCE(
                        (SELECT MAX(lp.alsoSubscription) FROM tl_consho_product_visibility vv
                           JOIN tl_consho_product_list_price lp ON lp.pid = vv.pid AND lp.currency = :currency AND lp.country = :consho_home_country
                          WHERE vv.main = p.id AND vv.variant = 1 AND vv.shop = :consho_shop AND vv.language = :consho_language AND vv.currency = :currency),
                        (SELECT lp.alsoSubscription FROM tl_consho_product_list_price lp
                          WHERE lp.pid = p.id AND lp.currency = :currency AND lp.country = :consho_home_country
                            AND NOT EXISTS (SELECT 1 FROM tl_consho_product lpc WHERE lpc.pid = p.id))
                     ) AS alsoSubscription,
                     /*
                      * Block H (H-2): the strike-through price of the row
                      * that gave the figure — the same split and the same
                      * tie rule as subscriptionRule above, so it belongs to
                      * the printed amount and to no other.
                      */
                     COALESCE(
                        (SELECT lp.%5$s FROM tl_consho_product_visibility vv
                           JOIN tl_consho_product_list_price lp ON lp.pid = vv.pid AND lp.currency = :currency AND lp.country = :consho_home_country
                          WHERE vv.main = p.id AND vv.variant = 1 AND vv.shop = :consho_shop AND vv.language = :consho_language AND vv.currency = :currency
                          ORDER BY lp.%1$s, lp.subscriptionRule LIMIT 1),
                        (SELECT lp.%5$s FROM tl_consho_product_list_price lp
                          WHERE lp.pid = p.id AND lp.currency = :currency AND lp.country = :consho_home_country
                            AND NOT EXISTS (SELECT 1 FROM tl_consho_product lpc WHERE lpc.pid = p.id))
                     ) AS compare
                 FROM tl_consho_product p
                 LEFT JOIN tl_consho_product_visibility pv
                        ON pv.main = p.id AND pv.variant = 0 AND pv.shop = :consho_shop AND pv.language = :consho_language AND pv.currency = :currency
                 %4$s
                 WHERE p.id IN (:ids)',
                $column,
                $translation->coalesce('title'),
                $translation->coalesce('teaser'),
                $translation->sql(),
                $showsNet ? 'compareNet' : 'compareGross',
            ),
            ['ids' => $ids, 'currency' => $currency, 'consho_home_country' => $country, 'consho_shop' => $shopId, 'consho_language' => $language, ...$translation->params()],
            ['ids' => ArrayParameterType::INTEGER, 'consho_shop' => ParameterType::INTEGER],
        );

        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row['id']] = new ProductTile(
                id: (int) $row['id'],
                title: (string) $row['title'],
                teaser: (string) ($row['teaser'] ?? ''),
                image: $this->firstImage((string) ($row['images'] ?? '')),
                price: (string) ($row['price'] ?? ''),
                // More than one distinct variant price means no single figure
                // is true for the article — then, and only then, "from".
                isFrom: (int) ($row['variantPrices'] ?? 0) > 1,
                // A plan is chosen on the product page: the tile's button
                // buys once, and a product not sold once would refuse it.
                isBuyable: 0 === (int) ($row['variants'] ?? 0) && 0 === (int) ($row['subscriptionRule'] ?? 0),
                purchaseQuantity: self::quantityOf((string) ($row['minPurchase'] ?? '')),
                subscriptionRule: (int) ($row['subscriptionRule'] ?? 0),
                alsoSubscription: 0 === (int) ($row['subscriptionRule'] ?? 0) && (bool) ($row['alsoSubscription'] ?? false),
                compare: null === ($row['compare'] ?? null) ? '' : (string) $row['compare'],
            );
        }

        /*
         * Put back into the order the query asked for. An IN clause returns
         * rows in whatever order the engine likes, and a listing sorted by
         * price that arrives sorted by id is a bug nobody sees until the prices
         * differ.
         */
        $byId = $this->withStock($byId, $shopId, $language, $currency);

        $tiles = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $tiles[] = $byId[$id];
            }
        }

        return $tiles;
    }

    /**
     * The first image of the product — its `images` field is an ordered list of
     * binary UUIDs, and the order is a statement about the article (A-12).
     */
    /**
     * Die Mindestmenge als Formularwert: ohne überflüssige Nullen, und 1 wo
     * keine gesetzt ist.
     *
     * ⚠️ Gekürzt wird der WERT, nicht nur die Anzeige. `minPurchase` ist
     * `decimal(10,4)`, und ein roh durchgereichtes „1,0000" stand am
     * 2026-09-21 auf jeder Produktseite im Mengenfeld.
     */
    private static function quantityOf(string $minPurchase): string
    {
        $trimmed = rtrim(rtrim(trim($minPurchase), '0'), '.');

        return '' === $trimmed || '0' === $trimmed ? '1' : $trimmed;
    }

    private function firstImage(string $serialized): string
    {
        if ('' === $serialized) {
            return '';
        }

        $uuids = StringUtil::deserialize($serialized, true);
        $first = reset($uuids);

        return \is_string($first) ? StringUtil::binToUuid($first) : '';
    }

    /**
     * Block G (G7): the state of each tile. The articles of a product with
     * variants are its VISIBLE variants here (the projection's rows of this
     * shop, language and currency) — a hidden variant in stock must not make
     * the tile say "lieferbar".
     *
     * @param array<int, ProductTile> $tiles
     *
     * @return array<int, ProductTile>
     */
    private function withStock(array $tiles, int $shopId, string $language, string $currency): array
    {
        if (null === $this->availability || [] === $tiles) {
            return $tiles;
        }

        $articles = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT pid, main FROM tl_consho_product_visibility
              WHERE main IN (:ids) AND variant = 1 AND shop = :shop AND language = :language AND currency = :currency',
            ['ids' => array_keys($tiles), 'shop' => $shopId, 'language' => $language, 'currency' => $currency],
            ['ids' => ArrayParameterType::INTEGER, 'shop' => ParameterType::INTEGER],
        ) as $row) {
            $articles[(int) $row['main']][] = (int) $row['pid'];
        }

        foreach (array_keys($tiles) as $id) {
            $articles[$id] ??= [$id];
        }

        $stock = $this->availability->of(array_values(array_unique(array_merge(...array_values($articles)))));

        foreach ($tiles as $id => $tile) {
            $states = [];

            foreach ($articles[$id] as $article) {
                if (null !== ($stock[$article] ?? null)?->available) {
                    $states[] = $stock[$article]->state;
                }
            }

            if ([] !== $states) {
                $tiles[$id] = $tile->withStock(StockState::best($states)->value);
            }
        }

        return $tiles;
    }

}

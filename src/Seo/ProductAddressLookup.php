<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationJoin;

/**
 * Turns the segments of a product address back into a product.
 *
 * The inverse of ProductUrlGenerator, and it has to be exactly that: whatever
 * the generator put into the address must identify the product again, and
 * nothing else may.
 *
 * ── Why every present segment has to match ────────────────────────────────
 *
 * With the pattern {manufacturer}/{alias} the alias alone would already find
 * the product — so /shop/anything/schwedenbitter would answer 200 as well.
 * That is an unbounded supply of addresses for one product, which is the very
 * thing the single-address rule in section 4.2 exists to prevent. A segment
 * that is in the address is part of the address.
 *
 * ── Why a variant answers 404 ─────────────────────────────────────────────
 *
 * Not by a check of its own: VisibilityCriteria::mainProduct() requires
 * `pid = 0`, so a variant alias simply matches nothing. A variant has no
 * address, because its page is its main product's.
 */
final class ProductAddressLookup
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VisibilityCriteria $criteria,
    ) {
    }

    /**
     * Whether a product may be addressed at all.
     *
     * The same question as find(), minus the address. B-2 needs it: a
     * superseded address must lead to a 404 rather than a redirect once the
     * product behind it is gone or switched off — a redirect onto a 404 page is
     * worse than the 404 itself, for the visitor and for the crawler.
     */
    public function isVisible(int $productId, int $shopId, int $now, string $language = '', string $fallback = ''): bool
    {
        $visibility = $this->criteria->mainProduct($shopId, $now, 'p', $language, $fallback);

        return (bool) $this->connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM tl_consho_product p WHERE p.id = :id AND %s', $visibility->sql),
            ['id' => $productId, ...$visibility->params],
        );
    }

    /**
     * @param array<string, string> $segments values taken from the route, keyed by placeholder name
     */
    public function find(array $segments, int $shopId, int $now, string $language = '', string $fallback = ''): ?int
    {
        $productTranslation = new TranslationJoin('tl_consho_product', $language, $fallback, 'p', 'tp');
        $manufacturerTranslation = new TranslationJoin('tl_consho_manufacturer', $language, $fallback, 'm', 'tm');
        $typeTranslation = new TranslationJoin('tl_consho_product_type', $language, $fallback, 't', 'tt');

        $columns = [
            // sku is language-neutral (specification 3.2) — it never goes through coalesce().
            'alias' => $productTranslation->coalesce('alias'),
            'sku' => 'p.sku',
            'manufacturer' => \sprintf("COALESCE(%s, '')", $manufacturerTranslation->coalesce('alias')),
            'type' => \sprintf("COALESCE(%s, '')", $typeTranslation->coalesce('alias')),
        ];

        $conditions = [];
        $params = [];

        foreach ($columns as $name => $column) {
            if (!isset($segments[$name])) {
                continue;
            }

            $conditions[] = \sprintf('%s = :segment_%s', $column, $name);
            $params['segment_'.$name] = $segments[$name];
        }

        if ([] === $conditions) {
            // No identifying segment at all. Cannot happen through a route built
            // from a validated template, but an empty condition list would
            // return the first visible product of the shop — a wrong page with
            // status 200 is worse than an error.
            return null;
        }

        $visibility = $this->criteria->mainProduct($shopId, $now, 'p', $language, $fallback);

        // In the fallback language TranslationJoin::sql() returns '' for all
        // three — no join is added, and the query is exactly the one from
        // before M-3.
        $joins = implode(' ', array_filter([
            $productTranslation->sql(),
            $manufacturerTranslation->sql(),
            $typeTranslation->sql(),
        ]));

        $id = $this->connection->fetchOne(
            \sprintf(
                'SELECT p.id
                 FROM tl_consho_product p
                 LEFT JOIN tl_consho_manufacturer m ON m.id = p.manufacturer
                 LEFT JOIN tl_consho_product_type t ON t.id = p.productType
                 %s
                 WHERE %s AND %s
                 LIMIT 1',
                $joins,
                implode(' AND ', $conditions),
                $visibility->sql,
            ),
            [
                ...$params,
                ...$productTranslation->params(),
                ...$manufacturerTranslation->params(),
                ...$typeTranslation->params(),
                ...$visibility->params,
            ],
        );

        return false === $id || null === $id ? null : (int) $id;
    }
}

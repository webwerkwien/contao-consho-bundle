<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Keeps the visibility projection in step with the catalogue.
 *
 * A projection, not a cache — like tl_consho_product_list_price: it can be
 * thrown away and rebuilt completely at any time, and it is never the source
 * of a rule. Which rows belong in it is decided by VisibilityRows, and through
 * it by VisibilityCriteria, alone; this class only deletes and inserts.
 *
 * refresh(), rebuildShop() and rebuild() differ in how much they cover, not in
 * what they write: all three put the same INSERT … SELECT behind a DELETE of
 * the same scope. A projection that only ever writes keeps a product in the
 * listing that is gone. See docs/block-b-katalog-frontend.md, section 5.7.
 *
 * refresh() does not trust the product table alone to name the main products.
 * It adds the ones found in the STORED rows: a variant hung onto another main
 * product may take the last visible variant from its old one, which the
 * product table no longer connects to it — and a deleted product is named
 * only there. For the same reason it takes in the old main products of
 * variants that now hang below the ones it recalculates, so reporting only
 * the new main product neither leaves the old one standing nor runs into the
 * unique key (pid, shop, language, currency).
 *
 * One transaction per target — shop, language and currency — never one around
 * everything: a listing reads one shop in one language and one currency, so
 * that is the unit that must not be seen half written, and a lock over the
 * whole catalogue would stall every listing of every shop for as long as a
 * rebuild takes.
 */
final class VisibilityProjector
{
    /**
     * Main products per DELETE/INSERT — keeps IN lists and transactions short.
     */
    private const BLOCK = 500;

    /**
     * The rows of these main products — and the stored rows of their current
     * variants, which may still carry an old main product. Without the second
     * half, a variant whose old main product falls into another block would
     * run into the unique key (pid, shop, language, currency).
     */
    private const SCOPE = '(main IN (:consho_mains) OR pid IN (SELECT id FROM tl_consho_product WHERE pid IN (:consho_mains)))';

    public function __construct(
        private readonly Connection $connection,
        private readonly VisibilityRows $rows,
        private readonly ShopLanguages $languages,
        private readonly ProjectionState $state,
    ) {
    }

    /**
     * @param list<int> $productIds products or variants that changed, deleted ones included
     *
     * @return bool whether the stored rows of the affected main products changed
     */
    public function refresh(array $productIds, int|null $now = null): bool
    {
        if ([] === $productIds) {
            return false;
        }

        $now ??= time();
        $mainIds = $this->mainIdsOf($productIds);

        if ([] === $mainIds) {
            return false;
        }

        $targets = $this->targets();
        $changed = false;

        foreach (array_chunk($mainIds, self::BLOCK) as $block) {
            $before = $this->snapshot($block);

            foreach ($targets as $target) {
                $this->connection->transactional(function () use ($target, $block, $now): void {
                    $this->connection->executeStatement(
                        'DELETE FROM tl_consho_product_visibility WHERE shop = :consho_shop AND language = :consho_language AND currency = :consho_currency AND '.self::SCOPE,
                        ['consho_shop' => $target->shop, 'consho_language' => $target->language, 'consho_currency' => $target->currency, 'consho_mains' => $block],
                        ['consho_shop' => ParameterType::INTEGER, 'consho_mains' => ArrayParameterType::INTEGER],
                    );

                    $this->write($target, $now, $block);
                });
            }

            if ($this->snapshot($block) !== $before) {
                $changed = true;
            }
        }

        return $changed;
    }

    public function rebuildShop(int $shopId, int|null $now = null): void
    {
        $this->rebuildShopOf($this->targets(), $shopId, $now ?? time());
    }

    public function rebuild(int|null $now = null): void
    {
        $now ??= time();
        $targets = $this->targets();
        $fingerprints = $this->fingerprints($targets);

        $this->rebuildTargets($targets, $now, null);

        foreach ($fingerprints as $shopId => $fingerprint) {
            $this->state->set(ProjectionState::VISIBILITY_SHOP_PREFIX.$shopId, $fingerprint);
        }

        foreach (array_keys(array_diff_key($this->storedFingerprints(), $fingerprints)) as $shopId) {
            $this->state->delete(ProjectionState::VISIBILITY_SHOP_PREFIX.$shopId);
        }

        // Only once every target is written: the boundary cron reads the
        // marker as "everything up to here is applied".
        $this->state->set(ProjectionState::VISIBILITY_BOUNDARIES, $now);
    }

    /**
     * Brings the table in line with the shops and their root pages.
     *
     * What a shop was built with is read from its fingerprint in
     * tl_consho_projection_state, not from the languages found in the table: a
     * language without a single visible product has no rows, would be missing
     * from the table for good, and every save of a page would rebuild the whole
     * shop — with 100,000 articles, inside the back end's response.
     *
     * The fingerprint carries one entry per AUDIENCE — language and currency
     * together (C-1c) — plus the default language and the home country for the
     * whole shop. The last two change every language of the shop: the default
     * language which products are visible untranslated, the home country the
     * gross of every "from" price (C25). The currency decides the "from" price
     * and whether a product is priced at all, but only for its own audience.
     *
     * ⚠️ That format changed with C-1c, so the first save of a page or a shop
     * after the deploy rebuilds each shop once, even where nothing moved. A
     * fingerprint that stayed comparable across the change could not have seen
     * a currency switch at one of several start points.
     *
     * @return bool whether a shop was rebuilt or removed
     */
    public function syncShops(int|null $now = null): bool
    {
        $now ??= time();
        $targets = $this->targets();
        $wanted = $this->fingerprints($targets);
        $stored = $this->storedFingerprints();
        $changed = false;

        // Gone: a fingerprint without a target (shop deleted, last root page
        // removed) — and rows of a shop without a target that were written
        // around the state altogether.
        $withRows = array_map(intval(...), $this->connection->fetchFirstColumn('SELECT DISTINCT shop FROM tl_consho_product_visibility'));
        $gone = array_unique([...array_keys(array_diff_key($stored, $wanted)), ...array_diff($withRows, array_keys($wanted))]);

        foreach ($gone as $shopId) {
            $this->connection->executeStatement(
                'DELETE FROM tl_consho_product_visibility WHERE shop = :consho_shop',
                ['consho_shop' => $shopId],
                ['consho_shop' => ParameterType::INTEGER],
            );
            $this->state->delete(ProjectionState::VISIBILITY_SHOP_PREFIX.$shopId);
            $changed = true;
        }

        foreach ($wanted as $shopId => $fingerprint) {
            // The whole shop, never single language pairs: without a stored
            // default language, ShopLanguages::fallbackOf() derives it from the
            // first root language — change the set and the default language may
            // change with it, and every language of the shop is calculated
            // against that (B-10, finding 5).
            if (($stored[$shopId] ?? null) !== $fingerprint) {
                $this->rebuildShopOf($targets, $shopId, $now);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Where the table differs from what VisibilityRows says it has to hold.
     *
     * Never writes. A verify that repairs hides the very cause one is looking
     * for — as ListPriceProjector::verify() says for the list prices. Rows of a
     * shop, language and currency that has no target any more count as
     * deviations too: a listing never reads them, but a rebuild would remove
     * them, so the table is not what it should be.
     *
     * Compared in PHP, not with a join in SQL: comparing NULL amounts needs
     * `<=>` on MariaDB and `IS` on SQLite, and one statement per dialect is the
     * kind of difference that goes untested on one of them.
     *
     * @return list<array{shop: int, language: string, currency: string, pid: int, stored: array{main: int, variant: int, net: string|null, gross: string|null}|null, expected: array{main: int, variant: int, net: string|null, gross: string|null}|null}>
     */
    public function verify(int|null $now = null): array
    {
        $now ??= time();
        $deviations = [];
        $wanted = [];

        foreach ($this->targets() as $target) {
            $wanted[$target->key()] = true;
            $expected = [];

            foreach ([
                $this->rows->mains($target->shop, $target->language, $target->fallback, $target->currency, $target->country, $now),
                $this->rows->variants($target->shop, $target->language, $target->fallback, $now),
            ] as $statement) {
                foreach ($this->connection->fetchAllAssociative($statement->sql, $statement->params, $statement->types) as $row) {
                    $expected[(int) $row['pid']] = self::comparable($row);
                }
            }

            $stored = [];

            foreach ($this->connection->fetchAllAssociative(
                'SELECT pid, main, variant, net, gross FROM tl_consho_product_visibility WHERE shop = :consho_shop AND language = :consho_language AND currency = :consho_currency',
                ['consho_shop' => $target->shop, 'consho_language' => $target->language, 'consho_currency' => $target->currency],
                ['consho_shop' => ParameterType::INTEGER],
            ) as $row) {
                $stored[(int) $row['pid']] = self::comparable($row);
            }

            foreach (array_keys($expected + $stored) as $pid) {
                if (($expected[$pid] ?? null) !== ($stored[$pid] ?? null)) {
                    $deviations[] = ['shop' => $target->shop, 'language' => $target->language, 'currency' => $target->currency, 'pid' => $pid, 'stored' => $stored[$pid] ?? null, 'expected' => $expected[$pid] ?? null];
                }
            }
        }

        foreach ($this->connection->fetchAllAssociative('SELECT shop, language, currency, pid, main, variant, net, gross FROM tl_consho_product_visibility') as $row) {
            if (!isset($wanted[VisibilityTarget::keyOf((int) $row['shop'], (string) $row['language'], (string) $row['currency'])])) {
                $deviations[] = ['shop' => (int) $row['shop'], 'language' => (string) $row['language'], 'currency' => (string) $row['currency'], 'pid' => (int) $row['pid'], 'stored' => self::comparable($row), 'expected' => null];
            }
        }

        usort($deviations, static fn (array $a, array $b): int => [$a['shop'], $a['language'], $a['currency'], $a['pid']] <=> [$b['shop'], $b['language'], $b['currency'], $b['pid']]);

        return $deviations;
    }

    /**
     * One row as verify() compares it: amounts on four places — SQLite returns
     * NUMERIC as 12 or 12.0, MariaDB as "12.0000".
     *
     * @param array<string, mixed> $row
     *
     * @return array{main: int, variant: int, net: string|null, gross: string|null}
     */
    private static function comparable(array $row): array
    {
        return [
            'main' => (int) $row['main'],
            'variant' => (int) $row['variant'],
            'net' => null === $row['net'] ? null : \sprintf('%.4F', $row['net']),
            'gross' => null === $row['gross'] ? null : \sprintf('%.4F', $row['gross']),
        ];
    }

    /**
     * Every shop in every language of its root pages, each with the currency
     * that applies there. A shop without a root page has no targets — it
     * cannot deliver a listing anyway.
     *
     * Language and currency come from the SAME row since C-1c (spec 4.0): the
     * currency is a property of the audience, like the language, so a shop
     * selling over /en/ in pounds projects a pound "from" price there and a
     * euro one below /de/. Only the home country still comes from the shop —
     * it says who writes the invoice, and no currency changes that.
     *
     * One target per AUDIENCE — shop, language and currency — since C-1c task
     * 5b put the currency into the projection's unique key. Until then the
     * table was unique on (pid, shop, language) and carried no currency, so
     * two audiences of one language could only overwrite each other and
     * targets() kept the first of them. That silently forbade the DACH case:
     * example.de in euro and example.ch in francs, both in German.
     *
     * The empty language is skipped here rather than in ShopLanguages, the
     * same division of labour as of(): whoever calculates skips it, so that
     * whoever names it can still see the row.
     *
     * @return list<VisibilityTarget>
     */
    private function targets(): array
    {
        // A shop saved earlier in this request may have a new default language
        // or new root pages; the cache would still answer with the old ones.
        $this->languages->reset();

        $targets = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, country FROM tl_consho_shop ORDER BY id') as $shop) {
            $shopId = (int) $shop['id'];
            $fallback = $this->languages->fallbackOf($shopId);

            foreach ($this->languages->audiencesOf($shopId) as $audience) {
                if ('' === $audience['language']) {
                    continue;
                }

                $targets[] = new VisibilityTarget($shopId, $audience['language'], $audience['currency'], $fallback, (string) $shop['country']);
            }
        }

        return $targets;
    }

    /**
     * @param list<VisibilityTarget> $targets
     *
     * @return list<VisibilityTarget>
     */
    private function ofShop(array $targets, int $shopId): array
    {
        return array_values(array_filter($targets, static fn (VisibilityTarget $target): bool => $target->shop === $shopId));
    }

    /**
     * One shop anew, then its fingerprint — only after writing, because the
     * fingerprint says "built with these audiences and this default language".
     * A shop without targets keeps no fingerprint.
     *
     * @param list<VisibilityTarget> $targets all targets
     */
    private function rebuildShopOf(array $targets, int $shopId, int $now): void
    {
        $ofShop = $this->ofShop($targets, $shopId);

        $this->rebuildTargets($ofShop, $now, $shopId);

        $fingerprint = $this->fingerprints($ofShop)[$shopId] ?? null;

        if (null === $fingerprint) {
            $this->state->delete(ProjectionState::VISIBILITY_SHOP_PREFIX.$shopId);
        } else {
            $this->state->set(ProjectionState::VISIBILITY_SHOP_PREFIX.$shopId, $fingerprint);
        }
    }

    /**
     * @param list<VisibilityTarget> $targets
     *
     * @return array<int, int> shop id => fingerprint
     */
    private function fingerprints(array $targets): array
    {
        $audiences = [];
        $settings = [];

        foreach ($targets as $target) {
            // Language AND currency, not the language alone: since C-1c the
            // currency belongs to the single audience. As one value per shop it
            // would be whichever target came last, and switching /de/ from euro
            // to francs while /en/ stayed on pounds would leave the fingerprint
            // untouched — the listing would keep showing euro prices for good.
            $audiences[$target->shop][] = $target->language.'/'.$target->currency;
            $settings[$target->shop] = '|'.$target->fallback.'|'.$target->country;
        }

        $fingerprints = [];

        foreach ($audiences as $shopId => $ofShop) {
            sort($ofShop);

            // crc32() is never negative on 64-bit PHP and fits `value int unsigned`.
            $fingerprints[$shopId] = crc32(implode(',', $ofShop).$settings[$shopId]);
        }

        return $fingerprints;
    }

    /**
     * @return array<int, int> shop id => fingerprint
     */
    private function storedFingerprints(): array
    {
        $fingerprints = [];

        foreach ($this->state->withPrefix(ProjectionState::VISIBILITY_SHOP_PREFIX) as $name => $value) {
            $fingerprints[(int) substr($name, \strlen(ProjectionState::VISIBILITY_SHOP_PREFIX))] = $value;
        }

        return $fingerprints;
    }

    /**
     * Deletes what has no target (in one shop, or everywhere), then writes
     * every target anew — each in a transaction of its own.
     *
     * @param list<VisibilityTarget> $targets
     */
    private function rebuildTargets(array $targets, int $now, int|null $shopId): void
    {
        $wanted = [];

        foreach ($targets as $target) {
            $wanted[$target->key()] = true;
        }

        // Currency included since C-1c task 5b: a start point switched from
        // euro to francs leaves its euro rows behind, and without the currency
        // here they would look like rows of a target that still exists.
        $stored = null === $shopId
            ? $this->connection->fetchAllAssociative('SELECT DISTINCT shop, language, currency FROM tl_consho_product_visibility')
            : $this->connection->fetchAllAssociative('SELECT DISTINCT shop, language, currency FROM tl_consho_product_visibility WHERE shop = ?', [$shopId]);

        foreach ($stored as $row) {
            if (!isset($wanted[VisibilityTarget::keyOf((int) $row['shop'], (string) $row['language'], (string) $row['currency'])])) {
                $this->deleteTarget((int) $row['shop'], (string) $row['language'], (string) $row['currency']);
            }
        }

        foreach ($targets as $target) {
            $this->connection->transactional(function () use ($target, $now): void {
                $this->deleteTarget($target->shop, $target->language, $target->currency);
                $this->write($target, $now, null);
            });
        }
    }

    private function deleteTarget(int $shopId, string $language, string $currency): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM tl_consho_product_visibility WHERE shop = :consho_shop AND language = :consho_language AND currency = :consho_currency',
            ['consho_shop' => $shopId, 'consho_language' => $language, 'consho_currency' => $currency],
            ['consho_shop' => ParameterType::INTEGER],
        );
    }

    /**
     * @param list<int>|null $mainIds
     *
     * @return int rows written
     */
    private function write(VisibilityTarget $target, int $now, array|null $mainIds): int
    {
        $written = 0;

        foreach ([
            $this->rows->mains($target->shop, $target->language, $target->fallback, $target->currency, $target->country, $now, $mainIds),
            $this->rows->variants($target->shop, $target->language, $target->fallback, $now, $mainIds),
        ] as $statement) {
            /*
             * The currency is written as a constant, like the shop and the
             * language — VisibilityRows already selects the "from" price IN
             * that currency, so it is the target's currency and not a column
             * of the statement. `currency` is the parameter VisibilityRows
             * uses for it, hence no second binding of the same value.
             */
            $written += (int) $this->connection->executeStatement(
                'INSERT INTO tl_consho_product_visibility (tstamp, pid, main, variant, shop, language, currency, net, gross)
                 SELECT :consho_ts, x.pid, x.main, x.variant, :consho_shop, :consho_language, :consho_currency, x.net, x.gross
                 FROM ('.$statement->sql.') x',
                [...$statement->params, 'consho_ts' => $now, 'consho_shop' => $target->shop, 'consho_language' => $target->language, 'consho_currency' => $target->currency],
                [...$statement->types, 'consho_ts' => ParameterType::INTEGER, 'consho_shop' => ParameterType::INTEGER],
            );
        }

        return $written;
    }

    /**
     * The main products whose rows the given products touch.
     *
     * @param list<int> $productIds
     *
     * @return list<int>
     */
    private function mainIdsOf(array $productIds): array
    {
        $mainIds = [];

        foreach (array_chunk(array_values(array_unique($productIds)), self::BLOCK) as $ids) {
            $params = ['consho_ids' => $ids];
            $types = ['consho_ids' => ArrayParameterType::INTEGER];

            $mainIds = [
                ...$mainIds,
                ...$this->connection->fetchFirstColumn('SELECT DISTINCT CASE WHEN pid = 0 THEN id ELSE pid END FROM tl_consho_product WHERE id IN (:consho_ids)', $params, $types),
                // The stored rows: the old main product of a rehung variant, and
                // a deleted product — whose own row carries pid = main, whose
                // variants' rows carry it in main.
                ...$this->connection->fetchFirstColumn('SELECT DISTINCT main FROM tl_consho_product_visibility WHERE pid IN (:consho_ids) OR main IN (:consho_ids)', $params, $types),
            ];
        }

        $mainIds = array_values(array_unique(array_map(intval(...), $mainIds)));

        // Old main products of variants that hang below these by now — the
        // caller may have reported only the new main product.
        foreach (array_chunk($mainIds, self::BLOCK) as $block) {
            $mainIds = [...$mainIds, ...array_map(intval(...), $this->connection->fetchFirstColumn(
                'SELECT DISTINCT s.main FROM tl_consho_product_visibility s JOIN tl_consho_product c ON c.id = s.pid WHERE c.pid IN (:consho_mains)',
                ['consho_mains' => $block],
                ['consho_mains' => ArrayParameterType::INTEGER],
            ))];
        }

        $mainIds = array_values(array_filter(array_unique($mainIds), static fn (int $id): bool => $id > 0));
        sort($mainIds);

        return $mainIds;
    }

    /**
     * The stored rows in SCOPE as comparable keys — tstamp left out, amounts on
     * four places (SQLite returns NUMERIC as 12 or 12.0, MariaDB as "12.0000").
     *
     * @param list<int> $mainIds
     *
     * @return list<string>
     */
    private function snapshot(array $mainIds): array
    {
        $keys = array_map(
            static fn (array $row): string => \sprintf(
                '%d|%s|%s|%d|%d|%s|%s',
                $row['shop'],
                $row['language'],
                $row['currency'],
                $row['pid'],
                $row['variant'],
                null === $row['net'] ? 'NULL' : \sprintf('%.4F', $row['net']),
                null === $row['gross'] ? 'NULL' : \sprintf('%.4F', $row['gross']),
            ),
            $this->connection->fetchAllAssociative(
                'SELECT shop, language, currency, pid, variant, net, gross FROM tl_consho_product_visibility WHERE '.self::SCOPE,
                ['consho_mains' => $mainIds],
                ['consho_mains' => ArrayParameterType::INTEGER],
            ),
        );

        sort($keys);

        return $keys;
    }
}

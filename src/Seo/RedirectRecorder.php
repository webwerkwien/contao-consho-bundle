<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Controller\Page\ProductPageController;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Writes down the addresses every product had under the previous pattern.
 *
 * ── 🔴 The order is the whole thing ───────────────────────────────────────
 *
 * This runs BEFORE the new pattern is stored. Turn it round and the recorder
 * renders the NEW addresses and files redirects that point at themselves — a
 * table full of rows that all look plausible and do nothing.
 *
 * ── The same for an alias that changes ────────────────────────────────────
 *
 * recordProductAlias() files the address of ONE product before its alias is
 * replaced: same order, same table, same idempotence. It deliberately does not
 * loop over the shop — renaming one article in a catalogue of 100,000 costs a
 * few queries per startpoint, not a pass over the catalogue.
 *
 * recordSegmentAlias() and recordTranslatedSegmentAlias() do the same for a
 * manufacturer or product type: its alias is a segment of the address of every
 * product it has, wherever the pattern contains {manufacturer} or {type}. The
 * query is narrowed to those products, never the shop.
 *
 * All three share recordMove(), and all three accept the previous alias
 * explicitly. Left out, it is read from the database — the normal case, called
 * before storing. Given, the database may already hold the new one: restoring
 * a version calls back only after its UPDATE (AliasRestoreListener).
 *
 * ── A product's own parts: one comparison, after storing ──────────────────
 *
 * recordProductAddress() covers what one save or restore changes on the
 * product itself — alias, translated alias, sku, manufacturer, product type,
 * together. It takes the whole state before and reads the state after, because
 * a save writes the columns and the translations at different moments, and a
 * comparison part by part would render addresses that never existed. The mask
 * and a restore use it; recordProductAlias() stays for "Generate aliases",
 * which changes nothing but the alias.
 *
 * ── Why the paths are assembled here instead of asked from the generator ──
 *
 * ProductUrlGenerator answers with today's pattern. What is needed here is
 * yesterday's, and only this class ever needs it. That is why it appears in the
 * allow-list of AddressShapeIsOwnedInOnePlaceTest — an entry there is a
 * decision: one more place that has to change when the shape does.
 *
 * ── Why not INSERT … ON DUPLICATE KEY UPDATE ──────────────────────────────
 *
 * That is MySQL's spelling, SQLite writes ON CONFLICT, and the tests run on
 * SQLite. One query reads the paths already recorded for this site, and only
 * the rest are inserted — portable, idempotent, and it makes saving the same
 * pattern twice a no-op instead of a silent rewrite.
 */
final class RedirectRecorder
{
    /**
     * The tables whose alias is a placeholder of the product path template.
     */
    private const SEGMENTS = [
        'tl_consho_manufacturer' => 'manufacturer',
        'tl_consho_product_type' => 'type',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ProductPageRepositoryInterface $repository,
        private readonly ShopLanguages $languages,
    ) {
    }

    /**
     * @param int                  $productPageId the page whose pattern is about to change
     * @param ProductPathTemplate  $previous      the pattern as it stands right now
     *
     * @return int how many addresses were newly recorded
     */
    public function record(int $productPageId, ProductPathTemplate $previous, int $now): int
    {
        $page = $this->connection->fetchAssociative(
            'SELECT id, alias FROM tl_page WHERE id = :id',
            ['id' => $productPageId],
        );

        if (false === $page) {
            return 0;
        }

        $rootId = $this->repository->rootPageOf($productPageId);

        if (null === $rootId) {
            return 0;
        }

        $root = $this->connection->fetchAssociative(
            'SELECT id, urlPrefix, urlSuffix, language, consho_shop FROM tl_page WHERE id = :id',
            ['id' => $rootId],
        );

        if (false === $root) {
            return 0;
        }

        $shopId = (int) ($root['consho_shop'] ?? 0);

        if ($shopId < 1) {
            // A product page outside a shop has no addresses worth keeping.
            return 0;
        }

        $existing = $this->connection->fetchFirstColumn(
            'SELECT oldPath FROM tl_consho_url_redirect WHERE pid = :pid',
            ['pid' => $rootId],
        );
        $existing = array_flip(array_map(strval(...), $existing));

        $written = 0;

        /*
         * The recorded paths must be exactly the ones this root page actually
         * served — and a root page serves in one language only. Asking for
         * anything else here would record addresses that existed on a
         * DIFFERENT startpoint, not on this one (M-3, Aufgabe 4).
         */
        $language = (string) ($root['language'] ?? '');
        $productValues = $this->repository->urlValuesForShop($shopId, $language, $this->languages->fallbackOf($shopId));

        foreach ($productValues as $productId => $values) {
            $path = $this->pathFor($page, $root, $previous, $values);

            if (isset($existing[$path])) {
                continue;
            }

            $this->connection->insert('tl_consho_url_redirect', [
                'pid' => $rootId,
                'oldPath' => $path,
                'productId' => $productId,
                'createdAt' => $now,
            ]);

            $existing[$path] = true;
            ++$written;
        }

        return $written;
    }

    /**
     * Writes down the address a product had before its own alias changes.
     *
     * 🔴 Same order as record(): call it BEFORE the new alias is stored — or
     * pass $previousAlias. The old address is otherwise rendered from the
     * database; afterwards that would be the new one, old and new would be
     * equal, and nothing would be recorded — silently.
     *
     * Affected are the startpoints that show the record's own alias: those in
     * the shop's default language, or without a language. A startpoint in a
     * translation language shows the translated alias — and a product without
     * one is not visible there at all (VisibilityCriteria::translated()), even
     * though TranslationJoin::coalesce() would fall back to the record's alias.
     * No address existed there that could move.
     *
     * @param string|null $previousAlias the alias before the change; null reads the stored one
     *
     * @return int how many addresses were newly recorded
     */
    public function recordProductAlias(int $productId, string $newAlias, int $now, ?string $previousAlias = null): int
    {
        $previousAlias ??= (string) $this->connection->fetchOne(
            'SELECT alias FROM tl_consho_product WHERE id = :id',
            ['id' => $productId],
        );

        return $this->recordMove(
            'alias',
            $previousAlias,
            $newAlias,
            $now,
            static fn (string $rootLanguage, string $fallback): bool => '' === $rootLanguage || $rootLanguage === $fallback,
            fn (int $shopId, string $language, string $fallback): array => $this->repository->urlValuesForProductInShop($productId, $shopId, $language, $fallback),
        );
    }

    /**
     * Writes down the addresses of all products of a manufacturer or product
     * type before its own alias changes. Same order rule as
     * recordProductAlias().
     *
     * Affected are the startpoints whose product page pattern contains the
     * placeholder and that show the record's own alias: those in the default
     * language, and those in a translation language where the record has no
     * translated alias — TranslationJoin::coalesce() falls back to its own
     * there. Unlike a product, a manufacturer without a translation does not
     * make anything invisible.
     *
     * Not filtered by visibility, like record(): the shape of an address does
     * not depend on whether the product is switched on today.
     *
     * An empty previous alias is a move too: the pattern dropped the segment
     * (ProductPathTemplate::render()), and records from before B-1 carry none
     * until "Generate aliases" fills them.
     *
     * @param string      $table         tl_consho_manufacturer or tl_consho_product_type; anything else records nothing
     * @param string|null $previousAlias the alias before the change; null reads the stored one
     *
     * @return int how many addresses were newly recorded
     */
    public function recordSegmentAlias(string $table, int $recordId, string $newAlias, int $now, ?string $previousAlias = null): int
    {
        $placeholder = self::SEGMENTS[$table] ?? null;

        if (null === $placeholder) {
            return 0;
        }

        $previousAlias ??= $this->ownAlias($table, $recordId);

        return $this->recordMove(
            $placeholder,
            $previousAlias,
            $newAlias,
            $now,
            fn (string $rootLanguage, string $fallback): bool => '' === $rootLanguage
                || $rootLanguage === $fallback
                || '' === $this->translatedAlias($table, $recordId, $rootLanguage),
            fn (int $shopId, string $language, string $fallback): array => $this->segmentValues($table, $recordId, $shopId, $language, $fallback),
        );
    }

    /**
     * Writes down the addresses of all products of a manufacturer or product
     * type in one language before its translated alias changes.
     *
     * An empty translated alias is not an absence here, unlike a product's:
     * the address then shows the record's own alias. So adding the first
     * translation moves addresses away from the own alias, and removing it
     * moves them back — both are compared as the value the address shows.
     *
     * @param string|null $previousAlias the translated alias before the change; null reads the stored one
     *
     * @return int how many addresses were newly recorded
     */
    public function recordTranslatedSegmentAlias(string $table, int $recordId, string $language, string $newAlias, int $now, ?string $previousAlias = null): int
    {
        $placeholder = self::SEGMENTS[$table] ?? null;

        if (null === $placeholder || '' === $language) {
            return 0;
        }

        $previousAlias ??= $this->translatedAlias($table, $recordId, $language);
        $ownAlias = $this->ownAlias($table, $recordId);

        return $this->recordMove(
            $placeholder,
            '' !== trim($previousAlias) ? $previousAlias : $ownAlias,
            '' !== trim($newAlias) ? $newAlias : $ownAlias,
            $now,
            static fn (string $rootLanguage, string $fallback): bool => $rootLanguage === $language && $language !== $fallback,
            fn (int $shopId, string $rootLanguage, string $fallback): array => $this->segmentValues($table, $recordId, $shopId, $rootLanguage, $fallback),
        );
    }

    /**
     * The parts of a product its address is built from, as stored right now;
     * null for a product that does not exist.
     */
    public function productAddressState(int $productId): ?ProductAddressState
    {
        $row = $this->connection->fetchAssociative(
            'SELECT pid, alias, sku, manufacturer, productType FROM tl_consho_product WHERE id = :id',
            ['id' => $productId],
        );

        if (false === $row) {
            return null;
        }

        $translations = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT language, alias FROM tl_consho_translation WHERE ptable = :ptable AND pid = :pid',
            ['ptable' => 'tl_consho_product', 'pid' => $productId],
        ) as $translation) {
            $translations[(string) $translation['language']] = (string) $translation['alias'];
        }

        return new ProductAddressState(
            (int) $row['pid'],
            (string) $row['alias'],
            (string) $row['sku'],
            (int) $row['manufacturer'],
            (int) $row['productType'],
            $translations,
        );
    }

    /**
     * Writes down the addresses a product had before one save or restore
     * changed any of its own parts: alias, translated alias, sku, manufacturer
     * or product type — one of them or several at once.
     *
     * 🔴 The other way round from the methods above: the state before is passed
     * in, the state after is read from the database — call it AFTER storing.
     * A save writes the record in one UPDATE and its translations only
     * afterwards (TranslationFieldsListener::store()). Compared part by part
     * with the database, some part is always already new, and the "old"
     * address would be one that never existed. The listeners take the state
     * before anything is written (productAddressState()) and hand it in once
     * everything is: ProductAddressListener for the mask, AliasRestoreListener
     * for a restored version.
     *
     * Per startpoint only the parts that moved are replaced by their previous
     * value. When none of them is a placeholder of its pattern, the startpoint
     * is not queried.
     *
     * - A variant has no address, before or after: {sku} is the main product's.
     * - A manufacturer or type without alias, or none at all, is an empty
     *   segment the pattern drops. Like an empty previous alias in
     *   recordSegmentAlias(), that is a move — in both directions here, because
     *   the product keeps an address either way.
     * - On a startpoint in a translation language the product needs a
     *   translated alias to have an address (VisibilityCriteria::translated());
     *   without one before or after, nothing moved there.
     *
     * @return int how many addresses were newly recorded
     */
    public function recordProductAddress(int $productId, ProductAddressState $previous, int $now): int
    {
        $current = $this->productAddressState($productId);

        if (null === $current || 0 !== $previous->pid) {
            // Deleted, or a variant until now: nothing had an address that could move.
            return 0;
        }

        $roots = $this->connection->fetchAllAssociative(
            "SELECT id, urlPrefix, urlSuffix, language, consho_shop FROM tl_page WHERE type = 'root' AND consho_shop > 0 ORDER BY id",
        );

        $written = 0;

        foreach ($roots as $root) {
            $rootId = (int) $root['id'];
            $shopId = (int) $root['consho_shop'];
            $language = (string) ($root['language'] ?? '');
            $fallback = $this->languages->fallbackOf($shopId);
            $translated = '' !== $language && $language !== $fallback;

            $moved = $this->movedPlaceholders($previous, $current, $translated ? $language : null);

            if ([] === $moved) {
                continue;
            }

            $pageId = $this->repository->findForRootPage($rootId);

            if (null === $pageId) {
                continue;
            }

            $page = $this->connection->fetchAssociative(
                'SELECT id, alias, conshoPathTemplate FROM tl_page WHERE id = :id',
                ['id' => $pageId],
            );

            if (false === $page) {
                continue;
            }

            $template = $this->currentTemplateOf($page);

            if ([] === array_intersect($moved, $template->placeholders())) {
                continue;
            }

            // Empty for a product that is a variant now, or one this shop does not carry.
            $values = $this->repository->urlValuesForProductInShop($productId, $shopId, $language, $fallback)[$productId] ?? null;

            if (null === $values) {
                continue;
            }

            $before = $values;
            $after = $values;

            if ($translated) {
                $before[ProductPathTemplate::ALIAS] = $previous->translations[$language] ?? '';
                $after[ProductPathTemplate::ALIAS] = $current->translations[$language] ?? '';
            } elseif (\in_array(ProductPathTemplate::ALIAS, $moved, true)) {
                $before[ProductPathTemplate::ALIAS] = $previous->alias;
            }

            if (\in_array(ProductPathTemplate::SKU, $moved, true)) {
                $before[ProductPathTemplate::SKU] = $previous->sku;
            }

            if (\in_array(ProductPathTemplate::MANUFACTURER, $moved, true)) {
                $before[ProductPathTemplate::MANUFACTURER] = $this->segmentIn('tl_consho_manufacturer', $previous->manufacturer, $language, $fallback);
            }

            if (\in_array(ProductPathTemplate::TYPE, $moved, true)) {
                $before[ProductPathTemplate::TYPE] = $this->segmentIn('tl_consho_product_type', $previous->productType, $language, $fallback);
            }

            if (!$this->hasAddress($template, $before) || !$this->hasAddress($template, $after)) {
                continue;
            }

            $oldPath = $this->pathFor($page, $root, $template, $before);
            $newPath = $this->pathFor($page, $root, $template, $after);

            if ($oldPath !== $newPath && $this->fileMove($rootId, $oldPath, $newPath, $productId, $now)) {
                ++$written;
            }
        }

        return $written;
    }

    /**
     * The placeholders whose value differs between the two states on a
     * startpoint: the translated alias in a translation language, the
     * record's own otherwise.
     *
     * @param string|null $language the startpoint's translation language; null for the default language
     *
     * @return list<string>
     */
    private function movedPlaceholders(ProductAddressState $previous, ProductAddressState $current, ?string $language): array
    {
        $moved = [];

        $aliasBefore = null === $language ? $previous->alias : ($previous->translations[$language] ?? '');
        $aliasAfter = null === $language ? $current->alias : ($current->translations[$language] ?? '');

        if ($aliasBefore !== $aliasAfter) {
            $moved[] = ProductPathTemplate::ALIAS;
        }

        if ($previous->sku !== $current->sku) {
            $moved[] = ProductPathTemplate::SKU;
        }

        if ($previous->manufacturer !== $current->manufacturer) {
            $moved[] = ProductPathTemplate::MANUFACTURER;
        }

        if ($previous->productType !== $current->productType) {
            $moved[] = ProductPathTemplate::TYPE;
        }

        return $moved;
    }

    /**
     * Without an alias the product has no address; without what the pattern
     * renders only the bare product page is left — a pattern of just {sku} and
     * an empty sku. A redirect from there would catch the page itself.
     *
     * @param array<string, string> $values
     */
    private function hasAddress(ProductPathTemplate $template, array $values): bool
    {
        return '' !== trim($values[ProductPathTemplate::ALIAS] ?? '') && '' !== $template->render($values);
    }

    /**
     * The segment a manufacturer or product type fills in one language: its
     * translated alias, else its own, '' for none — the expression of
     * DbalProductPageRepository::urlValuesInShop() in PHP. Only for the record
     * a product had before, which that query can no longer join.
     *
     * $table is a literal of recordProductAddress(), never input.
     */
    private function segmentIn(string $table, int $recordId, string $language, string $fallback): string
    {
        if ($recordId < 1) {
            return '';
        }

        $own = $this->connection->fetchOne(\sprintf('SELECT alias FROM %s WHERE id = :id', $table), ['id' => $recordId]);

        if (false === $own) {
            return '';
        }

        if ('' === $language || $language === $fallback) {
            return (string) $own;
        }

        $translated = $this->translatedAlias($table, $recordId, $language);

        return '' !== $translated ? $translated : (string) $own;
    }

    /**
     * The part all alias changes share: one value of the address moves from
     * $previous to $new, for the products $valuesFor() returns.
     *
     * @param string                                                         $placeholder   the template placeholder the value fills
     * @param \Closure(string, string): bool                                 $showsTheValue whether a startpoint of this language, in a shop with this default language, shows the value that changes
     * @param \Closure(int, string, string): array<int, array<string, string>> $valuesFor     url values per product for a shop, language and fallback
     */
    private function recordMove(string $placeholder, string $previous, string $new, int $now, \Closure $showsTheValue, \Closure $valuesFor): int
    {
        $new = trim($new);

        if ('' === $new) {
            // Not a move: without an alias the product has no address there
            // any more, and a redirect would lead onto a 404.
            return 0;
        }

        if ($previous === $new) {
            return 0;
        }

        // A startpoint without a shop has no product addresses worth keeping —
        // the same boundary as record().
        $roots = $this->connection->fetchAllAssociative(
            "SELECT id, urlPrefix, urlSuffix, language, consho_shop FROM tl_page WHERE type = 'root' AND consho_shop > 0 ORDER BY id",
        );

        $written = 0;

        foreach ($roots as $root) {
            $rootId = (int) $root['id'];
            $shopId = (int) $root['consho_shop'];
            $language = (string) ($root['language'] ?? '');
            $fallback = $this->languages->fallbackOf($shopId);

            if (!$showsTheValue($language, $fallback)) {
                continue;
            }

            // The page ProductUrlGenerator builds addresses on, so the recorded
            // path is one that was actually handed out.
            $pageId = $this->repository->findForRootPage($rootId);

            if (null === $pageId) {
                continue;
            }

            $page = $this->connection->fetchAssociative(
                'SELECT id, alias, conshoPathTemplate FROM tl_page WHERE id = :id',
                ['id' => $pageId],
            );

            if (false === $page) {
                continue;
            }

            $template = $this->currentTemplateOf($page);

            if (!\in_array($placeholder, $template->placeholders(), true)) {
                // Nothing of this startpoint's addresses moves — and a
                // manufacturer with 5,000 products is not read for nothing.
                continue;
            }

            /*
             * Empty for a variant or a product this startpoint's shop does not
             * carry — the rules urlValuesForShop() applies for record().
             */
            $rows = $valuesFor($shopId, $language, $fallback);

            foreach ($rows as $id => $values) {
                $before = [...$values, $placeholder => $previous];

                if ('' === ($before['alias'] ?? '')) {
                    // Never had an address. Recording it would file a redirect
                    // from the bare product page.
                    continue;
                }

                $oldPath = $this->pathFor($page, $root, $template, $before);
                $newPath = $this->pathFor($page, $root, $template, [...$values, $placeholder => $new]);

                if ($oldPath !== $newPath && $this->fileMove($rootId, $oldPath, $newPath, $id, $now)) {
                    ++$written;
                }
            }
        }

        return $written;
    }

    /**
     * Files one move on one startpoint.
     *
     * @return bool whether a row was inserted; false when the old path was known already
     */
    private function fileMove(int $rootId, string $oldPath, string $newPath, int $productId, int $now): bool
    {
        /*
         * 🔴 The new address is today's address from now on. A row still
         * filed under it goes: the product returning to an earlier
         * alias, or taking over one another product gave up.
         *
         * ProductRedirectListener only asks on a 404, so such a row would
         * not hide the page. But it is stale, and in the takeover case it
         * is wrong: when this product moves on, the idempotence check
         * below would find that row and skip — and the old address would
         * lead to the other product.
         */
        $this->connection->delete('tl_consho_url_redirect', ['pid' => $rootId, 'oldPath' => $newPath]);

        $known = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_consho_url_redirect WHERE pid = :pid AND oldPath = :path',
            ['pid' => $rootId, 'path' => $oldPath],
        );

        if ($known > 0) {
            return false;
        }

        $this->connection->insert('tl_consho_url_redirect', [
            'pid' => $rootId,
            'oldPath' => $oldPath,
            'productId' => $productId,
            'createdAt' => $now,
        ]);

        return true;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function segmentValues(string $table, int $recordId, int $shopId, string $language, string $fallback): array
    {
        return 'tl_consho_manufacturer' === $table
            ? $this->repository->urlValuesForManufacturerInShop($recordId, $shopId, $language, $fallback)
            : $this->repository->urlValuesForProductTypeInShop($recordId, $shopId, $language, $fallback);
    }

    /**
     * The stored alias of a manufacturer or product type. $table is a key of
     * SEGMENTS, never input.
     */
    private function ownAlias(string $table, int $recordId): string
    {
        return (string) $this->connection->fetchOne(
            \sprintf('SELECT alias FROM %s WHERE id = :id', $table),
            ['id' => $recordId],
        );
    }

    /**
     * '' for no translation row, and for a row without alias — the two read
     * the same, TranslationJoin::coalesce() treats both as missing.
     */
    private function translatedAlias(string $ptable, int $pid, string $language): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT alias FROM tl_consho_translation WHERE ptable = :ptable AND pid = :pid AND language = :language',
            ['ptable' => $ptable, 'pid' => $pid, 'language' => $language],
        );
    }

    /**
     * The page's pattern as its route uses it today, with the fallback of
     * ProductPageController::configurePageRoute(): empty means the default, and
     * a pattern written past the DCA falls back to it instead of failing.
     *
     * @param array<string, mixed> $page
     */
    private function currentTemplateOf(array $page): ProductPathTemplate
    {
        $stored = (string) ($page['conshoPathTemplate'] ?? '');

        try {
            return ProductPathTemplate::fromString('' !== $stored ? $stored : ProductPageController::DEFAULT_TEMPLATE);
        } catch (InvalidPathTemplate) {
            return ProductPathTemplate::fromString(ProductPageController::DEFAULT_TEMPLATE);
        }
    }

    /**
     * The path exactly as a request carries it.
     *
     * Prefix and suffix belong to the root page and are part of what the
     * browser sends, so they are part of what has to be matched later. Leaving
     * the suffix off would file "/produkt/schwedenbitter" for a site that only
     * ever answered "/produkt/schwedenbitter.html".
     *
     * @param array<string, mixed>  $page
     * @param array<string, mixed>  $root
     * @param array<string, string> $values
     */
    private function pathFor(array $page, array $root, ProductPathTemplate $template, array $values): string
    {
        $prefix = trim((string) ($root['urlPrefix'] ?? ''), '/');
        $alias = (string) ($page['alias'] ?? '') ?: (string) $page['id'];

        $segments = array_filter([$prefix, $alias, $template->render($values)], static fn (string $s): bool => '' !== $s);

        return '/'.implode('/', $segments).(string) ($root['urlSuffix'] ?? '');
    }
}

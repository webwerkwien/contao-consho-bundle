<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * Whether a product may be shown — in one place, for all four of them.
 *
 * The rules come from docs/block-a-fundament.md, section 3.7. They apply to
 * the LISTING, the PRODUCT PAGE, the SITEMAP and the JSON-LD, and that is the
 * whole reason this class exists: four copies of the same condition are three
 * too many, and the forgotten one is always the sitemap. Isotope has exactly
 * that bug — variants of disabled products end up in its sitemap.
 *
 * ── The three rules ───────────────────────────────────────────────────────
 *
 * 1. Shop assignment: a variant INHERITS its main product's shops, or narrows
 *    them. Never beyond. A variant that could appear in a shop whose catalogue
 *    does not carry its main product is a contradiction you notice in the
 *    basket.
 * 2. A variant without an active main product is invisible — it has no
 *    existence of its own. Description, images, tax class and its page all come
 *    from the main product.
 * 3. A main product whose variants are all inactive is invisible. A product
 *    WITHOUT variants is untouched by this: it is bought directly, and its own
 *    `published` decides alone.
 *
 * > 🔴 Rule 3 is the one that gets built the wrong way round. "Has no active
 * > variant" and "has no variants" are one condition apart, and confusing them
 * > hides every variant-less product in the shop.
 *
 * ── What this class must NOT learn ────────────────────────────────────────
 *
 * "Out of stock" is not a case here, and must not become one. Today "no active
 * variant" means exactly one thing: somebody switched it off deliberately.
 *
 *   | taken out of the catalogue | editorial decision | gone, page answers 404 |
 *   | temporarily unavailable    | stock is zero      | page STAYS, no purchase |
 *
 * Mapping "sold out" onto `published = 0` would drop a product that is back
 * next week out of the index, URL and all — the very damage the facet landing
 * pages from block A section 2 are meant to prevent.
 */
final class VisibilityCriteria
{
    private const SHOP = 'consho_vis_shop';
    private const NOW = 'consho_vis_now';
    private const LANGUAGE = 'vc_language';

    /**
     * Condition on a main product row.
     */
    public function mainProduct(int $shopId, int $now, string $alias = 'p', string $language = '', string $fallback = ''): SqlCondition
    {
        $sql = \sprintf(
            '%1$s.pid = 0
             AND %2$s
             AND %3$s
             AND %6$s
             AND (
                 NOT EXISTS (SELECT 1 FROM tl_consho_product child WHERE child.pid = %1$s.id)
                 OR EXISTS (
                     SELECT 1 FROM tl_consho_product child
                     WHERE child.pid = %1$s.id
                       AND %4$s
                       AND %5$s
                       AND %7$s
                 )
             )',
            $alias,
            $this->published($alias),
            $this->assignedToShop($alias),
            $this->published('child'),
            $this->variantReachesShop('child'),
            $this->translated($alias, $language, $fallback),
            // Rule 3 in the page's language: a variant hidden there by its
            // untranslated safety texts cannot be bought there either.
            $this->ownSafetyTextsTranslated('child', $language, $fallback),
        );

        $params = [self::SHOP => $shopId, self::NOW => $this->nowAsString($now)];

        if ('' !== $language && $language !== $fallback) {
            $params[self::LANGUAGE] = $language;
        }

        return new SqlCondition($sql, $params);
    }

    /**
     * Condition on a variant row.
     *
     * A variant is visible when it is published within its window, its main
     * product is visible in this shop, its own shop selection — if it has
     * one — includes this shop, and its OWN safety texts, if it has any, are
     * translated into the page's language.
     *
     * There is deliberately no `translated()` condition here: a variant
     * inherits its main product's texts and has no translation row of its
     * own — except for its own safety texts. If rule 4 applied here as a
     * whole, every variant-based product would disappear in every foreign
     * language. ownSafetyTextsTranslated() is the part of it that does apply.
     */
    public function variant(int $shopId, int $now, string $alias = 'v', string $language = '', string $fallback = ''): SqlCondition
    {
        $sql = \sprintf(
            '%1$s.pid > 0
             AND %2$s
             AND %3$s
             AND %6$s
             AND EXISTS (
                 SELECT 1 FROM tl_consho_product parent
                 WHERE parent.id = %1$s.pid
                   AND %4$s
                   AND %5$s
             )',
            $alias,
            $this->published($alias),
            $this->variantReachesShop($alias),
            $this->published('parent'),
            $this->assignedToShop('parent'),
            $this->ownSafetyTextsTranslated($alias, $language, $fallback),
        );

        $params = [self::SHOP => $shopId, self::NOW => $this->nowAsString($now)];

        if ('' !== $language && $language !== $fallback) {
            $params[self::LANGUAGE] = $language;
        }

        return new SqlCondition($sql, $params);
    }

    /**
     * Rule 0, unwritten until now: published within the time window.
     *
     * start/stop are `varchar(10) NOT NULL DEFAULT ''` (see
     * contao/dca/tl_consho_product.php, `rgxp => 'datim'`) — exactly the same
     * shape Contao itself uses for tl_content/tl_article/tl_page start and
     * stop, and it stores a Unix timestamp as a decimal-digit STRING, not a
     * formatted date (see ContentModel::findPublishedByPidAndTable(): `($t.
     * start='' OR $t.start<=$time) AND ($t.stop='' OR $t.stop>$time)`).
     *
     * "" means "not set" and must be checked for explicitly — comparing it
     * with `<=`/`>` against a timestamp answers a different, wrong question.
     * The previous `= 0` version only ever worked on the test server because
     * MySQL casts '' to 0 for an implicit numeric comparison; on 2026-09-13
     * the real DB confirmed both columns hold '' for every product, so every
     * row was passing this check purely by that implicit cast, not by design.
     *
     * ⚠️ SQLite has no such implicit cast. A VARCHAR/TEXT column carries TEXT
     * affinity, and SQLite applies that affinity to the OTHER operand before
     * comparing — even a parameter bound as a native integer gets converted
     * to its decimal string form first, so the comparison is ALWAYS
     * lexicographic here, never numeric, regardless of how :now is bound.
     * Binding :now as a PHP string makes that explicit instead of accidental,
     * and keeps MySQL and SQLite doing the same string comparison rather than
     * MySQL silently doing a numeric one — the same kind of implicit-cast
     * reliance that hid the '' bug in the first place. Lexicographic order
     * only matches numeric order for equal-width digit strings, which is
     * exactly what a Unix timestamp is from 2001-09-09 to 2286-11-20 (always
     * 10 digits) — the same range the column's length(10) already assumes.
     */
    private function published(string $alias): string
    {
        return \sprintf(
            "%1\$s.published = 1
             AND (%1\$s.start = '' OR %1\$s.start <= :%2\$s)
             AND (%1\$s.stop = '' OR %1\$s.stop > :%2\$s)",
            $alias,
            self::NOW,
        );
    }

    /**
     * See published() above: the column stores a Unix timestamp as a
     * fixed-width decimal string, and the comparison must be a plain string
     * comparison on both MySQL and SQLite — not an integer bind that happens
     * to work by implicit cast on one engine only.
     */
    private function nowAsString(int $now): string
    {
        return (string) $now;
    }

    private function assignedToShop(string $alias): string
    {
        return \sprintf(
            'EXISTS (SELECT 1 FROM tl_consho_product_shop ps WHERE ps.pid = %s.id AND ps.shop = :%s)',
            $alias,
            self::SHOP,
        );
    }

    /**
     * The narrowing half of rule 1.
     *
     * An empty selection means "like the main product"; a non-empty one means
     * "only there". The intersection with the main product's shops is not done
     * here — the callers above check the parent separately, which keeps this
     * fragment readable and makes the inheritance visible as its own clause.
     */
    private function variantReachesShop(string $alias): string
    {
        return \sprintf(
            '(NOT EXISTS (SELECT 1 FROM tl_consho_product_shop vs WHERE vs.pid = %1$s.id)
              OR EXISTS (SELECT 1 FROM tl_consho_product_shop vs WHERE vs.pid = %1$s.id AND vs.shop = :%2$s))',
            $alias,
            self::SHOP,
        );
    }

    /**
     * Rule 4: in a language other than the default language, a product
     * exists only with a translation row — and without translated safety
     * notices, not at all.
     *
     * Why this sits here and not in the output: A-11 requires the safety
     * notices IN THE MARKET'S LANGUAGE. A display check can be forgotten at
     * any one of four places; this condition is shared by the listing, the
     * detail page, the sitemap and the JSON-LD.
     *
     * A product without safety notices in the default language — the
     * majority — is untouched by this: then the respective sub-condition is
     * true.
     *
     * ⚠️ COALESCE, not a plain comparison with ''. The columns are nullable
     * longtext without a default, so a field nobody ever saved is NULL — on
     * the test server on 2026-09-13 that was every single product. `NULL = ''` is
     * never true in SQL, and without COALESCE every such product vanished in
     * every translation language, complete translation or not.
     */
    private function translated(string $alias, string $language, string $fallback): string
    {
        if ($language === $fallback || '' === $language) {
            return '1 = 1';
        }

        return \sprintf(
            'EXISTS (
                SELECT 1 FROM tl_consho_translation ct
                WHERE ct.ptable = \'tl_consho_product\' AND ct.pid = %1$s.id AND ct.language = :%2$s
                  AND ct.alias IS NOT NULL AND ct.alias != \'\'
                  AND (COALESCE(%1$s.safetyWarnings, \'\') = \'\' OR COALESCE(ct.safetyWarnings, \'\') != \'\')
                  AND (COALESCE(%1$s.safetyInformation, \'\') = \'\' OR COALESCE(ct.safetyInformation, \'\') != \'\')
            )',
            $alias,
            self::LANGUAGE,
        );
    }

    /**
     * Rule 4 for a variant: its OWN safety texts, if it has any, exist in the
     * page's language — otherwise the variant does not exist there.
     *
     * Michael, 2026-09-13 (plan B-5, question 3). A variant usually inherits
     * its main product's safety texts and is untouched by this. But one that
     * carries its own German warning leaves a French page only three options:
     * show the German one (a breach), show the main product's (wrong) or show
     * none (a breach). The M-4 mask gives variants translation sections, so
     * the rule can be met in the back end.
     *
     * No alias is required, unlike translated(): a variant has no address of
     * its own. Same COALESCE reasoning as there — a never saved field is NULL.
     */
    private function ownSafetyTextsTranslated(string $alias, string $language, string $fallback): string
    {
        if ($language === $fallback || '' === $language) {
            return '1 = 1';
        }

        return \sprintf(
            '(
                (COALESCE(%1$s.safetyWarnings, \'\') = \'\' AND COALESCE(%1$s.safetyInformation, \'\') = \'\')
                OR EXISTS (
                    SELECT 1 FROM tl_consho_translation vt
                    WHERE vt.ptable = \'tl_consho_product\' AND vt.pid = %1$s.id AND vt.language = :%2$s
                      AND (COALESCE(%1$s.safetyWarnings, \'\') = \'\' OR COALESCE(vt.safetyWarnings, \'\') != \'\')
                      AND (COALESCE(%1$s.safetyInformation, \'\') = \'\' OR COALESCE(vt.safetyInformation, \'\') != \'\')
                )
            )',
            $alias,
            self::LANGUAGE,
        );
    }
}

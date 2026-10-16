<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Translation;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Counts, per shop and translation language, the products a translation gap
 * hides — for the back end start page.
 *
 * Two findings, and the difference is not cosmetic. A product without a
 * translation is simply not there in that language, which is easy to
 * understand. A product WITH a translation but without translated safety
 * notices is published and still invisible — without a report the operator
 * looks for a bug in the shop.
 *
 * ── The rule comes from VisibilityCriteria, not from here ────────────────
 *
 * A report saying "missing" where the front end shows the product, or staying
 * silent where it hides one, is worse than none. So the query does not restate
 * the rule: a gap is a main product that the criteria make visible in the
 * shop's default language and invisible in the translation language. Drafts,
 * expired products, products of other shops and main products whose variants
 * are all inactive drop out on their own — the default language hides them
 * already, so no translation is what is keeping them away.
 *
 * Variants are not counted. They inherit their main product's texts and have
 * no translation row; the gap is the main product's, and counting it once per
 * size would multiply what there is to translate.
 *
 * Only the SPLIT into the two findings is decided here: an invisible product
 * that has a translation row with an address can only be missing its safety
 * notices. Should rule 4 ever get a further condition, it would land in the
 * safety finding — DbalTranslationGapIssueSourceTest compares both counts
 * against the criteria to catch that.
 *
 * ⚠️ **Runs on every call of the start page**, like the other sources. One
 * aggregate query per shop and translation language, no row lists. A
 * monolingual shop causes no query on products at all.
 */
final class DbalTranslationGapIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ShopLanguages $languages,
        private readonly VisibilityCriteria $criteria,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function issues(): array
    {
        $now = null !== $this->clock ? ($this->clock)() : time();
        $issues = [];

        foreach ($this->connection->fetchAllKeyValue('SELECT id, title FROM tl_consho_shop ORDER BY id') as $shopId => $title) {
            $shopId = (int) $shopId;

            // The touchstone of the multilingual specification: for a shop
            // without a second language nothing changes, not even here.
            if ($this->languages->isMonolingual($shopId)) {
                continue;
            }

            $fallback = $this->languages->fallbackOf($shopId);

            foreach ($this->languages->translatedOf($shopId) as $language) {
                [$hidden, $withAddress] = $this->count($shopId, $now, $language, $fallback);

                /*
                 * The language CODE, not its name: the fingerprint of a note
                 * includes the context, and a name in the back end user's
                 * language would make an acknowledgement hold for one user
                 * and not for the next. The mask labels its sections with the
                 * code as well.
                 */
                $context = ['shop' => (string) $title, 'language' => $language];

                if ($withAddress > 0) {
                    $issues[] = new ConfigurationIssue(
                        ConfigurationFinding::TranslationSafetyMissing,
                        [...$context, 'count' => (string) $withAddress],
                    );
                }

                if ($hidden - $withAddress > 0) {
                    $issues[] = new ConfigurationIssue(
                        ConfigurationFinding::TranslationMissing,
                        [...$context, 'count' => (string) ($hidden - $withAddress)],
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @return array{int, int} hidden by the language, and of those the ones with an address there
     */
    private function count(int $shopId, int $now, string $language, string $fallback): array
    {
        $inFallback = $this->criteria->mainProduct($shopId, $now, 'p', $fallback, $fallback);
        $inLanguage = $this->criteria->mainProduct($shopId, $now, 'p', $language, $fallback);

        /*
         * NOT (…) is safe here despite SQL's three-valued logic: the first
         * condition is true for every row that gets this far, and the only
         * part the second adds is an EXISTS, which is never NULL.
         */
        $row = $this->connection->fetchAssociative(
            \sprintf(
                "SELECT
                    COUNT(*) AS hidden,
                    COALESCE(SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM tl_consho_translation gap
                        WHERE gap.ptable = 'tl_consho_product' AND gap.pid = p.id AND gap.language = :gap_language
                          AND gap.alias IS NOT NULL AND gap.alias != ''
                    ) THEN 1 ELSE 0 END), 0) AS withAddress
                 FROM tl_consho_product p
                 WHERE %s
                   AND NOT (%s)",
                $inFallback->sql,
                $inLanguage->sql,
            ),
            [...$inFallback->params, ...$inLanguage->params, 'gap_language' => $language],
        );

        return [(int) ($row['hidden'] ?? 0), (int) ($row['withAddress'] ?? 0)];
    }
}

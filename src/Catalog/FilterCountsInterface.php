<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * How many products each filter option would leave — the extension point for
 * large catalogues.
 *
 * ProductQuery is the core's implementation: it counts on the visibility
 * projection, exact and in one grouped query per call. Measured in B-10 it
 * carries the target group, but stays proportional to the matches
 * (docs/block-b-katalog-frontend.md, 5.6 "Nach B-10"). Faster counting for
 * large catalogues — precomputed or capped numbers — belongs in a separate,
 * paid module (Consho principle 6, decided 2026-09-14). Such a module replaces
 * the counting by pointing this interface's service alias at its own service;
 * FilterPanelBuilder asks nothing but this interface.
 *
 * ── The contract ──────────────────────────────────────────────────────────
 *
 * - The numbers are counted under the same condition as the listing's
 *   ProductQuery::find() — shop, language, currency, visible and priced, the
 *   groups. A number that does not match what clicking the option shows is a
 *   defect. The currency belongs to the condition since C-1c task 5b: one
 *   shop can be reached in one language in two currencies, and an article
 *   priced in only one of them is shown in only one of them.
 * - Self-exclusion: with $without set, that group is left out of the
 *   condition, so choosing "rot" does not put a zero next to "blau".
 * - An option without products may be missing from the result; the caller
 *   treats it as 0.
 * - An implementation may round or cap large numbers only if it says so in
 *   its own documentation; FilterPanelBuilder uses 0 to decide that an option
 *   leads nowhere, so a real 0 must stay 0.
 */
interface FilterCountsInterface
{
    /**
     * @param array<int, list<int>> $groups        property group id => selected option ids; OR within a group, AND across groups
     * @param int|null              $without       the group to leave out of the condition
     * @param bool                  $availableOnly "Nur verfügbare" (block Verfügbarkeit, V5): count only what is not sold out —
     *                                             through the article that meets the groups, as ProductQuery::find() does (V6)
     *
     * @return array<int, int> option id => number of products
     */
    public function countsFor(int $shopId, array $groups, int|null $without, string $language, string $currency, bool $availableOnly = false): array;

    /**
     * How many products the groups leave — for "Nur verfügbare" itself, which
     * is no option of a group (block Verfügbarkeit, V5).
     *
     * @param array<int, list<int>> $groups
     * @param list<int>             $excluded
     */
    public function count(int $shopId, array $groups, string $language, string $currency, array $excluded = [], bool $availableOnly = false): int;
}

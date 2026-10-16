<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\PageModel;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Seo\FilterUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Seo\ListingAddress;

/**
 * What the filter element shows: which groups and options, their numbers, and
 * where each of them leads (B-4 decision 10).
 *
 * ── The numbers: self-exclusion ───────────────────────────────────────────
 *
 * A group is counted under every filter except its own
 * (docs/block-b-katalog-frontend.md, 5.2). With "rot" chosen, "blau" keeps its
 * eight — otherwise the control a visitor would use to change their mind
 * looks dead. ProductQuery::countsFor() drops the whole group, the category's
 * part of it included; for the options of that part the number is the same,
 * because carrying one of them already satisfies the preset.
 *
 * Cost: one query without a visitor choice. With one, the category once more,
 * one for the groups nobody touched — only if an offered group is untouched —
 * and one per chosen group the element offers.
 *
 * ── What the category lacks does not appear ───────────────────────────────
 *
 * An option without a product in the category itself — the preset alone, no
 * visitor choice — is left out; otherwise "Kräuterbitter" would list every
 * colour of the shop. That set does not change with clicks, so nothing moves:
 * an option a choice brings down to zero stays, without a link.
 *
 * Except an option the address already carries. A bookmark with
 * colour:navy,red still shows the red products; hiding navy would turn
 * "deselect red" into colour:navy, which is a 404.
 *
 * A group with at most one option left is dropped: a control that narrows
 * nothing only makes one more cq address. Unless that option is chosen — then
 * the group is the only way to deselect it.
 *
 * ── Without numbers: counted anyway, only not shown ───────────────────────
 *
 * conshoShowCounts off hides the numbers; it does not skip counting. Without
 * the numbers the panel cannot tell an empty option from a full one — until
 * 2026-09-14 it then linked every option, and a click on an empty one led to
 * a 404 (found 2026-09-13). Michael decided on 2026-09-14 to count always:
 * the rules above need the numbers, and a catalogue too large to count belongs
 * to a module behind FilterCountsInterface, not to this switch.
 *
 * ── A page without a route ────────────────────────────────────────────────
 *
 * ContentUrlGenerator throws for an unroutable page. It happens: a filter in a
 * layout section with a fixed listing renders on the error page too. The panel
 * stays empty then — without an address there is nothing to link to, and an
 * error page must not fail a second time.
 *
 * The page number never travels along: FilterUrlGenerator gets no request, so
 * ListingElement::pageParameter() has no business here. And the sort order in
 * the address is taken as it comes — ListingAddressResolver has already
 * dropped one the listing does not know.
 *
 * ── Where the numbers come from ───────────────────────────────────────────
 *
 * From FilterCountsInterface, never from ProductQuery directly. The core's
 * implementation is ProductQuery; a module for large catalogues swaps it by
 * re-pointing the interface's service alias (decided 2026-09-14). Everything
 * above — self-exclusion, what the category lacks, the one-option rule —
 * stays here and applies to any implementation.
 */
final class FilterPanelBuilder
{
    public function __construct(
        private readonly FilterResolver $filters,
        private readonly FilterOptionRepository $options,
        private readonly FilterCountsInterface $counts,
        private readonly FilterUrlGenerator $urls,
    ) {
    }

    /**
     * @param list<int> $offeredGroupIds the filter element's groups; empty = every filterable group
     *
     * @return list<FilterGroupView>
     */
    public function build(
        PageModel $page,
        int $shopId,
        ListingElement $listing,
        ListingAddress $address,
        array $offeredGroupIds,
        string $language,
        string $currency,
        string $fallback,
    ): array {
        // The listing answers 404 for it. Its state is empty, and the controls
        // of the whole category would be all there is on the error page.
        if (!$address->valid) {
            return [];
        }

        $state = $address->state;
        $preset = $this->filters->group($listing->preset);
        $chosen = $this->filters->resolve($state);
        $narrowed = $this->filters->narrow($preset, $chosen);

        // Nothing can match, and the listing answers 404 for that as well.
        if (null === $narrowed) {
            return [];
        }

        $groups = self::withinPreset($this->options->forGroups($offeredGroupIds, $language, $fallback), $preset, $state);

        // Counted always — the switch only hides the numbers (class comment,
        // "Without numbers"). Without them the panel would link empty options.
        $counts = $this->counts($groups, $shopId, $preset, $chosen, $narrowed, $language, $currency, $state->availableOnly);

        try {
            return $this->panel($page, $state, $groups, $counts, $listing->showCounts);
        } catch (RoutingException) {
            return [];
        }
    }

    /**
     * @param list<FilterGroupView> $groups
     * @param array<int, list<int>> $preset
     * @param array<int, list<int>> $chosen
     * @param array<int, list<int>> $narrowed
     *
     * @param bool                  $availableOnly "Nur verfügbare" — for the shown numbers, never the category's
     *
     * @return array{category: array<int, int>, shown: array<int, array<int, int>>} option id => number
     *                                                                             in the category alone,
     *                                                                             and per group what it shows
     */
    private function counts(array $groups, int $shopId, array $preset, array $chosen, array $narrowed, string $language, string $currency, bool $availableOnly): array
    {
        // The category's own set stays without "Nur verfügbare": what it
        // decides — which options appear at all — must not move with the
        // stock. A sold-out colour stays visible, unlinked, like any option a
        // choice brings down to zero.
        $category = $this->counts->countsFor($shopId, $preset, null, $language, $currency);
        $untouched = [] === $chosen && !$availableOnly ? $category : null;
        $shown = [];

        foreach ($groups as $group) {
            $shown[$group->id] = isset($chosen[$group->id])
                ? $this->counts->countsFor($shopId, $narrowed, $group->id, $language, $currency, $availableOnly)
                : $untouched ??= $this->counts->countsFor($shopId, $narrowed, null, $language, $currency, $availableOnly);
        }

        return ['category' => $category, 'shown' => $shown];
    }

    /**
     * "Nur verfügbare" (block Verfügbarkeit, V5) — null where the listing
     * answers 404 or nothing can match, like build().
     *
     * Its number is what ticking it leaves under the visitor's filters.
     * Unticking always leads somewhere; ticking that leaves nothing has no
     * link, the same rule as an option (class comment).
     */
    public function availability(PageModel $page, int $shopId, ListingElement $listing, ListingAddress $address, string $language, string $currency): AvailabilityChoice|null
    {
        if (!$address->valid) {
            return null;
        }

        $state = $address->state;
        $narrowed = $this->filters->narrow($this->filters->group($listing->preset), $this->filters->resolve($state));

        if (null === $narrowed) {
            return null;
        }

        $count = $this->counts->count($shopId, $narrowed, $language, $currency, [], true);

        try {
            $url = !$state->availableOnly && 0 === $count ? null : $this->urls->toggleAvailable($page, $state);
        } catch (RoutingException) {
            return null;
        }

        return new AvailabilityChoice($state->availableOnly, $listing->showCounts ? $count : null, $url);
    }

    /**
     * @param list<FilterGroupView>                                               $groups
     * @param array{category: array<int, int>, shown: array<int, array<int, int>>} $counts
     * @param bool                                                                $showCounts whether the numbers are shown; they decide either way
     *
     * @throws RoutingException when the page has no route
     *
     * @return list<FilterGroupView>
     */
    private function panel(PageModel $page, FilterState $state, array $groups, array $counts, bool $showCounts): array
    {
        $panel = [];

        foreach ($groups as $group) {
            $options = [];

            foreach ($group->options as $option) {
                $selected = \in_array($option->alias, $state->filters[$group->alias] ?? [], true);

                if (!$selected && 0 === ($counts['category'][$option->id] ?? 0)) {
                    continue;
                }

                $count = $counts['shown'][$group->id][$option->id] ?? 0;

                // Deselecting always leads somewhere; choosing an option that
                // leaves nothing would lead to a 404.
                $url = !$selected && 0 === $count ? null : $this->urls->toggle($page, $state, $group->alias, $option->alias);

                $options[] = $option->withChoice($showCounts ? $count : null, $selected, $url);
            }

            // One option left narrows nothing — unless it is chosen: dropping
            // the group then would leave no way to deselect it (a landing page
            // "250 ml" in a category where everything is 250 ml).
            if (\count($options) > 1 || array_any($options, static fn (FilterOptionView $option): bool => $option->selected)) {
                $panel[] = $group->withOptions($options);
            }
        }

        return $panel;
    }

    /**
     * Where the category presets a group, only the preset's options remain —
     * plus any option the address already carries.
     *
     * The same promise panel() keeps for an option with no products: what the
     * visitor chose stays visible so it can be deselected. Dropped here, before
     * panel() ever sees it, deselecting its neighbour would lead straight to a
     * 404 (?cq=colour:navy,red in a category preset to red and blue).
     *
     * @param list<FilterGroupView> $groups
     * @param array<int, list<int>> $preset
     *
     * @return list<FilterGroupView>
     */
    private static function withinPreset(array $groups, array $preset, FilterState $state): array
    {
        return array_map(
            static fn (FilterGroupView $group): FilterGroupView => isset($preset[$group->id])
                ? $group->withOptions(array_values(array_filter(
                    $group->options,
                    static fn (FilterOptionView $option): bool => \in_array($option->id, $preset[$group->id], true)
                        || \in_array($option->alias, $state->filters[$group->alias] ?? [], true),
                )))
                : $group,
            $groups,
        );
    }
}

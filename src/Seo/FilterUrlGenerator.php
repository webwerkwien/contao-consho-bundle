<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The only place a filter address is put together — every filter link, every
 * sort link, and the canonical of a landing page.
 *
 * ── An address is a function of (page, state), never of the click path ────
 *
 *     filters of the state released on this page?
 *         yes → /<page>/<alias>.html   + ?cq=sort_by:… only when sorted
 *         no  → /<page>.html           + ?cq=<whole state> when not empty
 *
 * Where the visitor stands plays no part. Red then 250 ml and 250 ml then red
 * reach the same state, so they reach the same address — even though the
 * second path passes through the landing page of 250 ml on the way. A link
 * built from "landing page plus the rest in cq" would give the same products
 * two addresses, depending on which filter came first
 * (docs/block-b-katalog-frontend.md, 4.5).
 *
 * ── Why the page number disappears ────────────────────────────────────────
 *
 * Deliberately, and without a line of code: this class gets no Request. A
 * page_c<ID> cannot come along, so filtering or sorting from page three starts
 * over on page one — which is right, because the new set has different pages.
 * Any other query parameter of the current request is dropped for the same
 * reason.
 *
 * ── Why cq goes through ContentUrlGenerator instead of being appended ─────
 *
 * Passed as a parameter the route does not declare, it reaches Symfony's
 * UrlGenerator, which appends it with http_build_query() and then decodes
 * ":" ";" "," again — RFC 3986 allows them in a query. The address therefore
 * stays readable (cq=colour:red;volume:250ml), and anything outside the codec's
 * alphabet is still escaped: an option alias containing "&" cannot turn into a
 * second query parameter. Concatenating by hand would give up the second half.
 *
 * No framework->initialize() as in ProductUrlGenerator: that one loads the
 * page itself, possibly before the framework is up. Here the caller hands the
 * page in, and a PageModel only exists once the framework does.
 */
final class FilterUrlGenerator
{
    /**
     * The route variable that carries the path segment (PageRegistry).
     */
    private const SEGMENT = 'parameters';

    public function __construct(
        private readonly ContentUrlGenerator $urls,
        private readonly FacetPageResolver $facets,
        private readonly FilterStateCodec $codec,
    ) {
    }

    /**
     * The address after selecting or deselecting one option.
     */
    public function toggle(
        PageModel $page,
        FilterState $state,
        string $group,
        string $option,
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH,
    ): string {
        return $this->addressOf($page, self::toggled($state, $group, $option), $referenceType);
    }

    /**
     * The address of the same filters in another order. An empty sort is the
     * listing's default order.
     */
    public function withSort(
        PageModel $page,
        FilterState $state,
        string $sort,
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH,
    ): string {
        return $this->addressOf($page, new FilterState($state->filters, $sort, $state->availableOnly), $referenceType);
    }

    /**
     * The address after ticking or unticking "Nur verfügbare" (block
     * Verfügbarkeit, V5). Filters and sort order stay.
     */
    public function toggleAvailable(
        PageModel $page,
        FilterState $state,
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH,
    ): string {
        return $this->addressOf($page, new FilterState($state->filters, $state->sort, !$state->availableOnly), $referenceType);
    }

    /**
     * @param array<string, int|string> $query Further query values, appended after the
     *                                         address's own — the page number in the
     *                                         canonical of a landing page. The caller
     *                                         names them explicitly; nothing comes
     *                                         along from the request. They may add to
     *                                         the address but never change it, so cq
     *                                         and the path segment are refused.
     *
     * @throws \InvalidArgumentException when $query carries cq or the path segment
     */
    public function addressOf(
        PageModel $page,
        FilterState $state,
        int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH,
        array $query = [],
    ): string {
        foreach ([FilterStateCodec::PARAMETER, self::SEGMENT] as $reserved) {
            if (\array_key_exists($reserved, $query)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is part of the address itself and cannot be passed as an extra query value.', $reserved));
            }
        }

        // Asked without the sort order: aliasFor() refuses a sorted state, and a
        // sorted view of a released combination is still that landing page.
        // "Nur verfügbare" likewise — it travels in cq on top of the path.
        $alias = $this->facets->aliasFor((int) $page->id, new FilterState($state->filters));

        if (null === $alias) {
            return $this->urls->generate($page, [...$this->query($state), ...$query], $referenceType);
        }

        // The segment is handed over the way the core does it: as the route's
        // "parameters" variable, with its leading slash (Frontend::addToUrl()).
        return $this->urls->generate(
            $page,
            [self::SEGMENT => '/'.$alias, ...$this->query(new FilterState([], $state->sort, $state->availableOnly)), ...$query],
            $referenceType,
        );
    }

    /**
     * @return array<string, string>
     */
    private function query(FilterState $state): array
    {
        $encoded = $this->codec->encode($state);

        return null === $encoded ? [] : [FilterStateCodec::PARAMETER => $encoded];
    }

    /**
     * The order of groups and options is the codec's business, not this one's.
     */
    private static function toggled(FilterState $state, string $group, string $option): FilterState
    {
        $filters = $state->filters;
        $options = $filters[$group] ?? [];

        if (\in_array($option, $options, true)) {
            $options = array_values(array_diff($options, [$option]));
        } else {
            $options[] = $option;
        }

        // An empty group falls out rather than staying as "colour:" — the state
        // after deselecting the last option is the same as before selecting it.
        if ([] === $options) {
            unset($filters[$group]);
        } else {
            $filters[$group] = $options;
        }

        return new FilterState($filters, $state->sort, $state->availableOnly);
    }
}

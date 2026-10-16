<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\PageModel;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Seo\FilterUrlGenerator;

/**
 * The sort choice of a listing — links, not a form (B-4 decision 7).
 *
 * Every address comes from FilterUrlGenerator::withSort(), so a sorted landing
 * page stays the landing page and the page number is left behind: a new order
 * starts again on page one.
 *
 * ── The listing's default order has no sort value ─────────────────────────
 *
 * Its link is withSort(…, ''), never sort_by:<default>. Otherwise the same order
 * would have two addresses. For the same reason the default is also the active
 * entry when the state carries no sort order, or one the listing does not know.
 */
final class SortLinks
{
    public function __construct(private readonly FilterUrlGenerator $urls)
    {
    }

    /**
     * @param string $default the listing's own order, a known ProductSort value
     *
     * @return list<SortLink>
     */
    public function for(PageModel $page, FilterState $state, string $default): array
    {
        $current = ProductSort::isKnown($state->sort) ? $state->sort : $default;
        $links = [];

        foreach (ProductSort::ALL as $sort) {
            // "Manual order" means the editor's sequence, which tells a visitor
            // nothing — offered only where it is how the listing arrives.
            if (ProductSort::MANUAL === $sort && ProductSort::MANUAL !== $default) {
                continue;
            }

            $links[] = new SortLink(
                $sort,
                $this->urls->withSort($page, $state, $sort === $default ? '' : $sort),
                $sort === $current,
            );
        }

        return $links;
    }
}

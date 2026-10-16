<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRecord;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;

/**
 * Whether a landing page shows anything right now (plan B-8).
 *
 * A landing page whose combination matches nothing answers 404 (B-4) — and a
 * sitemap that lists it sends crawlers to an error page. So the sitemap asks
 * the same question the listing asks, with the listing's own classes:
 *
 * 1. The first listing on the page that follows the address — no listing, no
 *    reader of the path segment, so Contao answers 404 itself.
 * 2. Every option of the combination still resolves (ListingVerdict: a landing
 *    page that resolves only partly is a 404, not a wider listing).
 * 3. The preset narrowed by the combination is not empty.
 * 4. The rule finds a product, or a pin matches the combination (B-7, rule 5:
 *    a visitor who filters keeps the pins that match).
 *
 * ⚠️ A listing set to "pins only" is not told apart: its rule is counted as
 * well. Such a listing with landing pages below it is improbable enough to
 * leave the sitemap one address too generous there.
 */
class FacetPageHits
{
    public function __construct(
        private readonly ListingPageRepository $listings,
        private readonly FilterResolver $filters,
        private readonly FilterStateCodec $codec,
        private readonly ProductQuery $products,
        private readonly DbalPinRepository $pins,
    ) {
    }

    public function any(FacetPageRecord $landing, int $shopId, int $now, string $language, string $currency): bool
    {
        $listing = $this->listings->firstOnPage($landing->pageId, $now);

        if (null === $listing) {
            return false;
        }

        $filters = $this->codec->decode($landing->filterState)->filters;

        if ([] === $filters) {
            return false;
        }

        $chosen = $this->filters->resolve(new FilterState($filters));

        if (array_sum(array_map(\count(...), $chosen)) < ListingVerdict::requestedOptions($filters)) {
            return false;
        }

        $groups = $this->filters->narrow($this->filters->group($listing->preset), $chosen);

        if (null === $groups) {
            return false;
        }

        if ($this->products->count($shopId, $groups, $language, $currency) > 0) {
            return true;
        }

        return [] !== $this->products->visibleAmong($this->pins->productIdsOf($listing->id, $now), $shopId, $chosen, $language, $currency);
    }
}

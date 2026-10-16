<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Webwerkwien\ContaoConshoBundle\Seo\ListingAddress;

/**
 * When a listing that follows the address answers 404
 * (docs/block-b-katalog-frontend.md, 10; B-4 decision 9).
 *
 * | invalid address                                               | 404 |
 * | page number below 1 or past the last page                     | 404 |
 * | filters asked for, intersection with the preset empty         | 404 |
 * | filters asked for, no products                                | 404 |
 * | landing page whose stored combination no longer fully resolves | 404 |
 * | a sort order alone, no products                               | 200 |
 * | nothing asked for, no products                                | 200 |
 *
 * A sort order is not a filter. It produces no set of its own, so an empty
 * sorted category is still just an empty category — a shop being filled.
 *
 * On the category an alias that no longer resolves is dropped silently: a stale
 * bookmark shows a listing one filter wider. A landing page has no such
 * leeway, because it would show that wider set under its own title.
 *
 * The page number follows the core (ContentGallery, ImagesController): outside
 * the range is no page. B-3 fell back to the last page instead, which gave every
 * number past it the same content.
 */
final class ListingVerdict
{
    /**
     * @param array<int, list<int>>|null $groups          the result of FilterResolver::narrow()
     * @param int                        $pages           the number of pages; 0 counts as 1
     * @param int                        $resolvedOptions option ids FilterResolver::resolve() found for the state
     */
    public static function isNotFound(
        ListingAddress $address,
        array|null $groups,
        int $matches,
        int $requestedPage,
        int $pages,
        int $resolvedOptions,
    ): bool {
        if (!$address->valid) {
            return true;
        }

        if ($requestedPage < 1 || $requestedPage > max(1, $pages)) {
            return true;
        }

        $filters = $address->state->filters;

        if ([] === $filters) {
            return false;
        }

        if (null === $groups || 0 === $matches) {
            return true;
        }

        return null !== $address->facet && $resolvedOptions < self::requestedOptions($filters);
    }

    /**
     * How many options an address names — shared with FacetPageHits, so the
     * sitemap and the listing agree on "does not fully resolve".
     *
     * @param array<string, list<string>> $filters
     */
    public static function requestedOptions(array $filters): int
    {
        return array_sum(array_map(static fn (array $options): int => \count(array_unique($options)), $filters));
    }
}

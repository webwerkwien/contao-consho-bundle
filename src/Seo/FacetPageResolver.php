<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

/**
 * Decides which URL class a filter state belongs to.
 *
 * The lookup goes through the codec first, so the answer does not depend on the
 * order the customer clicked in: volume before colour and colour before volume
 * find the same release.
 *
 * Every question is asked for one listing page: a landing page lives below the
 * category page it narrows down (docs/block-b-katalog-frontend.md, 4.5), so the
 * same combination may be released on one category and not on another.
 */
final class FacetPageResolver
{
    public function __construct(
        private readonly FacetPageRepositoryInterface $repository,
        private readonly FilterStateCodec $codec,
    ) {
    }

    public function classify(int $pageId, FilterState $state): UrlClass
    {
        if ($state->isEmpty()) {
            return UrlClass::Category;
        }

        return null === $this->aliasFor($pageId, $state) ? UrlClass::Parameter : UrlClass::FacetPage;
    }

    public function aliasFor(int $pageId, FilterState $state): ?string
    {
        // A sort order produces the same products in a different order — always
        // duplicate content, never worth its own indexed page. And an empty
        // state is the category, which has an address already; asking the
        // database for either would be a query per listing view for an answer
        // that cannot change.
        // "Nur verfügbare" the same: a view by stock, never its own page
        // (block Verfügbarkeit, V5).
        if ('' !== $state->sort || $state->availableOnly || [] === $state->filters) {
            return null;
        }

        return $this->landingFor($pageId, $state)?->alias;
    }

    /**
     * The landing page of the filters in a state, whatever the sort order.
     *
     * aliasFor() answers "does this state get a path segment" and says no to a
     * sorted state. This answers "which landing page is this content", which a
     * sorted view of a released combination still is.
     */
    public function landingFor(int $pageId, FilterState $state): ?FacetPageRecord
    {
        // Null for a state without filters: the codec encodes nothing, and the
        // database is not asked.
        $encoded = $this->codec->encode(new FilterState($state->filters));

        return null === $encoded ? null : $this->repository->findByState($pageId, $encoded);
    }
}

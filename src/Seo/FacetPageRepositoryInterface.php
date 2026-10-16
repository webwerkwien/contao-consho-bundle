<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

/**
 * Reads the editorial releases.
 *
 * An interface rather than the DBAL class directly, so the resolver can be
 * tested without a database — and so the answer can later come from a cache
 * without the resolver noticing.
 *
 * Both lookups are scoped to one listing page: a release belongs to the page it
 * lives below, not to the shop. The same combination on another category is a
 * different set of products and a different landing page.
 */
interface FacetPageRepositoryInterface
{
    /**
     * @param string $filterState normalised, see FilterStateCodec::encode()
     */
    public function findByState(int $pageId, string $filterState): ?FacetPageRecord;

    public function findByAlias(int $pageId, string $alias): ?FacetPageRecord;

    /**
     * Every complete landing page, ordered by page and alias — for the sitemap,
     * which decides per page whether its landing pages belong in it.
     *
     * @return list<FacetPageRecord>
     */
    public function findAll(): array;
}

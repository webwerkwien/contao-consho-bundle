<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

/**
 * One released landing page, as read for the front end.
 *
 * Only complete records become one: DbalFacetPageRepository skips rows without
 * a filter state or an alias, so both are never empty here.
 */
final class FacetPageRecord
{
    public function __construct(
        public readonly int $id,
        /** The listing page it lives below (tl_consho_facet_page.pid). */
        public readonly int $pageId,
        public readonly string $alias,
        /** Normalised, as FilterStateCodec::encode() writes it. */
        public readonly string $filterState,
        public readonly string $pageTitle,
        /** Empty when none was written; the page's own description applies then. */
        public readonly string $description,
    ) {
    }
}

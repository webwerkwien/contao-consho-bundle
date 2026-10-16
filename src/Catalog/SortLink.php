<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * One entry of the sort choice in a listing.
 */
final class SortLink
{
    public function __construct(
        /** A ProductSort value — also the key of its label, CONSHO.sort.<value>. */
        public readonly string $sort,
        public readonly string $url,
        /** The order the listing is shown in right now. */
        public readonly bool $active,
    ) {
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * A product listing element, as far as the page around it needs to know it.
 */
final class ListingElement
{
    /**
     * @param list<int> $preset option ids of conshoFilter — the category
     */
    public function __construct(
        public readonly int $id,
        /** The page of the article it stands in; 0 outside an article (layout section). */
        public readonly int $pageId,
        public readonly array $preset,
        public readonly bool $showCounts,
        public readonly bool $followsAddress,
        /** "Pins only" (B-7, E2): the preset shows nothing, only the pins do. */
        public readonly bool $pinsOnly = false,
    ) {
    }

    /**
     * The query parameter carrying a listing's page number.
     *
     * Per element, so two listings on one page page independently. Named in
     * this one place: the listing reads it, the pagination writes it, and the
     * canonical keeps it.
     */
    public static function pageParameter(int $elementId): string
    {
        return 'page_c'.$elementId;
    }
}

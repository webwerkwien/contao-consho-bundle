<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * One page of a listing with pins: which pins it shows, and which part of the
 * rule behind them.
 */
final class PinnedSlice
{
    /**
     * @param list<int> $pins            the pinned products on this page, in the dragged order
     * @param int|null  $limit           how many products of the rule follow them: null = all, 0 = none
     * @param int       $pages           the number of pages; 0 for an empty listing, as before B-7
     * @param int       $matches         pins and rule together — what 404 and "empty" are judged by
     * @param int       $paginationTotal what Contao's Pagination has to be told to draw this many pages
     * @param int       $page            the page shown, within range
     */
    public function __construct(
        public readonly array $pins,
        public readonly int $offset,
        public readonly int|null $limit,
        public readonly int $pages,
        public readonly int $matches,
        public readonly int $paginationTotal,
        public readonly int $page,
    ) {
    }
}

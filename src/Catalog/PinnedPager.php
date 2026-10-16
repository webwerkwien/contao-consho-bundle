<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * Splits "pins, then the rule" into pages (B-7, decision E1).
 *
 * Arithmetic only, so that the part of the listing that is easiest to get
 * wrong by one can be tested without a database or a request.
 *
 * Every pin stands before every product of the rule. How the pins meet the
 * pagination is set at the listing element (`conshoPinsExtra`):
 *
 * | off (default) | the pins TAKE places: one sequence, pins first, every page the same size.
 *                   More pins than a page holds continue on page two. |
 * | on            | ALL pins come on top of page one, which grows by that many; the rule
 *                   pages exactly as it would without them. |
 *
 * The rule is always asked WITHOUT the pins (ProductQuery `$excluded`), so an
 * offset into it never counts a pinned product twice.
 */
final class PinnedPager
{
    /**
     * @param list<int> $pins          visible pins, in the dragged order
     * @param int       $rest          products of the rule, pins excluded
     * @param int       $perPage       0 = no pagination
     * @param int       $requestedPage as it came from the address; judged by ListingVerdict, clamped here
     */
    public static function slice(array $pins, int $rest, int $perPage, int $requestedPage, bool $extra): PinnedSlice
    {
        $pinCount = \count($pins);
        $matches = $pinCount + $rest;

        if ($perPage <= 0) {
            return new PinnedSlice($pins, 0, null, 1, $matches, $matches, 1);
        }

        if ($extra) {
            // Pins alone still make one page; nothing at all makes none.
            $pages = $rest > 0 ? (int) ceil($rest / $perPage) : ($pinCount > 0 ? 1 : 0);
            $page = self::clamp($requestedPage, $pages);

            return 1 === $page
                ? new PinnedSlice($pins, 0, $perPage, $pages, $matches, $rest, $page)
                : new PinnedSlice([], ($page - 1) * $perPage, $perPage, $pages, $matches, $rest, $page);
        }

        $pages = (int) ceil($matches / $perPage);
        $page = self::clamp($requestedPage, $pages);
        $start = ($page - 1) * $perPage;
        $onPage = \array_slice($pins, $start, $perPage);

        return new PinnedSlice(
            $onPage,
            max(0, $start - $pinCount),
            $perPage - \count($onPage),
            $pages,
            $matches,
            $matches,
            $page,
        );
    }

    private static function clamp(int $requestedPage, int $pages): int
    {
        return min(max(1, $requestedPage), max(1, $pages));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\PinnedPager;
use Webwerkwien\ContaoConshoBundle\Catalog\PinnedSlice;

/**
 * The page split "pins first, then the rule" (B-7, E1).
 *
 * `$rest` is the count of the rule WITHOUT the pinned products — an offset
 * into the rule therefore never counts a pinned product.
 */
class PinnedPagerTest extends TestCase
{
    public function testWithoutPaginationEveryPinAndTheWholeRule(): void
    {
        self::assertEquals(new PinnedSlice([7, 3], 0, null, 1, 12, 12, 1), PinnedPager::slice([7, 3], 10, 0, 1, false));
    }

    public function testPinsTakePlacesOnPageOne(): void
    {
        // 12 per page, 3 pins, 20 more: page 1 = 3 + 9.
        self::assertEquals(new PinnedSlice([1, 2, 3], 0, 9, 2, 23, 23, 1), PinnedPager::slice([1, 2, 3], 20, 12, 1, false));
    }

    public function testPageTwoContinuesTheRuleWhereItStopped(): void
    {
        // Page 1 had 9 from the rule — page 2 starts at the tenth.
        self::assertEquals(new PinnedSlice([], 9, 12, 2, 23, 23, 2), PinnedPager::slice([1, 2, 3], 20, 12, 2, false));
    }

    public function testMorePinsThanPlacesContinueOnPageTwo(): void
    {
        $pins = range(1, 15);

        self::assertEquals(new PinnedSlice(range(1, 12), 0, 0, 2, 20, 20, 1), PinnedPager::slice($pins, 5, 12, 1, false));
        self::assertEquals(new PinnedSlice([13, 14, 15], 0, 9, 2, 20, 20, 2), PinnedPager::slice($pins, 5, 12, 2, false));
    }

    public function testExtraPutsAllPinsOnTopOfPageOne(): void
    {
        // Page 1 = 3 + 12; paging only counts the rule.
        self::assertEquals(new PinnedSlice([1, 2, 3], 0, 12, 2, 23, 20, 1), PinnedPager::slice([1, 2, 3], 20, 12, 1, true));
    }

    public function testExtraLeavesTheOtherPagesAsWithoutPins(): void
    {
        self::assertEquals(new PinnedSlice([], 12, 12, 2, 23, 20, 2), PinnedPager::slice([1, 2, 3], 20, 12, 2, true));
    }

    public function testExtraKeepsEvenMorePinsThanAPageOnPageOne(): void
    {
        $pins = range(1, 15);

        self::assertEquals(new PinnedSlice($pins, 0, 12, 1, 20, 5, 1), PinnedPager::slice($pins, 5, 12, 1, true));
    }

    public function testPinsAloneMakeOnePageInBothModes(): void
    {
        self::assertSame(1, PinnedPager::slice([4, 5], 0, 12, 1, true)->pages);
        self::assertSame(1, PinnedPager::slice([4, 5], 0, 12, 1, false)->pages);
    }

    public function testWithoutPinsTheListingPagesAsBefore(): void
    {
        // The rule from B-3: offset (page − 1) × per page.
        foreach ([false, true] as $extra) {
            self::assertEquals(new PinnedSlice([], 24, 12, 3, 25, 25, 3), PinnedPager::slice([], 25, 12, 3, $extra));
        }
    }

    public function testAnEmptyListingHasNoPagesAsBefore(): void
    {
        // ListingVerdict reads 0 pages as one — that is how B-3 kept it.
        self::assertSame(0, PinnedPager::slice([], 0, 12, 1, false)->pages);
        self::assertSame(0, PinnedPager::slice([], 0, 12, 1, true)->pages);
    }

    public function testAPagePastTheEndIsClampedForAListingThatDoesNotFollowTheAddress(): void
    {
        // An address-following list answered 404 before (ListingVerdict
        // gets the requested page, not this one).
        self::assertSame(2, PinnedPager::slice([1], 20, 12, 9, false)->page);
    }
}

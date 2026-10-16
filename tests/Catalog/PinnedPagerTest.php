<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\PinnedPager;
use Webwerkwien\ContaoConshoBundle\Catalog\PinnedSlice;

/**
 * Die Seitenaufteilung „erst Anheftungen, dann die Regel" (B-7, E1).
 *
 * `$rest` ist die Zahl der Regel OHNE die angehefteten Produkte — ein Versatz
 * in die Regel zählt deshalb nie ein angeheftetes Produkt mit.
 */
class PinnedPagerTest extends TestCase
{
    public function testWithoutPaginationEveryPinAndTheWholeRule(): void
    {
        self::assertEquals(new PinnedSlice([7, 3], 0, null, 1, 12, 12, 1), PinnedPager::slice([7, 3], 10, 0, 1, false));
    }

    public function testPinsTakePlacesOnPageOne(): void
    {
        // 12 je Seite, 3 Anheftungen, 20 weitere: Seite 1 = 3 + 9.
        self::assertEquals(new PinnedSlice([1, 2, 3], 0, 9, 2, 23, 23, 1), PinnedPager::slice([1, 2, 3], 20, 12, 1, false));
    }

    public function testPageTwoContinuesTheRuleWhereItStopped(): void
    {
        // Seite 1 hatte 9 aus der Regel — Seite 2 beginnt bei der zehnten.
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
        // Seite 1 = 3 + 12; Blätterung rechnet nur mit der Regel.
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
        // Die Regel aus B-3: Versatz (Seite − 1) × je Seite.
        foreach ([false, true] as $extra) {
            self::assertEquals(new PinnedSlice([], 24, 12, 3, 25, 25, 3), PinnedPager::slice([], 25, 12, 3, $extra));
        }
    }

    public function testAnEmptyListingHasNoPagesAsBefore(): void
    {
        // ListingVerdict liest 0 Seiten als eine — so hielt es B-3.
        self::assertSame(0, PinnedPager::slice([], 0, 12, 1, false)->pages);
        self::assertSame(0, PinnedPager::slice([], 0, 12, 1, true)->pages);
    }

    public function testAPagePastTheEndIsClampedForAListingThatDoesNotFollowTheAddress(): void
    {
        // Eine adressfolgende Liste hat vorher 404 geantwortet (ListingVerdict
        // bekommt die angefragte Seite, nicht diese).
        self::assertSame(2, PinnedPager::slice([1], 20, 12, 9, false)->page);
    }
}

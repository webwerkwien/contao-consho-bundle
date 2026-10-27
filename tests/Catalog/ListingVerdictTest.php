<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingVerdict;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRecord;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Seo\ListingAddress;

/**
 * The table of outcomes from B-4 is the contract.
 *
 * Groups stand for the result of FilterResolver::narrow(): null means the
 * visitor's choice cuts the category's default down to nothing.
 */
class ListingVerdictTest extends TestCase
{
    private const GROUPS = [3 => [31]];

    /**
     * @param array<int, list<int>>|null $groups
     */
    #[DataProvider('cases')]
    public function testTheTableOfDecisionNine(
        ListingAddress $address,
        array|null $groups,
        int $matches,
        int $requestedPage,
        int $pages,
        int $resolvedOptions,
        bool $notFound,
    ): void {
        self::assertSame($notFound, ListingVerdict::isNotFound($address, $groups, $matches, $requestedPage, $pages, $resolvedOptions));
    }

    /**
     * @return iterable<string, array{ListingAddress, array<int, list<int>>|null, int, int, int, int, bool}>
     */
    public static function cases(): iterable
    {
        $filter = ListingAddress::category(new FilterState(['colour' => ['red']]));
        $landing = self::landing(['volume' => ['250ml']]);

        yield 'ungültige Adresse' => [ListingAddress::invalid(), [], 5, 1, 1, 0, true];

        // A shop that is just being filled: a notice instead of an error page.
        yield 'Kategorie ohne Filter, null Treffer' => [ListingAddress::category(new FilterState()), [], 0, 1, 1, 0, false];

        // Sorting creates no set. Without hits it stays the empty
        // category — not a combination that contains nothing.
        yield 'Kategorie, nur Sortierung, null Treffer' => [ListingAddress::category(new FilterState([], 'price_asc')), [], 0, 1, 1, 0, false];

        yield 'Filter schneidet die Vorgabe leer' => [$filter, null, 0, 1, 1, 1, true];
        yield 'Filter ohne Treffer' => [$filter, self::GROUPS, 0, 1, 1, 1, true];
        yield 'Filter mit Treffern' => [$filter, self::GROUPS, 5, 1, 1, 1, false];

        // Specification 10: an unknown alias in cq is silently discarded,
        // the list is then one option wider than requested.
        yield 'Kategorie, ein Alias unbekannt' => [
            ListingAddress::category(new FilterState(['colour' => ['gibtsnicht', 'red']])),
            self::GROUPS, 5, 1, 1, 1, false,
        ];

        yield 'Landingpage ohne Treffer' => [$landing, self::GROUPS, 0, 1, 1, 1, true];
        yield 'Landingpage mit Treffern' => [$landing, self::GROUPS, 5, 1, 1, 1, false];

        // An option of the stored combination has been deleted. Otherwise the
        // wider set would appear under the title of the landing page.
        yield 'Landingpage, Option inzwischen gelöscht' => [
            self::landing(['colour' => ['red'], 'volume' => ['250ml']]),
            self::GROUPS, 5, 1, 1, 1, true,
        ];

        yield 'Landingpage mit Sortierung, alles aufgelöst' => [
            ListingAddress::landingPage(self::record('volume:250ml'), new FilterState(['volume' => ['250ml']], 'price_desc')),
            self::GROUPS, 5, 1, 1, 1, false,
        ];

        // Changes B-3, which fell back to the last page. The core does the
        // same (ContentGallery, ImagesController).
        yield 'Seite hinter der letzten' => [$filter, self::GROUPS, 30, 4, 3, 1, true];
        yield 'letzte Seite' => [$filter, self::GROUPS, 30, 3, 3, 1, false];
        yield 'Seite hinter der letzten, ohne Filter' => [ListingAddress::category(new FilterState()), [], 30, 4, 3, 0, true];
        yield 'Seite 2 einer leeren Kategorie' => [ListingAddress::category(new FilterState()), [], 0, 2, 0, 0, true];

        // Like the core: page_c7=0 and page_c7=abc are not a page.
        yield 'Seite 0' => [$filter, self::GROUPS, 30, 0, 3, 1, true];

        // ?cq=Foo decodes to nothing and is the
        // category without a filter — 200, even without hits.
        yield 'cq, das zu nichts dekodiert' => [
            ListingAddress::category((new FilterStateCodec())->decode('Foo')),
            [], 0, 1, 1, 0, false,
        ];
    }

    /**
     * @param array<string, list<string>> $filters
     */
    private static function landing(array $filters): ListingAddress
    {
        $encoded = (string) (new FilterStateCodec())->encode(new FilterState($filters));

        return ListingAddress::landingPage(self::record($encoded), new FilterState($filters));
    }

    private static function record(string $state): FacetPageRecord
    {
        return new FacetPageRecord(1, 12, 'schwedenbitter-250-ml', $state, 'Schwedenbitter 250 ml', '');
    }
}

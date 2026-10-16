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
 * Die Tabelle aus Entscheidung 9 des B-4-Plans ist der Vertrag.
 *
 * Gruppen stehen für das Ergebnis von FilterResolver::narrow(): null heißt, die
 * Wahl des Besuchers schneidet die Vorgabe der Kategorie leer.
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

        // Ein Shop, der gerade befüllt wird: Hinweis statt Fehlerseite.
        yield 'Kategorie ohne Filter, null Treffer' => [ListingAddress::category(new FilterState()), [], 0, 1, 1, 0, false];

        // Sortieren erzeugt keine Menge. Ohne Treffer bleibt es die leere
        // Kategorie — nicht eine Kombination, die nichts enthält.
        yield 'Kategorie, nur Sortierung, null Treffer' => [ListingAddress::category(new FilterState([], 'price_asc')), [], 0, 1, 1, 0, false];

        yield 'Filter schneidet die Vorgabe leer' => [$filter, null, 0, 1, 1, 1, true];
        yield 'Filter ohne Treffer' => [$filter, self::GROUPS, 0, 1, 1, 1, true];
        yield 'Filter mit Treffern' => [$filter, self::GROUPS, 5, 1, 1, 1, false];

        // Spezifikation 10: ein unbekannter Alias in cq wird still verworfen,
        // die Liste ist dann eine Option breiter als verlangt.
        yield 'Kategorie, ein Alias unbekannt' => [
            ListingAddress::category(new FilterState(['colour' => ['gibtsnicht', 'red']])),
            self::GROUPS, 5, 1, 1, 1, false,
        ];

        yield 'Landingpage ohne Treffer' => [$landing, self::GROUPS, 0, 1, 1, 1, true];
        yield 'Landingpage mit Treffern' => [$landing, self::GROUPS, 5, 1, 1, 1, false];

        // Eine Option der gespeicherten Kombination ist gelöscht. Unter dem
        // Titel der Landingpage stünde sonst die breitere Menge.
        yield 'Landingpage, Option inzwischen gelöscht' => [
            self::landing(['colour' => ['red'], 'volume' => ['250ml']]),
            self::GROUPS, 5, 1, 1, 1, true,
        ];

        yield 'Landingpage mit Sortierung, alles aufgelöst' => [
            ListingAddress::landingPage(self::record('volume:250ml'), new FilterState(['volume' => ['250ml']], 'price_desc')),
            self::GROUPS, 5, 1, 1, 1, false,
        ];

        // Ändert B-3, das auf die letzte Seite zurückfiel. Der Kern macht es
        // genauso (ContentGallery, ImagesController).
        yield 'Seite hinter der letzten' => [$filter, self::GROUPS, 30, 4, 3, 1, true];
        yield 'letzte Seite' => [$filter, self::GROUPS, 30, 3, 3, 1, false];
        yield 'Seite hinter der letzten, ohne Filter' => [ListingAddress::category(new FilterState()), [], 30, 4, 3, 0, true];
        yield 'Seite 2 einer leeren Kategorie' => [ListingAddress::category(new FilterState()), [], 0, 2, 0, 0, true];

        // Wie der Kern: page_c7=0 und page_c7=abc sind keine Seite.
        yield 'Seite 0' => [$filter, self::GROUPS, 30, 0, 3, 1, true];

        // Übergabe aus Task 4: ?cq=Foo dekodiert zu nichts und ist die
        // Kategorie ohne Filter — 200, auch ohne Treffer.
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

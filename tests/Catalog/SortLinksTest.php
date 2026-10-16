<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\Page\PageRegistry;
use Contao\CoreBundle\Routing\PageUrlGenerator;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Cmf\Component\Routing\RouteProviderInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSort;
use Webwerkwien\ContaoConshoBundle\Catalog\SortLink;
use Webwerkwien\ContaoConshoBundle\Catalog\SortLinks;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRecord;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageResolver;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Seo\FilterUrlGenerator;

/**
 * Die Sortierauswahl im Listentemplate (Michael, 2026-09-13).
 *
 * Wie FilterUrlGeneratorTest mit dem echten ContentUrlGenerator: geprüft wird,
 * was im href landet. Seite 12 „Kräuter" hat „volume:250ml" freigegeben.
 */
class SortLinksTest extends TestCase
{
    public function testEveryKnownOrderButTheManualOneGetsOneLinkInItsOwnOrder(): void
    {
        // Übergabe aus Task 7: „Eigene Reihenfolge" sagt einem Besucher nichts.
        $links = $this->links(new FilterState(), ProductSort::TITLE);

        self::assertSame(
            [ProductSort::TITLE, ProductSort::PRICE_ASC, ProductSort::PRICE_DESC, ProductSort::NEWEST],
            array_map(static fn (SortLink $link): string => $link->sort, $links),
        );
    }

    public function testTheManualOrderIsOfferedWhenItIsTheElementsDefault(): void
    {
        // Dann ist sie die Reihenfolge, in der die Liste ankommt — ohne Eintrag
        // stünde die Auswahl ohne aktiven Wert da.
        $links = $this->links(new FilterState(), ProductSort::MANUAL);
        $bySort = $this->bySort($links);

        self::assertSame(ProductSort::ALL, array_map(static fn (SortLink $link): string => $link->sort, $links));
        self::assertTrue($bySort[ProductSort::MANUAL]->active);
        self::assertSame('/kraeuter.html', $bySort[ProductSort::MANUAL]->url);
    }

    public function testTheDefaultOrderIsLinkedWithoutASortValue(): void
    {
        // Übergabe aus Task 5: withSort(…, ''), nicht sort_by:title. Sonst hätte
        // dieselbe Reihenfolge zwei Adressen.
        $links = $this->bySort($this->links(new FilterState(), ProductSort::TITLE));

        self::assertSame('/kraeuter.html', $links[ProductSort::TITLE]->url);
        self::assertSame('/kraeuter.html?cq=sort_by:price_asc', $links[ProductSort::PRICE_ASC]->url);
    }

    public function testWithoutASortValueTheDefaultIsTheActiveOne(): void
    {
        self::assertSame([ProductSort::TITLE], $this->active($this->links(new FilterState(), ProductSort::TITLE)));
    }

    public function testTheDefaultComesFromTheElementNotFromTheClass(): void
    {
        // Steht am Element „Preis aufsteigend", ist das die Adresse ohne sort —
        // und „Bezeichnung" braucht dann einen eigenen Wert.
        $links = $this->bySort($this->links(new FilterState(), ProductSort::PRICE_ASC));

        self::assertSame('/kraeuter.html', $links[ProductSort::PRICE_ASC]->url);
        self::assertSame('/kraeuter.html?cq=sort_by:title', $links[ProductSort::TITLE]->url);
        self::assertTrue($links[ProductSort::PRICE_ASC]->active);
    }

    public function testAChosenOrderIsTheActiveOneAndKeepsTheFilters(): void
    {
        $links = $this->bySort($this->links(new FilterState(['colour' => ['red']], ProductSort::PRICE_DESC), ProductSort::TITLE));

        self::assertSame([ProductSort::PRICE_DESC], $this->active(array_values($links)));
        self::assertSame('/kraeuter.html?cq=colour:red', $links[ProductSort::TITLE]->url);
        self::assertSame('/kraeuter.html?cq=colour:red;sort_by:newest', $links[ProductSort::NEWEST]->url);
    }

    public function testSortingOnALandingPageStaysOnIt(): void
    {
        // Sortieren erzeugt keine neue Menge — Überschrift und Einleitung der
        // Landingpage bleiben (Entscheidung 3).
        $links = $this->bySort($this->links(new FilterState(['volume' => ['250ml']]), ProductSort::TITLE));

        self::assertSame('/kraeuter/schwedenbitter-250-ml.html', $links[ProductSort::TITLE]->url);
        self::assertSame('/kraeuter/schwedenbitter-250-ml.html?cq=sort_by:price_desc', $links[ProductSort::PRICE_DESC]->url);
    }

    public function testTheDefaultWrittenOutByHandIsStillTheDefault(): void
    {
        // ?cq=sort_by:title bei Vorgabe „Bezeichnung" — von Hand getippt. Aktiv
        // ist die Bezeichnung, der Link zeigt trotzdem auf die Adresse ohne sort.
        $links = $this->bySort($this->links(new FilterState([], ProductSort::TITLE), ProductSort::TITLE));

        self::assertTrue($links[ProductSort::TITLE]->active);
        self::assertSame('/kraeuter.html', $links[ProductSort::TITLE]->url);
    }

    public function testAnUnknownOrderMarksTheDefaultActive(): void
    {
        // Die Liste sortiert dann nach der Vorgabe — die Auswahl muss dasselbe
        // sagen und darf nicht ohne aktiven Eintrag dastehen.
        self::assertSame([ProductSort::NEWEST], $this->active($this->links(new FilterState([], 'gibtsnicht'), ProductSort::NEWEST)));
    }

    /**
     * @return list<SortLink>
     */
    private function links(FilterState $state, string $default): array
    {
        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository->method('findByState')->willReturnCallback(
            static fn (int $page, string $encoded): ?FacetPageRecord => 12 === $page && 'volume:250ml' === $encoded
                ? new FacetPageRecord(1, 12, 'schwedenbitter-250-ml', $encoded, 'Schwedenbitter 250 ml', '')
                : null,
        );

        $codec = new FilterStateCodec();
        $urls = new FilterUrlGenerator($this->contentUrls(), new FacetPageResolver($repository, $codec), $codec);

        return (new SortLinks($urls))->for($this->kraeuter(), $state, $default);
    }

    /**
     * @param list<SortLink> $links
     *
     * @return array<string, SortLink>
     */
    private function bySort(array $links): array
    {
        return array_column(array_map(static fn (SortLink $link): array => ['sort' => $link->sort, 'link' => $link], $links), 'link', 'sort');
    }

    /**
     * @param list<SortLink> $links
     *
     * @return list<string>
     */
    private function active(array $links): array
    {
        return array_values(array_map(
            static fn (SortLink $link): string => $link->sort,
            array_filter($links, static fn (SortLink $link): bool => $link->active),
        ));
    }

    private function contentUrls(): ContentUrlGenerator
    {
        $registry = new PageRegistry($this->createMock(Connection::class));
        $router = new PageUrlGenerator($this->createMock(RouteProviderInterface::class), $registry);

        return new ContentUrlGenerator($router, $registry, $this->createMock(EntityManagerInterface::class), []);
    }

    private function kraeuter(): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', 12],
            ['type', 'regular'],
            ['alias', 'kraeuter'],
            ['requireItem', false],
            ['domain', ''],
            ['rootUseSSL', false],
            ['urlPrefix', ''],
            ['urlSuffix', '.html'],
        ]);

        return $page;
    }
}

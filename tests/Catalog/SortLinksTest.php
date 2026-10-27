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
 * The sort selection in the list template.
 *
 * Like FilterUrlGeneratorTest with the real ContentUrlGenerator: what is
 * checked is what ends up in the href. Page 12 "Kräuter" has released
 * "volume:250ml".
 */
class SortLinksTest extends TestCase
{
    public function testEveryKnownOrderButTheManualOneGetsOneLinkInItsOwnOrder(): void
    {
        // "Eigene Reihenfolge" (custom order) tells a visitor nothing.
        $links = $this->links(new FilterState(), ProductSort::TITLE);

        self::assertSame(
            [ProductSort::TITLE, ProductSort::PRICE_ASC, ProductSort::PRICE_DESC, ProductSort::NEWEST],
            array_map(static fn (SortLink $link): string => $link->sort, $links),
        );
    }

    public function testTheManualOrderIsOfferedWhenItIsTheElementsDefault(): void
    {
        // Then it is the order in which the list arrives — without an entry
        // the selection would have no active value.
        $links = $this->links(new FilterState(), ProductSort::MANUAL);
        $bySort = $this->bySort($links);

        self::assertSame(ProductSort::ALL, array_map(static fn (SortLink $link): string => $link->sort, $links));
        self::assertTrue($bySort[ProductSort::MANUAL]->active);
        self::assertSame('/kraeuter.html', $bySort[ProductSort::MANUAL]->url);
    }

    public function testTheDefaultOrderIsLinkedWithoutASortValue(): void
    {
        // withSort(…, ''), not sort_by:title. Otherwise
        // the same order would have two addresses.
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
        // If the element says "Preis aufsteigend" (price ascending), that is the
        // address without sort — and "Bezeichnung" (name) then needs a value of its own.
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
        // Sorting creates no new set — heading and introduction of the
        // landing page stay.
        $links = $this->bySort($this->links(new FilterState(['volume' => ['250ml']]), ProductSort::TITLE));

        self::assertSame('/kraeuter/schwedenbitter-250-ml.html', $links[ProductSort::TITLE]->url);
        self::assertSame('/kraeuter/schwedenbitter-250-ml.html?cq=sort_by:price_desc', $links[ProductSort::PRICE_DESC]->url);
    }

    public function testTheDefaultWrittenOutByHandIsStillTheDefault(): void
    {
        // ?cq=sort_by:title with the default "Bezeichnung" — typed by hand. The
        // name is active, the link still points to the address without sort.
        $links = $this->bySort($this->links(new FilterState([], ProductSort::TITLE), ProductSort::TITLE));

        self::assertTrue($links[ProductSort::TITLE]->active);
        self::assertSame('/kraeuter.html', $links[ProductSort::TITLE]->url);
    }

    public function testAnUnknownOrderMarksTheDefaultActive(): void
    {
        // The list then sorts by the default — the selection must say the same
        // and must not be left without an active entry.
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

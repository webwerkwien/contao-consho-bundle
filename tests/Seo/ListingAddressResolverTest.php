<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Input;
use Contao\PageModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRecord;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Seo\ListingAddressResolver;

class ListingAddressResolverTest extends TestCase
{
    public function testThePlainCategoryIsValidWithoutAnyFilter(): void
    {
        // /kraeuter.html — no segment, no cq. The database is not
        // asked: without a segment there is no landing page to look up.
        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository->expects(self::never())->method('findByAlias');

        $address = $this->resolver(null, $repository)->resolve($this->page(12), Request::create('/kraeuter.html'));

        self::assertTrue($address->valid);
        self::assertNull($address->facet);
        self::assertTrue($address->state->isEmpty());
    }

    public function testAFilterParameterOnTheCategoryIsItsState(): void
    {
        $address = $this->resolver(null)->resolve($this->page(12), Request::create('/kraeuter.html?cq=colour:red'));

        self::assertTrue($address->valid);
        self::assertNull($address->facet);
        self::assertSame(['colour' => ['red']], $address->state->filters);
        self::assertSame('', $address->state->sort);
    }

    public function testTheSegmentOfAReleasedCombinationIsItsLandingPage(): void
    {
        // The filters come from the stored combination, not from the
        // address — /kraeuter/schwedenbitter-250-ml.html carries no cq.
        $address = $this->resolver('schwedenbitter-250-ml')->resolve(
            $this->page(12),
            Request::create('/kraeuter/schwedenbitter-250-ml.html'),
        );

        self::assertTrue($address->valid);
        self::assertSame('schwedenbitter-250-ml', $address->facet?->alias);
        self::assertSame(['volume' => ['250ml']], $address->state->filters);
        self::assertSame('', $address->state->sort);
    }

    public function testASortOrderKeepsTheVisitorOnTheLandingPage(): void
    {
        // Sorting creates no new set. If the visitor left the
        // landing page doing so, its heading and introduction would disappear.
        $address = $this->resolver('schwedenbitter-250-ml')->resolve(
            $this->page(12),
            Request::create('/kraeuter/schwedenbitter-250-ml.html?cq=sort_by:price_asc'),
        );

        self::assertTrue($address->valid);
        self::assertSame('schwedenbitter-250-ml', $address->facet?->alias);
        self::assertSame(['volume' => ['250ml']], $address->state->filters);
        self::assertSame('price_asc', $address->state->sort);
    }

    public function testNurVerfuegbareBleibtAufDerLandingpage(): void
    {
        // Block Verfügbarkeit (availability), V5: like sorting — a view by stock,
        // not a new combination.
        $address = $this->resolver('schwedenbitter-250-ml')->resolve(
            $this->page(12),
            Request::create('/kraeuter/schwedenbitter-250-ml.html?cq=only_available:1'),
        );

        self::assertTrue($address->valid);
        self::assertSame('schwedenbitter-250-ml', $address->facet?->alias);
        self::assertTrue($address->state->availableOnly);
    }

    public function testFiltersOnTopOfALandingPageAreNoAddress(): void
    {
        // Consho never links such an address: red + 250 ml has exactly one
        // address, and that is the category with cq. If the landing page also
        // accepted cq, the same content would have any number of addresses.
        $address = $this->resolver('schwedenbitter-250-ml')->resolve(
            $this->page(12),
            Request::create('/kraeuter/schwedenbitter-250-ml.html?cq=colour:red'),
        );

        self::assertFalse($address->valid);
        self::assertNull($address->facet);
        self::assertTrue($address->state->isEmpty());
    }

    public function testAnUnknownSegmentIsNoAddress(): void
    {
        $address = $this->resolver('unbekannt')->resolve($this->page(12), Request::create('/kraeuter/unbekannt.html'));

        self::assertFalse($address->valid);
    }

    public function testTheLandingPageOfAnotherPageIsNoAddress(): void
    {
        // "tee-250-ml" is released under page 13. Under page 12 it is
        // an unknown segment — the release belongs to the page.
        $address = $this->resolver('tee-250-ml')->resolve($this->page(12), Request::create('/kraeuter/tee-250-ml.html'));

        self::assertFalse($address->valid);
    }

    public function testAnArrayInTheFilterParameterIsIgnoredNotRefused(): void
    {
        // Specification 10: FilterStateCodec::decode() never throws — the way
        // there must not either. ?cq[]=x is an empty category, not a
        // 400.
        $request = Request::create('/kraeuter.html', 'GET', ['cq' => ['x']]);

        $address = $this->resolver(null)->resolve($this->page(12), $request);

        self::assertTrue($address->valid);
        self::assertTrue($address->state->isEmpty());
    }

    public function testAParameterThatDecodesToNothingIsTheBareCategory(): void
    {
        // ?cq=Foo is like no
        // cq at all — 200, canonical without parameters, no filter links with leftovers.
        $address = $this->resolver(null)->resolve($this->page(12), Request::create('/kraeuter.html?cq=Foo'));

        self::assertTrue($address->valid);
        self::assertTrue($address->state->isEmpty());
    }

    public function testAParameterThatDecodesToNothingLeavesALandingPageValid(): void
    {
        $address = $this->resolver('schwedenbitter-250-ml')->resolve(
            $this->page(12),
            Request::create('/kraeuter/schwedenbitter-250-ml.html?cq=Foo'),
        );

        self::assertTrue($address->valid);
        self::assertSame('schwedenbitter-250-ml', $address->facet?->alias);
        self::assertSame('', $address->state->sort);
    }

    public function testAnUnknownSortOrderIsNoSortOrder(): void
    {
        // The same treatment for the sort key: with sort_by:gibtsnicht the list
        // sorts by its default. If the value stayed in the state,
        // the canonical would lose the page number, and filter links would carry
        // the garbage along.
        $address = $this->resolver(null)->resolve($this->page(12), Request::create('/kraeuter.html?cq=colour:red;sort_by:gibtsnicht'));

        self::assertTrue($address->valid);
        self::assertSame(['colour' => ['red']], $address->state->filters);
        self::assertSame('', $address->state->sort);
    }

    public function testAnUnknownSortOrderOnALandingPageIsNoSortOrderEither(): void
    {
        $address = $this->resolver('schwedenbitter-250-ml')->resolve(
            $this->page(12),
            Request::create('/kraeuter/schwedenbitter-250-ml.html?cq=sort_by:gibtsnicht'),
        );

        self::assertTrue($address->valid);
        self::assertTrue('' === $address->state->sort);
    }

    public function testTheQueryBagItselfWouldRefuseThatArray(): void
    {
        // Evidence for the detour via query->all(): InputBag::get() throws for
        // a non-scalar value. If Symfony changes that, the detour may
        // stay — it is then just no longer needed.
        $request = Request::create('/kraeuter.html', 'GET', ['cq' => ['x']]);

        $this->expectException(BadRequestException::class);

        $request->query->get(FilterStateCodec::PARAMETER);
    }

    public function testTheSegmentIsReadThroughInputGetSoContaoCountsItAsUsed(): void
    {
        // Contao remembers every path segment as unused and answers 404
        // if nobody reads it via Input::get() (LegacyRouteParametersListener).
        // The request attribute carries the same value but does not mark it —
        // that is why it is in here too: whoever reads it instead of Input stands out.
        // And exactly ['auto_item'], without the third parameter true, which
        // explicitly skips the marking.
        $input = $this->createMock(Adapter::class);
        $input
            ->expects(self::once())
            ->method('__call')
            ->with('get', ['auto_item'])
            ->willReturn('schwedenbitter-250-ml')
        ;

        $request = Request::create('/kraeuter/schwedenbitter-250-ml.html');
        $request->attributes->set('auto_item', 'schwedenbitter-250-ml');

        $resolver = new ListingAddressResolver($this->framework($input), new FilterStateCodec(), $this->repository());

        self::assertTrue($resolver->resolve($this->page(12), $request)->valid);
    }

    public function testTheAddressIsReadOncePerPageWithinARequest(): void
    {
        // List, filter and page head ask the same thing. Only one should ask the
        // database — but per page, not once for all.
        $input = $this->createMock(Adapter::class);
        $input->expects(self::exactly(2))->method('__call')->willReturn('schwedenbitter-250-ml');

        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository
            ->expects(self::exactly(2))
            ->method('findByAlias')
            ->willReturnCallback(fn (int $page, string $alias): FacetPageRecord => $this->record($page, $alias, 'volume:250ml'))
        ;

        $resolver = new ListingAddressResolver($this->framework($input), new FilterStateCodec(), $repository);
        $request = Request::create('/kraeuter/schwedenbitter-250-ml.html');

        $first = $resolver->resolve($this->page(12), $request);

        self::assertSame($first, $resolver->resolve($this->page(12), $request));
        self::assertNotSame($first, $resolver->resolve($this->page(13), $request));
    }

    public function testResetForgetsWhatTheLastRequestAsked(): void
    {
        // If the kernel is reused, the address of the previous
        // request must not leak into the next one.
        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository
            ->expects(self::exactly(2))
            ->method('findByAlias')
            ->willReturnCallback(fn (int $page, string $alias): FacetPageRecord => $this->record($page, $alias, 'volume:250ml'))
        ;

        $resolver = $this->resolver('schwedenbitter-250-ml', $repository);
        $request = Request::create('/kraeuter/schwedenbitter-250-ml.html');

        $resolver->resolve($this->page(12), $request);
        $resolver->reset();
        $resolver->resolve($this->page(12), $request);
    }

    private function resolver(?string $autoItem, ?FacetPageRepositoryInterface $repository = null): ListingAddressResolver
    {
        $input = $this->createMock(Adapter::class);
        $input->method('__call')->with('get', ['auto_item'])->willReturn($autoItem);

        return new ListingAddressResolver($this->framework($input), new FilterStateCodec(), $repository ?? $this->repository());
    }

    /**
     * @param Adapter<Input> $input
     */
    private function framework(Adapter $input): ContaoFramework
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->with(Input::class)->willReturn($input);

        return $framework;
    }

    /**
     * Page 12 (Kräuter) has released "schwedenbitter-250-ml", page 13
     * (Tees) "tee-250-ml".
     */
    private function repository(): FacetPageRepositoryInterface
    {
        $released = [
            12 => ['schwedenbitter-250-ml' => 'volume:250ml'],
            13 => ['tee-250-ml' => 'volume:250ml'],
        ];

        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository->method('findByAlias')->willReturnCallback(
            fn (int $page, string $alias): ?FacetPageRecord => isset($released[$page][$alias])
                ? $this->record($page, $alias, $released[$page][$alias])
                : null,
        );

        return $repository;
    }

    private function page(int $id): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([['id', $id]]);

        return $page;
    }

    private function record(int $page, string $alias, string $state): FacetPageRecord
    {
        return new FacetPageRecord(1, $page, $alias, $state, 'Schwedenbitter 250 ml', '');
    }
}

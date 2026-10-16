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
        // /kraeuter.html — kein Segment, kein cq. Die Datenbank wird nicht
        // gefragt: Ohne Segment gibt es keine Landingpage nachzuschlagen.
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
        // Die Filter kommen aus der gespeicherten Kombination, nicht aus der
        // Adresse — /kraeuter/schwedenbitter-250-ml.html trägt kein cq.
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
        // Sortieren erzeugt keine neue Menge. Verließe der Besucher dabei die
        // Landingpage, verschwänden ihre Überschrift und ihr Einleitungstext.
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
        // Block Verfügbarkeit, V5: wie die Sortierung — eine Sicht nach Lager,
        // keine neue Kombination.
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
        // Consho verlinkt so eine Adresse nie: rot + 250 ml hat genau eine
        // Adresse, und das ist die Kategorie mit cq. Nähme die Landingpage cq
        // zusätzlich an, gäbe es für denselben Inhalt beliebig viele Adressen.
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
        // "tee-250-ml" ist unter Seite 13 freigegeben. Unter Seite 12 ist es
        // ein unbekanntes Segment — die Freigabe gehört der Seite.
        $address = $this->resolver('tee-250-ml')->resolve($this->page(12), Request::create('/kraeuter/tee-250-ml.html'));

        self::assertFalse($address->valid);
    }

    public function testAnArrayInTheFilterParameterIsIgnoredNotRefused(): void
    {
        // Spezifikation 10: FilterStateCodec::decode() wirft nie — der Weg
        // dorthin darf es auch nicht. ?cq[]=x ist eine leere Kategorie, keine
        // 400.
        $request = Request::create('/kraeuter.html', 'GET', ['cq' => ['x']]);

        $address = $this->resolver(null)->resolve($this->page(12), $request);

        self::assertTrue($address->valid);
        self::assertTrue($address->state->isEmpty());
    }

    public function testAParameterThatDecodesToNothingIsTheBareCategory(): void
    {
        // Übergabe aus Task 4, entschieden in Task 7: ?cq=Foo ist wie gar kein
        // cq — 200, Canonical ohne Parameter, keine Filterlinks mit Rest.
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
        // Dieselbe Entscheidung für den Sortierschlüssel: Die Liste sortiert
        // bei sort_by:gibtsnicht nach ihrer Vorgabe. Bliebe der Wert im Zustand,
        // verlöre der Canonical die Seitenzahl, und Filterlinks trügen den
        // Müll weiter.
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
        // Beleg für den Umweg über query->all(): InputBag::get() wirft bei
        // einem nicht-skalaren Wert. Ändert Symfony das, darf der Umweg
        // bleiben — er ist dann nur nicht mehr nötig.
        $request = Request::create('/kraeuter.html', 'GET', ['cq' => ['x']]);

        $this->expectException(BadRequestException::class);

        $request->query->get(FilterStateCodec::PARAMETER);
    }

    public function testTheSegmentIsReadThroughInputGetSoContaoCountsItAsUsed(): void
    {
        // Contao merkt sich jedes Pfadsegment als unbenutzt und antwortet 404,
        // wenn es niemand über Input::get() liest (LegacyRouteParametersListener).
        // Das Request-Attribut trägt denselben Wert, markiert ihn aber nicht —
        // deshalb steht es hier mit drin: Wer es liest statt Input, fällt auf.
        // Und genau ['auto_item'], ohne den dritten Parameter true, der das
        // Markieren ausdrücklich unterlässt.
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
        // Liste, Filter und Seitenkopf fragen dasselbe. Nur einer soll die
        // Datenbank fragen — aber je Seite, nicht einmal für alle.
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
        // Wird der Kernel wiederverwendet, darf die Adresse der vorigen
        // Anfrage nicht in die nächste durchsickern.
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
     * Seite 12 (Kräuter) hat "schwedenbitter-250-ml" freigegeben, Seite 13
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

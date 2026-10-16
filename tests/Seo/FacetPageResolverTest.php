<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRecord;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\FacetPageResolver;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Seo\UrlClass;

class FacetPageResolverTest extends TestCase
{
    public function testAnEmptyStateIsTheCategoryItself(): void
    {
        $resolver = $this->resolverKnowing([]);

        self::assertSame(UrlClass::Category, $resolver->classify(12, new FilterState()));
    }

    public function testAReleasedCombinationIsALandingPage(): void
    {
        $resolver = $this->resolverKnowing(['volume:250ml' => 'schwedenbitter-250-ml']);

        self::assertSame(
            UrlClass::FacetPage,
            $resolver->classify(12, new FilterState(['volume' => ['250ml']])),
        );
    }

    public function testAnUnreleasedCombinationStaysAParameter(): void
    {
        $resolver = $this->resolverKnowing(['volume:250ml' => 'schwedenbitter-250-ml']);

        self::assertSame(
            UrlClass::Parameter,
            $resolver->classify(12, new FilterState(['volume' => ['750ml']])),
        );
    }

    public function testTheLookupUsesTheNormalisedStateNotTheClickOrder(): void
    {
        // Freigegeben als "colour:red;volume:250ml". Wer zuerst das Volumen
        // anklickt, muss auf derselben Landingpage landen, nicht auf einer
        // Parameter-Adresse.
        $resolver = $this->resolverKnowing(['colour:red;volume:250ml' => 'rote-farben-250-ml']);

        self::assertSame(
            UrlClass::FacetPage,
            $resolver->classify(12, new FilterState(['volume' => ['250ml'], 'colour' => ['red']])),
        );
    }

    public function testASortOrderIsNeverALandingPage(): void
    {
        // Sortieren liefert dieselben Produkte in anderer Reihenfolge — immer
        // Duplicate Content, nie eine eigene indexierte Seite wert.
        $resolver = $this->resolverKnowing(['volume:250ml' => 'schwedenbitter-250-ml']);

        self::assertSame(
            UrlClass::Parameter,
            $resolver->classify(12, new FilterState(['volume' => ['250ml']], 'price_asc')),
        );
    }

    public function testNurVerfuegbareIstNieEineLandingpage(): void
    {
        // Block Verfügbarkeit, V5: eine Sicht nach Lager — wie die Sortierung
        // ein ausgeschlossener Parameter, auch über einer freigegebenen
        // Kombination, und nie die Kategorie.
        $resolver = $this->resolverKnowing(['volume:250ml' => 'schwedenbitter-250-ml']);

        self::assertSame(UrlClass::Parameter, $resolver->classify(12, new FilterState(['volume' => ['250ml']], '', true)));
        self::assertSame(UrlClass::Parameter, $resolver->classify(12, new FilterState([], '', true)));
    }

    public function testASortOrderOnItsOwnIsNotTheCategoryEither(): void
    {
        // Unsortiert und sortiert zeigen dieselben Produkte. Nur die schlichte
        // Liste ist die Kategorie; die andere ist ein ausgeschlossener Parameter.
        $resolver = $this->resolverKnowing([]);

        self::assertSame(UrlClass::Parameter, $resolver->classify(12, new FilterState([], 'price_asc')));
    }

    public function testTheReleaseBelongsToOnePage(): void
    {
        // "250 ml" unter "Kräuterbitter" und "250 ml" unter "Tees" sind zwei
        // verschiedene Mengen. Wer mit der falschen Seite fragt, darf die
        // Freigabe der anderen nicht finden.
        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository
            ->method('findByState')
            ->willReturnCallback(
                fn (int $page, string $state): ?FacetPageRecord => 12 === $page && 'volume:250ml' === $state
                    ? $this->record($page, $state, 'schwedenbitter-250-ml')
                    : null,
            )
        ;

        $resolver = new FacetPageResolver($repository, new FilterStateCodec());
        $state = new FilterState(['volume' => ['250ml']]);

        self::assertSame(UrlClass::FacetPage, $resolver->classify(12, $state));
        self::assertSame(UrlClass::Parameter, $resolver->classify(13, $state));
    }

    public function testTheAliasOfAReleasedCombinationIsAvailable(): void
    {
        $resolver = $this->resolverKnowing(['volume:250ml' => 'schwedenbitter-250-ml']);

        self::assertSame(
            'schwedenbitter-250-ml',
            $resolver->aliasFor(12, new FilterState(['volume' => ['250ml']])),
        );
    }

    public function testThereIsNoAliasForAnUnreleasedCombination(): void
    {
        $resolver = $this->resolverKnowing([]);

        self::assertNull($resolver->aliasFor(12, new FilterState(['volume' => ['250ml']])));
    }

    public function testAnEmptyStateIsNotLookedUpAtAll(): void
    {
        // Die Kategorie hat schon eine Adresse. Die Datenbank danach zu fragen,
        // wäre eine Abfrage je Listenaufruf für eine Antwort, die sich nie ändert.
        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository->expects(self::never())->method('findByState');

        $resolver = new FacetPageResolver($repository, new FilterStateCodec());

        self::assertNull($resolver->aliasFor(12, new FilterState()));
        self::assertNull($resolver->landingFor(12, new FilterState()));
        self::assertNull($resolver->landingFor(12, new FilterState([], 'price_asc')));
    }

    public function testASortedViewOfAReleasedCombinationStillHasItsLandingPage(): void
    {
        // Keine eigene Adresse (aliasFor null), aber derselbe Inhalt — Titel und
        // Canonical der Landingpage gelten weiter.
        $resolver = $this->resolverKnowing(['volume:250ml' => 'schwedenbitter-250-ml']);
        $state = new FilterState(['volume' => ['250ml']], 'price_asc');

        self::assertNull($resolver->aliasFor(12, $state));
        self::assertSame('schwedenbitter-250-ml', $resolver->landingFor(12, $state)?->alias);
    }

    public function testTheLandingPageIsLookedUpWithoutTheSortOrder(): void
    {
        // Gespeichert ist die Kombination ohne Sortierung (FacetStateListener
        // lehnt eine Sortierung ab). Fragte landingFor() mit "…;sort_by:price_asc",
        // fände es nie etwas.
        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('findByState')
            ->with(12, 'volume:250ml')
            ->willReturn(null)
        ;

        (new FacetPageResolver($repository, new FilterStateCodec()))
            ->landingFor(12, new FilterState(['volume' => ['250ml']], 'price_asc'))
        ;
    }

    /**
     * @param array<string, string> $released filterState => alias
     */
    private function resolverKnowing(array $released): FacetPageResolver
    {
        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository->method('findByState')->willReturnCallback(
            fn (int $page, string $state): ?FacetPageRecord => isset($released[$state])
                ? $this->record($page, $state, $released[$state])
                : null,
        );

        return new FacetPageResolver($repository, new FilterStateCodec());
    }

    private function record(int $page, string $state, string $alias): FacetPageRecord
    {
        return new FacetPageRecord(1, $page, $alias, $state, 'Titel', '');
    }
}

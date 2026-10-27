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
        // Released as "colour:red;volume:250ml". Whoever clicks the volume
        // first must land on the same landing page, not on a
        // parameter address.
        $resolver = $this->resolverKnowing(['colour:red;volume:250ml' => 'rote-farben-250-ml']);

        self::assertSame(
            UrlClass::FacetPage,
            $resolver->classify(12, new FilterState(['volume' => ['250ml'], 'colour' => ['red']])),
        );
    }

    public function testASortOrderIsNeverALandingPage(): void
    {
        // Sorting returns the same products in a different order — always
        // duplicate content, never worth an indexed page of its own.
        $resolver = $this->resolverKnowing(['volume:250ml' => 'schwedenbitter-250-ml']);

        self::assertSame(
            UrlClass::Parameter,
            $resolver->classify(12, new FilterState(['volume' => ['250ml']], 'price_asc')),
        );
    }

    public function testNurVerfuegbareIstNieEineLandingpage(): void
    {
        // Block Verfügbarkeit (availability), V5: a view by stock — like sorting
        // an excluded parameter, also on top of a released
        // combination, and never the category.
        $resolver = $this->resolverKnowing(['volume:250ml' => 'schwedenbitter-250-ml']);

        self::assertSame(UrlClass::Parameter, $resolver->classify(12, new FilterState(['volume' => ['250ml']], '', true)));
        self::assertSame(UrlClass::Parameter, $resolver->classify(12, new FilterState([], '', true)));
    }

    public function testASortOrderOnItsOwnIsNotTheCategoryEither(): void
    {
        // Unsorted and sorted show the same products. Only the plain
        // list is the category; the other one is an excluded parameter.
        $resolver = $this->resolverKnowing([]);

        self::assertSame(UrlClass::Parameter, $resolver->classify(12, new FilterState([], 'price_asc')));
    }

    public function testTheReleaseBelongsToOnePage(): void
    {
        // "250 ml" under "Kräuterbitter" and "250 ml" under "Tees" are two
        // different sets. Whoever asks with the wrong page must not find the
        // release of the other one.
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
        // The category already has an address. Asking the database for it
        // would be one query per list request for an answer that never changes.
        $repository = $this->createMock(FacetPageRepositoryInterface::class);
        $repository->expects(self::never())->method('findByState');

        $resolver = new FacetPageResolver($repository, new FilterStateCodec());

        self::assertNull($resolver->aliasFor(12, new FilterState()));
        self::assertNull($resolver->landingFor(12, new FilterState()));
        self::assertNull($resolver->landingFor(12, new FilterState([], 'price_asc')));
    }

    public function testASortedViewOfAReleasedCombinationStillHasItsLandingPage(): void
    {
        // No address of its own (aliasFor null), but the same content — title and
        // canonical of the landing page still apply.
        $resolver = $this->resolverKnowing(['volume:250ml' => 'schwedenbitter-250-ml']);
        $state = new FilterState(['volume' => ['250ml']], 'price_asc');

        self::assertNull($resolver->aliasFor(12, $state));
        self::assertSame('schwedenbitter-250-ml', $resolver->landingFor(12, $state)?->alias);
    }

    public function testTheLandingPageIsLookedUpWithoutTheSortOrder(): void
    {
        // The combination is stored without sorting (FacetStateListener
        // rejects a sort order). If landingFor() asked with "…;sort_by:price_asc",
        // it would never find anything.
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTaxClassesInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRow;
use Webwerkwien\ContaoConshoBundle\Pricing\ProjectionWriterInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

class ListPriceProjectorTest extends TestCase
{
    use TaxFixture;

    public function testProjectsTheListPriceForEveryMarket(): void
    {
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findProductIdsWithPrices')->willReturn([1]);
        $repository->method('findCurrencies')->willReturn(['EUR', 'CHF']);
        $repository->method('findCandidates')->willReturnCallback(
            static fn (int $pid, string $currency): array => [new PriceRow('EUR' === $currency ? '10.0000' : '9.5000', $currency, null, '1.0000')],
        );

        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([]);
        $writer->expects(self::exactly(2))->method('write');

        self::assertSame(2, $this->projector($repository, $writer, markets: [['EUR', 'AT'], ['CHF', 'CH']], rates: ['AT' => '20.0000', 'CH' => '8.1000'])->rebuild());
    }

    public function testProjectsNetAndTheGrossOfTheHomeCountry(): void
    {
        // Net is stored, the gross amount comes from the home country's rate (C25).
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([]);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');

        $this->projector($this->oneEuroPrice(), $writer)->rebuild();
    }

    public function testTwoEuroShopsInDifferentCountriesGetTwoRows(): void
    {
        // The same net price, two gross amounts: 20 % in Austria, 19 % in Germany.
        $written = [];
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([]);
        $writer->method('write')->willReturnCallback(
            static function (int $pid, string $currency, string $country, string $net, string $gross) use (&$written): void {
                $written[] = [$country, $gross];
            },
        );

        $this->projector($this->oneEuroPrice(), $writer, markets: [['EUR', 'AT'], ['EUR', 'DE']], rates: ['AT' => '20.0000', 'DE' => '19.0000'])->rebuild();

        self::assertSame([['AT', '12.0000'], ['DE', '11.9000']], $written);
    }

    public function testSkipsCombinationsThatHaveNoPrice(): void
    {
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findProductIdsWithPrices')->willReturn([1]);
        $repository->method('findCurrencies')->willReturn(['EUR', 'CHF']);
        $repository->method('findCandidates')->willReturnCallback(
            static fn (int $pid, string $currency): array => 'EUR' === $currency ? [new PriceRow('10.0000', 'EUR', null, '1.0000')] : [],
        );

        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([]);
        $writer->expects(self::once())->method('write');

        self::assertSame(1, $this->projector($repository, $writer, markets: [['EUR', 'AT'], ['CHF', 'CH']], rates: ['AT' => '20.0000', 'CH' => '8.1000'])->rebuild());
    }

    public function testAMarketWithoutARateLosesItsRowInsteadOfKeepingAWrongGross(): void
    {
        // Italy has no rate for the class: no row, the product is not priced
        // there — and the start page reports the missing rate.
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([
            ['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false],
            ['currency' => 'EUR', 'country' => 'IT', 'net' => '10.0000', 'gross' => '12.2000', 'subscriptionRule' => 0, 'alsoSubscription' => false],
        ]);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');
        $writer->expects(self::once())->method('delete')->with(1, 'EUR', 'IT');

        $this->projector($this->oneEuroPrice(), $writer, markets: [['EUR', 'AT'], ['EUR', 'IT']])->rebuild();
    }

    public function testACurrencyNoShopSellsInIsNotProjected(): void
    {
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findProductIdsWithPrices')->willReturn([1]);
        $repository->method('findCurrencies')->willReturn(['EUR', 'CHF']);
        $repository->method('findCandidates')->willReturnCallback(
            static fn (int $pid, string $currency): array => [new PriceRow('10.0000', $currency, null, '1.0000')],
        );

        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([]);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');

        $this->projector($repository, $writer)->rebuild();
    }

    public function testVerifyReportsDeviationsWithoutChangingAnything(): void
    {
        // A verify that repairs is not a verify — it hides the very cause one
        // is looking for. Hence expects(never())->method('write').
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '99.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);
        $writer->expects(self::never())->method('write');

        self::assertSame(
            [[
                'productId' => 1,
                'currency' => 'EUR',
                'country' => 'AT',
                'storedNet' => '10.0000',
                'storedGross' => '99.0000',
                'expectedNet' => '10.0000',
                'expectedGross' => '12.0000',
            ]],
            $this->projector($this->oneEuroPrice(), $writer)->verify(),
        );
    }

    public function testVerifyReportsAMissingRow(): void
    {
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([]);

        $deviations = $this->projector($this->oneEuroPrice(), $writer)->verify();

        self::assertCount(1, $deviations);
        self::assertNull($deviations[0]['storedNet']);
    }

    public function testVerifyReportsNothingWhenEverythingMatches(): void
    {
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);

        self::assertSame([], $this->projector($this->oneEuroPrice(), $writer)->verify());
    }

    // ── Deleting what no longer has a price ──────────────────────────────

    public function testRebuildDeletesTheRowOfAProductWhosePricesAreGone(): void
    {
        // Product 2 no longer has a price row, but still a projection row.
        // Previously rebuild only wrote — the product stayed in the list with its old price.
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findProductIdsWithPrices')->willReturn([]);
        $repository->method('findCurrencies')->willReturn([]);
        $repository->method('findCandidates')->willReturn([]);

        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('findProductIds')->willReturn([2]);
        $writer->method('readAll')->with(2)->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);
        $writer->expects(self::never())->method('write');
        $writer->expects(self::once())->method('delete')->with(2, 'EUR', 'AT');

        self::assertSame(1, $this->projector($repository, $writer)->rebuild());
    }

    public function testRefreshDeletesOnlyTheMarketThatLostItsPrice(): void
    {
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findCurrencies')->willReturn(['EUR']);
        $repository->method('findCandidates')->willReturnCallback(
            static fn (int $pid, string $currency): array => 'EUR' === $currency ? [new PriceRow('10.0000', 'EUR', null, '1.0000')] : [],
        );

        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([
            ['currency' => 'CHF', 'country' => 'CH', 'net' => '9.0000', 'gross' => '11.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false],
            ['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false],
        ]);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');
        $writer->expects(self::once())->method('delete')->with(1, 'CHF', 'CH');

        // Changed by I-1: the EUR-AT row is stored and unchanged
        // (net 10.0000/gross 12.0000 as calculated) — it no longer counts,
        // only the deleted CHF-CH row does. Previously 2.
        self::assertSame(1, $this->projector($repository, $writer, markets: [['EUR', 'AT'], ['CHF', 'CH']], rates: ['AT' => '20.0000', 'CH' => '8.1000'])->refresh([1, 1]));
    }

    // ── I-1: refresh() counts only real changes ─────────────────────────

    public function testRefreshWritesAnUnchangedRowButDoesNotCountIt(): void
    {
        // The boundary cron calls refresh() every minute for a price that is due but
        // unchanged (I-1) — write() must run so that the tstamp
        // moves, but the count must stay 0, otherwise every minute would
        // invalidate without anything changing in the gross amount.
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');

        self::assertSame(0, $this->projector($this->oneEuroPrice(), $writer)->refresh([1]));
    }

    public function testRefreshComparesSqliteNumericFormAsDecimalsNotStrings(): void
    {
        // SQLite returns NUMERIC inconsistently — '12' instead of '12.0000'.
        // A string comparison would wrongly take that for a change.
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '10', 'gross' => '12', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');

        self::assertSame(0, $this->projector($this->oneEuroPrice(), $writer)->refresh([1]));
    }

    public function testRefreshCountsAStoredGrossThatReallyDiffers(): void
    {
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '99.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');

        self::assertSame(1, $this->projector($this->oneEuroPrice(), $writer)->refresh([1]));
    }

    public function testRefreshCountsAStoredNetThatDiffersWhileTheGrossIsTheSame(): void
    {
        // Both amounts must match — a differing net alone
        // is a change as well.
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '9.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');

        self::assertSame(1, $this->projector($this->oneEuroPrice(), $writer)->refresh([1]));
    }

    public function testVerifyReportsARowWithoutPriceWithoutChangingAnything(): void
    {
        // Exactly this case was invisible to verify: the set to check came only from
        // the price table, a row without a price was never up for checking.
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findProductIdsWithPrices')->willReturn([]);
        $repository->method('findCurrencies')->willReturn([]);
        $repository->method('findCandidates')->willReturn([]);

        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('findProductIds')->willReturn([2]);
        $writer->method('readAll')->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);
        $writer->expects(self::never())->method('write');
        $writer->expects(self::never())->method('delete');

        self::assertSame(
            [[
                'productId' => 2,
                'currency' => 'EUR',
                'country' => 'AT',
                'storedNet' => '10.0000',
                'storedGross' => '12.0000',
                'expectedNet' => null,
                'expectedGross' => null,
            ]],
            $this->projector($repository, $writer)->verify(),
        );
    }

    public function testVerifyReportsNothingForAMarketWithoutPriceAndWithoutRow(): void
    {
        // A market in which the article has no price, and no row for it:
        // nothing is missing, so verify reports nothing.
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findProductIdsWithPrices')->willReturn([1]);
        $repository->method('findCurrencies')->willReturn(['EUR', 'CHF']);
        $repository->method('findCandidates')->willReturnCallback(
            static fn (int $pid, string $currency): array => 'EUR' === $currency ? [new PriceRow('10.0000', 'EUR', null, '1.0000')] : [],
        );

        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);

        self::assertSame([], $this->projector($repository, $writer, markets: [['EUR', 'AT'], ['CHF', 'CH']], rates: ['AT' => '20.0000', 'CH' => '8.1000'])->verify());
    }

    // ── Inheritance (A-4b, E2) ────────────────────────────────────────────

    public function testAVariantWithoutPricesOfItsOwnIsProjectedWithTheMainProductsPrice(): void
    {
        $written = [];
        $writer = $this->collectingWriter($written);

        $this->projector($this->repositoryWith([1 => '12.0000']), $writer, [1 => 1, 10 => 1], rates: ['AT' => '0.0000'])->refresh([1]);

        self::assertSame([1 => '12.0000', 10 => '12.0000'], $written);
    }

    public function testAVariantWithAPriceOfItsOwnKeepsIt(): void
    {
        $written = [];
        $writer = $this->collectingWriter($written);

        $this->projector($this->repositoryWith([1 => '10.0000', 10 => '15.0000']), $writer, [1 => 1, 10 => 1], rates: ['AT' => '0.0000'])->refresh([1]);

        self::assertSame([1 => '10.0000', 10 => '15.0000'], $written);
    }

    public function testWhenTheMainProductLosesItsPriceAnInheritingVariantLosesItsRowToo(): void
    {
        // Only the main product is changed — the variant must still come along,
        // otherwise it would stay in the list with the inherited, deleted price.
        $deleted = [];
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);
        $writer->method('delete')->willReturnCallback(
            static function (int $pid) use (&$deleted): void {
                $deleted[] = $pid;
            },
        );

        $this->projector($this->repositoryWith([]), $writer, [1 => 1, 10 => 1])->refresh([1]);

        self::assertSame([1, 10], $deleted);
    }

    public function testADeletedProductLosesItsRows(): void
    {
        // The product is no longer in tl_consho_product — the tree does not know it.
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->with(5)->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);
        $writer->expects(self::once())->method('delete')->with(5, 'EUR', 'AT');

        $this->projector($this->repositoryWith([]), $writer, [])->refresh([5]);
    }

    // ── Reconciling markets (C25) ─────────────────────────────────────────

    public function testSyncMarketsBuildsOnceWithoutAStoredFingerprintAndThenDoesNothing(): void
    {
        // Without a stored fingerprint (e.g. after the migration)
        // syncMarkets() rebuilds exactly once — with one write() for EUR/AT; a
        // second call with unchanged markets writes nothing more.
        $stored = null;

        $writer = $this->statefulWriter($stored);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');
        self::assertTrue($this->projector($this->oneEuroPrice(), $writer)->syncMarkets());

        $writer = $this->statefulWriter($stored);
        $writer->expects(self::never())->method('write');
        self::assertFalse($this->projector($this->oneEuroPrice(), $writer)->syncMarkets());
    }

    public function testAChangedHomeCountryRebuilds(): void
    {
        // The shop moved from DE to AT: every row carries the gross amount of the wrong
        // country. First synchronise with DE (sets the fingerprint), then
        // with a projector for AT on the same stored state.
        $stored = null;

        $this->projector($this->oneEuroPrice(), $this->statefulWriter($stored), markets: [['EUR', 'DE']], rates: ['DE' => '19.0000'])->syncMarkets();

        $writer = $this->statefulWriter($stored);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');
        self::assertTrue($this->projector($this->oneEuroPrice(), $writer)->syncMarkets());
    }

    public function testTheOrderOfMarketsDoesNotMatter(): void
    {
        // M-1: [EUR,AT],[EUR,DE] and [EUR,DE],[EUR,AT] are the same fingerprint.
        $stored = null;

        $this->projector($this->oneEuroPrice(), $this->statefulWriter($stored), markets: [['EUR', 'AT'], ['EUR', 'DE']], rates: ['AT' => '20.0000', 'DE' => '19.0000'])->syncMarkets();

        $writer = $this->statefulWriter($stored);
        $writer->expects(self::never())->method('write');
        $writer->expects(self::never())->method('delete');
        self::assertFalse($this->projector($this->oneEuroPrice(), $writer, markets: [['EUR', 'DE'], ['EUR', 'AT']], rates: ['AT' => '20.0000', 'DE' => '19.0000'])->syncMarkets());
    }

    public function testAMarketWithoutASinglePriceableArticleBuildsOnlyOnce(): void
    {
        // I-2: IT has no rate for the class, so never a written
        // row — the fingerprint still comes from the shop markets, not
        // from the rows, and does not change on the second call.
        $stored = null;

        $writer = $this->statefulWriter($stored);
        $writer->expects(self::never())->method('write');
        self::assertTrue($this->projector($this->oneEuroPrice(), $writer, markets: [['EUR', 'IT']], rates: ['AT' => '20.0000'])->syncMarkets());

        $writer = $this->statefulWriter($stored);
        $writer->expects(self::never())->method('write');
        self::assertFalse($this->projector($this->oneEuroPrice(), $writer, markets: [['EUR', 'IT']], rates: ['AT' => '20.0000'])->syncMarkets());
    }

    public function testRebuildLeavesTheFingerprintBehind(): void
    {
        // A rebuild() (e.g. consho:price:rebuild) sets the fingerprint
        // as well, without syncMarkets() having run.
        $stored = null;

        $this->projector($this->oneEuroPrice(), $this->statefulWriter($stored))->rebuild();

        $writer = $this->statefulWriter($stored);
        $writer->expects(self::never())->method('write');
        self::assertFalse($this->projector($this->oneEuroPrice(), $writer)->syncMarkets());
    }

    public function testTheFirstPriceInANewCurrencyDoesNotRebuild(): void
    {
        // The fingerprint comes from the shops' markets, not from the
        // priced ones. When the first CHF price is saved,
        // PriceRefreshTriggerListener updates this one product — a rebuild of the
        // whole catalogue at the next save of a page would be wasted.
        $stored = null;
        $markets = [['EUR', 'AT'], ['CHF', 'CH']];
        $rates = ['AT' => '20.0000', 'CH' => '8.1000'];

        $this->projector($this->oneEuroPrice(), $this->statefulWriter($stored), markets: $markets, rates: $rates)->syncMarkets();

        $withFrancs = $this->createMock(PriceRepositoryInterface::class);
        $withFrancs->method('findProductIdsWithPrices')->willReturn([1]);
        $withFrancs->method('findCurrencies')->willReturn(['EUR', 'CHF']);
        $withFrancs->method('findCandidates')->willReturnCallback(
            static fn (int $pid, string $currency): array => [new PriceRow('10.0000', $currency, null, '1.0000')],
        );

        $writer = $this->statefulWriter($stored);
        $writer->expects(self::never())->method('write');
        self::assertFalse($this->projector($withFrancs, $writer, markets: $markets, rates: $rates)->syncMarkets());
    }

    /**
     * A stateful writer mock: the stored fingerprint lives
     * in $stored, outside the mock — passed by reference to a closure
     * that returns or sets it. A new call of this method returns a
     * new mock (for its own write()/delete() expectations) that builds on the same
     * stored state — like two requests against the same database.
     */
    private function statefulWriter(int|null &$stored): MockObject&ProjectionWriterInterface
    {
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([]);
        $writer->method('marketsFingerprint')->willReturnCallback(static function () use (&$stored): int|null {
            return $stored;
        });
        $writer->method('storeMarketsFingerprint')->willReturnCallback(static function (int $fingerprint) use (&$stored): void {
            $stored = $fingerprint;
        });

        return $writer;
    }

    /**
     * @param array<int, int>             $articles article id => main product id, as ArticleTreeInterface returns it
     * @param list<array{string, string}> $markets  currency, country
     * @param array<string, string>       $rates    ISO code => rate, for every tax class
     */
    private function projector(
        PriceRepositoryInterface $repository,
        ProjectionWriterInterface $writer,
        array $articles = [],
        array $markets = [['EUR', 'AT']],
        array $rates = ['AT' => '20.0000'],
    ): ListPriceProjector {
        $tree = $this->createMock(ArticleTreeInterface::class);
        $tree->method('articlesOf')->willReturn($articles);

        $marketRepository = $this->createMock(MarketRepositoryInterface::class);
        $marketRepository->method('findMarkets')->willReturn(array_map(static fn (array $m): Market => new Market($m[0], $m[1]), $markets));

        $taxClasses = $this->createMock(ArticleTaxClassesInterface::class);
        $taxClasses->method('taxClassOf')->willReturn(1);

        // PriceCalculator is final — so the real one, with the same repository.
        return new ListPriceProjector(
            $repository,
            new ArticlePriceResolver(new PriceCalculator($repository), $repository, new BasePriceCalculator(), $this->grossCalculator($rates), $taxClasses),
            $writer,
            $tree,
            $marketRepository,
        );
    }

    private function oneEuroPrice(): PriceRepositoryInterface
    {
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findProductIdsWithPrices')->willReturn([1]);
        $repository->method('findCurrencies')->willReturn(['EUR']);
        $repository->method('findCandidates')->willReturn([new PriceRow('10.0000', 'EUR', null, '1.0000')]);

        return $repository;
    }

    /**
     * @param array<int, string> $netByProduct one EUR list price row per product id
     */
    private function repositoryWith(array $netByProduct): PriceRepositoryInterface
    {
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findCurrencies')->willReturn(['EUR']);
        $repository->method('findCandidates')->willReturnCallback(
            static fn (int $pid): array => isset($netByProduct[$pid]) ? [new PriceRow($netByProduct[$pid], 'EUR', null, '1.0000')] : [],
        );

        return $repository;
    }

    /**
     * @param array<int, string> $written product id => gross, filled by reference
     */
    private function collectingWriter(array &$written): ProjectionWriterInterface
    {
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([]);
        $writer->method('write')->willReturnCallback(
            static function (int $pid, string $currency, string $country, string $net, string $gross) use (&$written): void {
                $written[$pid] = $gross;
            },
        );

        return $writer;
    }
}

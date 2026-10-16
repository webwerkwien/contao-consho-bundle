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
        // Netto ist gespeichert, das Brutto entsteht mit dem Satz des Heimatlands (C25).
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([]);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');

        $this->projector($this->oneEuroPrice(), $writer)->rebuild();
    }

    public function testTwoEuroShopsInDifferentCountriesGetTwoRows(): void
    {
        // Derselbe Nettopreis, zwei Brutto: 20 % in Österreich, 19 % in Deutschland.
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
        // Italien hat keinen Satz für die Klasse: keine Zeile, das Produkt ist dort
        // nicht bepreist — und die Startseite meldet den fehlenden Satz.
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

    // ── Löschen, was keinen Preis mehr hat ──────────────────────────────

    public function testRebuildDeletesTheRowOfAProductWhosePricesAreGone(): void
    {
        // Produkt 2 hat keine Preiszeile mehr, aber noch eine Projektionszeile.
        // Vorher hat rebuild nur geschrieben — das Produkt blieb mit altem Preis in der Liste.
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

        // Geändert durch I-1: die EUR-AT-Zeile ist gespeichert und unverändert
        // (net 10.0000/gross 12.0000 wie berechnet) — sie zählt nicht mehr mit,
        // nur die gelöschte CHF-CH-Zeile. Vorher 2.
        self::assertSame(1, $this->projector($repository, $writer, markets: [['EUR', 'AT'], ['CHF', 'CH']], rates: ['AT' => '20.0000', 'CH' => '8.1000'])->refresh([1, 1]));
    }

    // ── I-1: refresh() zählt nur echte Änderungen ───────────────────────

    public function testRefreshWritesAnUnchangedRowButDoesNotCountIt(): void
    {
        // Der Grenzen-Cron ruft refresh() für einen fälligen, aber unveränderten
        // Preis jede Minute auf (I-1) — write() muss laufen, damit der tstamp
        // sich bewegt, die Zählung aber 0 bleiben, sonst würde jede Minute
        // invalidiert, ohne dass sich am Brutto etwas ändert.
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');

        self::assertSame(0, $this->projector($this->oneEuroPrice(), $writer)->refresh([1]));
    }

    public function testRefreshComparesSqliteNumericFormAsDecimalsNotStrings(): void
    {
        // SQLite liefert NUMERIC uneinheitlich zurück — '12' statt '12.0000'.
        // Ein Stringvergleich hielte das fälschlich für eine Änderung.
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
        // Beide Beträge müssen übereinstimmen — ein abweichendes Netto allein
        // ist ebenfalls eine Änderung.
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '9.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');

        self::assertSame(1, $this->projector($this->oneEuroPrice(), $writer)->refresh([1]));
    }

    public function testVerifyReportsARowWithoutPriceWithoutChangingAnything(): void
    {
        // Genau dieser Fall war für verify unsichtbar: Die Prüfmenge kam nur aus
        // der Preistabelle, eine Zeile ohne Preis stand nie zur Prüfung.
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
        // Ein Markt, in dem der Artikel keinen Preis hat, und keine Zeile dazu:
        // Es fehlt nichts, also meldet verify nichts.
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

    // ── Vererbung (A-4b, E2) ────────────────────────────────────────────

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
        // Geändert wird nur das Hauptprodukt — die Variante muss trotzdem mit,
        // sonst stünde sie mit dem geerbten, gelöschten Preis in der Liste.
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
        // Das Produkt steht nicht mehr in tl_consho_product — der Baum kennt es nicht.
        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->with(5)->willReturn([['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000', 'subscriptionRule' => 0, 'alsoSubscription' => false]]);
        $writer->expects(self::once())->method('delete')->with(5, 'EUR', 'AT');

        $this->projector($this->repositoryWith([]), $writer, [])->refresh([5]);
    }

    // ── Märkte abgleichen (C25) ─────────────────────────────────────────

    public function testSyncMarketsBuildsOnceWithoutAStoredFingerprintAndThenDoesNothing(): void
    {
        // Ohne gespeicherten Fingerabdruck (z. B. nach der Migration) baut
        // syncMarkets() genau einmal neu — mit einem write() für EUR/AT; ein
        // zweiter Aufruf mit unveränderten Märkten schreibt nichts mehr.
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
        // Der Shop zog von DE nach AT: Jede Zeile trägt das Brutto des falschen
        // Landes. Erst mit DE synchronisieren (setzt den Fingerabdruck), dann
        // mit einem Projektor über AT auf demselben gespeicherten Stand.
        $stored = null;

        $this->projector($this->oneEuroPrice(), $this->statefulWriter($stored), markets: [['EUR', 'DE']], rates: ['DE' => '19.0000'])->syncMarkets();

        $writer = $this->statefulWriter($stored);
        $writer->expects(self::once())->method('write')->with(1, 'EUR', 'AT', '10.0000', '12.0000');
        self::assertTrue($this->projector($this->oneEuroPrice(), $writer)->syncMarkets());
    }

    public function testTheOrderOfMarketsDoesNotMatter(): void
    {
        // M-1: [EUR,AT],[EUR,DE] und [EUR,DE],[EUR,AT] sind derselbe Fingerabdruck.
        $stored = null;

        $this->projector($this->oneEuroPrice(), $this->statefulWriter($stored), markets: [['EUR', 'AT'], ['EUR', 'DE']], rates: ['AT' => '20.0000', 'DE' => '19.0000'])->syncMarkets();

        $writer = $this->statefulWriter($stored);
        $writer->expects(self::never())->method('write');
        $writer->expects(self::never())->method('delete');
        self::assertFalse($this->projector($this->oneEuroPrice(), $writer, markets: [['EUR', 'DE'], ['EUR', 'AT']], rates: ['AT' => '20.0000', 'DE' => '19.0000'])->syncMarkets());
    }

    public function testAMarketWithoutASinglePriceableArticleBuildsOnlyOnce(): void
    {
        // I-2: IT hat keinen Satz für die Klasse, also nie eine geschriebene
        // Zeile — der Fingerabdruck kommt trotzdem aus den Shop-Märkten, nicht
        // aus den Zeilen, und ändert sich beim zweiten Aufruf nicht.
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
        // Ein rebuild() (z. B. consho:price:rebuild) setzt den Fingerabdruck
        // mit, ohne dass syncMarkets() gelaufen ist.
        $stored = null;

        $this->projector($this->oneEuroPrice(), $this->statefulWriter($stored))->rebuild();

        $writer = $this->statefulWriter($stored);
        $writer->expects(self::never())->method('write');
        self::assertFalse($this->projector($this->oneEuroPrice(), $writer)->syncMarkets());
    }

    public function testTheFirstPriceInANewCurrencyDoesNotRebuild(): void
    {
        // Der Fingerabdruck kommt aus den Märkten der Shops, nicht aus den
        // bepreisten. Wird der erste CHF-Preis gespeichert, zieht
        // PriceRefreshTriggerListener dieses eine Produkt nach — ein Neubau des
        // ganzen Katalogs beim nächsten Speichern einer Seite wäre verschwendet.
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
     * Ein zustandsbehafteter Writer-Mock: der gespeicherte Fingerabdruck lebt
     * in $stored, außerhalb des Mocks — per Referenz an eine Closure gereicht,
     * die ihn liefert bzw. setzt. Ein neuer Aufruf dieser Methode liefert einen
     * neuen Mock (für eigene write()-/delete()-Erwartungen), der auf demselben
     * gespeicherten Stand aufsetzt — wie zwei Anfragen an derselben Datenbank.
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

        // PriceCalculator ist final — also echt, mit demselben Repository.
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Contao\CoreBundle\Intl\Countries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTaxClassesInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRow;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\ResolvedPrice;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

class ArticlePriceResolverTest extends TestCase
{
    use TaxFixture;

    private const NOW = 1_757_000_000;

    private const NO_UNITS = ['unit' => '', 'purchaseUnit' => '0.0000', 'referenceUnit' => '0.0000'];

    /**
     * Each test puts its rows from tl_consho_price here.
     *
     * @var list<array{pid: int, net: string, currency?: string, group?: int|null, minQuantity?: string, validFrom?: int|null, validTo?: int|null, rule?: int}>
     */
    private array $rows = [];

    /**
     * Which customer groups the resolver asked the repository for.
     *
     * @var list<list<int>>
     */
    private array $groupsAsked = [];

    public function testUsesTheListPriceOfAProductWithoutVariants(): void
    {
        $this->rows = [['pid' => 1, 'net' => '13.0000']];

        $price = $this->resolve(1, 1);

        self::assertNotNull($price);
        self::assertSame('13.0000', $price->gross);
        self::assertSame(PriceSource::List, $price->price->source);
        self::assertSame(1, $price->ownerId);
        self::assertSame([], $price->scale);
    }

    public function testListsEveryThresholdWhenTheArticleHasTwoOrMore(): void
    {
        $this->rows = [
            ['pid' => 1, 'net' => '13.0000'],
            ['pid' => 1, 'net' => '10.8000', 'minQuantity' => '10.0000'],
        ];

        $price = $this->resolve(1, 1);

        self::assertNotNull($price);
        self::assertSame('13.0000', $price->gross);
        self::assertSame([['1.0000', '13.0000'], ['10.0000', '10.8000']], $this->stepsOf($price));
    }

    public function testASingleThresholdIsNoScale(): void
    {
        // A table with one row is not a scale.
        $this->rows = [['pid' => 1, 'net' => '13.0000']];

        self::assertSame([], $this->resolve(1, 1)?->scale);
    }

    public function testAVariantWithoutOwnRowsInheritsTheWholePriceSetOfTheMainProduct(): void
    {
        // Empty means "as the main product": the whole set, scale prices
        // included.
        $this->rows = [
            ['pid' => 1, 'net' => '13.0000'],
            ['pid' => 1, 'net' => '10.8000', 'minQuantity' => '10.0000'],
        ];

        $price = $this->resolve(6, 1);

        self::assertNotNull($price);
        self::assertSame('13.0000', $price->gross);
        self::assertSame(1, $price->ownerId);
        self::assertSame([['1.0000', '13.0000'], ['10.0000', '10.8000']], $this->stepsOf($price));
    }

    public function testAVariantWithAnOwnRowTakesNothingFromTheMainProduct(): void
    {
        // The main product's scale must not leak into the variant.
        $this->rows = [
            ['pid' => 1, 'net' => '13.0000'],
            ['pid' => 1, 'net' => '10.8000', 'minQuantity' => '10.0000'],
            ['pid' => 6, 'net' => '29.9000'],
        ];

        $price = $this->resolve(6, 1);

        self::assertNotNull($price);
        self::assertSame('29.9000', $price->gross);
        self::assertSame(6, $price->ownerId);
        self::assertSame([], $price->scale);
    }

    public function testOhneGruppenBleibtEsDerListenpreis(): void
    {
        // The guest, and until C-4 everyone: without groups the resolver asks
        // for none either, and the group row does not exist for them.
        $this->rows = [
            ['pid' => 1, 'net' => '13.0000'],
            ['pid' => 1, 'net' => '9.6000', 'group' => 5],
        ];

        $price = $this->resolve(1, 1);

        self::assertNotNull($price);
        self::assertSame('13.0000', $price->gross);
        self::assertSame(PriceSource::List, $price->price->source);
        self::assertNotEmpty($this->groupsAsked);
        self::assertSame([[]], array_values(array_unique($this->groupsAsked, SORT_REGULAR)));
    }

    public function testOverlappingRowsAtOneThresholdAppearOnceWithTheCalculatorsValue(): void
    {
        // Two rows of equal rank from 1 — the calculator takes the one delivered
        // first. The scale must give the same answer as the price, otherwise two
        // numbers on the page contradict each other.
        $this->rows = [
            ['pid' => 1, 'net' => '13.0000', 'validFrom' => self::NOW - 100],
            ['pid' => 1, 'net' => '12.0000', 'minQuantity' => '1', 'validTo' => self::NOW + 100],
            ['pid' => 1, 'net' => '10.8000', 'minQuantity' => '10.0000'],
        ];

        $price = $this->resolve(1, 1);

        self::assertNotNull($price);
        self::assertCount(2, $price->scale);
        self::assertSame($price->gross, $price->scale[0]->gross);
        self::assertSame([['1.0000', '13.0000'], ['10.0000', '10.8000']], $this->stepsOf($price));
    }

    public function testReturnsNullWithoutAPriceInTheCurrency(): void
    {
        // No exception: the page then shows no price.
        $this->rows = [['pid' => 1, 'net' => '13.0000', 'currency' => 'CHF']];

        self::assertNull($this->resolve(1, 1));
    }

    public function testReturnsNullWhenAVariantAndItsMainProductHaveNoPrice(): void
    {
        self::assertNull($this->resolve(6, 1));
    }

    public function testCalculatesTheBasePriceFromTheGrossPrice(): void
    {
        // Net 10.8333 at 20 % → gross 13.00 for 0.25 l → 52.00 per litre. With
        // a rate, not with 0 %: otherwise the test would stay green if the
        // unit price were derived from net — under PAngV it must be gross.
        $this->rows = [['pid' => 1, 'net' => '10.8333']];

        $price = $this->resolver($this->repository(), ['AT' => '20.0000'])
            ->forArticle(1, 1, 'EUR', 'AT', self::NOW, ['unit' => 'l', 'purchaseUnit' => '0.2500', 'referenceUnit' => '1.0000'])
        ;

        self::assertNotNull($price);
        self::assertSame('52.0000', $price->basePrice);
        self::assertSame('1.0000', $price->referenceUnit);
        self::assertSame('l', $price->unit);
    }

    /**
     * @param array{unit: string, purchaseUnit: string, referenceUnit: string} $units
     */
    #[DataProvider('unitsWithoutABasePrice')]
    public function testHasNoBasePriceWithoutAMeasurableContent(array $units): void
    {
        $this->rows = [['pid' => 1, 'net' => '13.0000']];

        $price = $this->resolve(1, 1, $units);

        self::assertNotNull($price);
        self::assertSame('', $price->basePrice);
        self::assertSame('', $price->referenceUnit);
        self::assertSame('', $price->unit);
    }

    /**
     * @return iterable<string, array{array{unit: string, purchaseUnit: string, referenceUnit: string}}>
     */
    public static function unitsWithoutABasePrice(): iterable
    {
        yield 'keine Einheit' => [['unit' => '', 'purchaseUnit' => '0.2500', 'referenceUnit' => '1.0000']];
        yield 'Stückware' => [['unit' => 'stk', 'purchaseUnit' => '1.0000', 'referenceUnit' => '1.0000']];
        yield 'Inhalt 0' => [['unit' => 'l', 'purchaseUnit' => '0.0000', 'referenceUnit' => '1.0000']];
        yield 'Inhalt leer' => [['unit' => 'l', 'purchaseUnit' => '', 'referenceUnit' => '1.0000']];
        yield 'Bezugsmenge 0' => [['unit' => 'l', 'purchaseUnit' => '0.2500', 'referenceUnit' => '0']];
    }

    public function testFromPriceIsTheCheapestAndSaysFromWhenTheVariantsDiffer(): void
    {
        [$cheapest, $isFrom] = ArticlePriceResolver::fromPrice([
            $this->articlePrice(6, '13.0000'),
            $this->articlePrice(7, '29.9000'),
            $this->articlePrice(8, '13.0000'),
        ]);

        self::assertNotNull($cheapest);
        self::assertSame('13.0000', $cheapest->gross);
        self::assertTrue($isFrom);
    }

    public function testFromPriceFindsTheCheapestWhereverItStands(): void
    {
        [$cheapest, $isFrom] = ArticlePriceResolver::fromPrice([
            $this->articlePrice(6, '29.9000'),
            $this->articlePrice(7, '13.0000'),
        ]);

        self::assertSame(7, $cheapest?->ownerId);
        self::assertTrue($isFrom);
    }

    public function testFromPriceIsNoFromPriceWhenAllVariantsCostTheSame(): void
    {
        // '13' and '13.0000' are the same amount.
        [$cheapest, $isFrom] = ArticlePriceResolver::fromPrice([
            $this->articlePrice(6, '13.0000'),
            $this->articlePrice(7, '13'),
        ]);

        self::assertSame(6, $cheapest?->ownerId);
        self::assertFalse($isFrom);
    }

    public function testFromPriceOfNothingIsNothing(): void
    {
        self::assertSame([null, false], ArticlePriceResolver::fromPrice([]));
    }

    public function testTheGrossUsesTheCountrysRateAndTheArticlesOwnTaxClass(): void
    {
        // Variant 6 inherits the price row of product 1 but is taxed at the
        // reduced rate (class 2). It is taxed with its own class — just as
        // OrderFactory taxes the line item.
        $this->rows = [['pid' => 1, 'net' => '10.0000']];

        $rates = new class implements TaxRateRepositoryInterface {
            public function findRate(int $taxClassId, string $countryCode, int $timestamp): ?string
            {
                return 'AT' === $countryCode ? ([1 => '20.0000', 2 => '10.0000'][$taxClassId] ?? null) : null;
            }

            public function findConfiguredCountries(int $taxClassId, int $timestamp): array
            {
                return ['AT'];
            }

            public function findTaxClassIdsUsedByShop(int $shopId): array
            {
                return [];
            }

            public function findClassesWithBoundaryIn(int $after, int $until): array
            {
                return [];
            }
        };

        $taxClasses = $this->createMock(ArticleTaxClassesInterface::class);
        $taxClasses->method('taxClassOf')->willReturnMap([[1, 1], [6, 2]]);

        $repository = $this->repository();
        $resolver = new ArticlePriceResolver(
            new PriceCalculator($repository),
            $repository,
            new BasePriceCalculator(),
            new GrossCalculator(new TaxResolver($rates, new CountryProvider($this->createMock(Countries::class)))),
            $taxClasses,
        );

        self::assertSame('12.0000', $resolver->forArticle(1, 1, 'EUR', 'AT', self::NOW, self::NO_UNITS)?->gross);
        self::assertSame('11.0000', $resolver->forArticle(6, 1, 'EUR', 'AT', self::NOW, self::NO_UNITS)?->gross);
        self::assertSame('11.0000', $resolver->listPrice(6, 1, 'EUR', 'AT', self::NOW)?->gross);
    }

    public function testWithoutARateInTheCountryThereIsNoPriceRatherThanAGuessedOne(): void
    {
        $this->rows = [['pid' => 1, 'net' => '10.0000']];
        $resolver = $this->resolver($this->repository(), ['AT' => '20.0000']);

        self::assertNull($resolver->forArticle(1, 1, 'EUR', 'IT', self::NOW, self::NO_UNITS));
        self::assertNull($resolver->listPrice(1, 1, 'EUR', 'IT', self::NOW));
    }

    public function testEveryScaleStepCarriesItsGross(): void
    {
        $this->rows = [
            ['pid' => 1, 'net' => '10.0000'],
            ['pid' => 1, 'net' => '9.0000', 'minQuantity' => '10.0000'],
        ];

        $price = $this->resolver($this->repository(), ['AT' => '20.0000'])->forArticle(1, 1, 'EUR', 'AT', self::NOW, self::NO_UNITS);

        self::assertNotNull($price);
        self::assertSame('12.0000', $price->gross);
        self::assertSame([['1.0000', '12.0000'], ['10.0000', '10.8000']], $this->stepsOf($price));
    }

    public function testMitEinerGruppeKommtDerGruppenpreisAufDieDetailseite(): void
    {
        // C-4: the one place where the argument changes.
        $this->rows = [
            ['pid' => 1, 'net' => '13.0000'],
            ['pid' => 1, 'net' => '9.6000', 'group' => 5],
        ];

        $price = $this->resolver($this->repository(), ['AT' => '0.0000'])
            ->forArticle(1, 1, 'EUR', 'AT', self::NOW, self::NO_UNITS, [5])
        ;

        self::assertNotNull($price);
        self::assertSame('9.6000', $price->gross);
        self::assertSame(PriceSource::CustomerGroup, $price->price->source);
        self::assertSame([[5]], array_values(array_unique($this->groupsAsked, SORT_REGULAR)));
    }

    public function testEineFremdeGruppeAendertNichts(): void
    {
        // The row belongs to group 5, the question is asked for group 7: it does
        // not exist for this visitor. Without this test a repository that
        // forgets the group in the WHERE would look just as green.
        $this->rows = [
            ['pid' => 1, 'net' => '13.0000'],
            ['pid' => 1, 'net' => '9.6000', 'group' => 5],
        ];

        $price = $this->resolver($this->repository(), ['AT' => '0.0000'])
            ->forArticle(1, 1, 'EUR', 'AT', self::NOW, self::NO_UNITS, [7])
        ;

        self::assertNotNull($price);
        self::assertSame('13.0000', $price->gross);
    }

    public function testDieStaffelZeigtDieSchwellenDerEigenenGruppe(): void
    {
        // ⚠️ The case the old line `if (null !== $row->customerGroup)
        // continue;` would have silently swallowed: the group buys from 5, everyone
        // else from 10. Without the group row in the thresholds the
        // table would show 1 and 10 — and the merchant would not find their own scale.
        $this->rows = [
            ['pid' => 1, 'net' => '10.0000'],
            ['pid' => 1, 'net' => '9.0000', 'minQuantity' => '10.0000'],
            ['pid' => 1, 'net' => '8.0000', 'group' => 5, 'minQuantity' => '5.0000'],
        ];

        $price = $this->resolver($this->repository(), ['AT' => '0.0000'])
            ->forArticle(1, 1, 'EUR', 'AT', self::NOW, self::NO_UNITS, [5])
        ;

        self::assertNotNull($price);
        // From 10 there is no tier of its own any more: the group price from 5 applies
        // there too (specificity beats the smaller amount), and a row
        // "from 10: 8.00" directly under "from 5: 8.00" says nothing.
        self::assertSame(
            [['1.0000', '10.0000'], ['5.0000', '8.0000']],
            $this->stepsOf($price),
        );
    }

    public function testEineStufeDieNichtsAendertFaelltWeg(): void
    {
        // The same amount on two thresholds — here without groups, so that the
        // rule is visible as a rule of its own and not as a side effect of C-4.
        $this->rows = [
            ['pid' => 1, 'net' => '10.0000'],
            ['pid' => 1, 'net' => '10.0000', 'minQuantity' => '10.0000'],
        ];

        $price = $this->resolve(1, 1);

        self::assertNotNull($price);
        self::assertSame([], $price->scale);
    }

    public function testEineAbozeileNimmtDenPreisIhrerRegel(): void
    {
        // Abo-2: one-off purchase 80, monthly subscription (rule 5) 8 — the row with rule 5
        // costs 8; even as the cheapest price nothing wrong would win otherwise.
        $this->rows = [
            ['pid' => 1, 'net' => '80.0000'],
            ['pid' => 1, 'net' => '8.0000', 'rule' => 5],
        ];

        $resolver = $this->resolver($this->repository(), ['AT' => '0.0000']);

        self::assertSame('8.0000', $resolver->forCartLine(1, 1, 'EUR', '1', self::NOW, [], 5)?->unitNet);
        self::assertSame('80.0000', $resolver->forCartLine(1, 1, 'EUR', '1', self::NOW)?->unitNet);
        self::assertSame('8.0000', $resolver->forArticle(1, 1, 'EUR', 'AT', self::NOW, self::NO_UNITS, [], 5)?->gross);
        self::assertNull($resolver->forCartLine(1, 1, 'EUR', '1', self::NOW, [], 6));
    }

    public function testEineVarianteErbtDenAbopreisObwohlSieEinenEigenenEinmalkaufPreisHat(): void
    {
        // The inheritance rule applies per contract: the variant has its own
        // one-off price but no subscription price — for the subscription the one of the
        // base product applies, not its one-off price and not "no price".
        $this->rows = [
            ['pid' => 1, 'net' => '8.0000', 'rule' => 5],
            ['pid' => 6, 'net' => '99.0000'],
        ];

        $resolver = $this->resolver($this->repository(), ['AT' => '0.0000']);

        self::assertSame('8.0000', $resolver->forCartLine(6, 1, 'EUR', '1', self::NOW, [], 5)?->unitNet);
        self::assertSame('99.0000', $resolver->forCartLine(6, 1, 'EUR', '1', self::NOW)?->unitNet);
    }

    public function testDieStaffelEinesAbosBleibtInSeinerRegel(): void
    {
        // The calculator recomputes scales per threshold — if it asked
        // without a rule, the one-off price would appear in the subscription scale.
        $this->rows = [
            ['pid' => 1, 'net' => '80.0000'],
            ['pid' => 1, 'net' => '8.0000', 'rule' => 5],
            ['pid' => 1, 'net' => '7.0000', 'rule' => 5, 'minQuantity' => '10.0000'],
        ];

        $price = $this->resolver($this->repository(), ['AT' => '0.0000'])
            ->forArticle(1, 1, 'EUR', 'AT', self::NOW, self::NO_UNITS, [], 5)
        ;

        self::assertNotNull($price);
        self::assertSame([['1.0000', '8.0000'], ['10.0000', '7.0000']], $this->stepsOf($price));
    }

    public function testDieProjektionFragtNieNachEinerGruppe(): void
    {
        // listPrice() feeds tl_consho_product_list_price, and the projection
        // has no group dimension ("no price per customer group in the
        // materialisation", Block A 3.6). Passing a group in here
        // would write a personal price into a shared row.
        $this->rows = [
            ['pid' => 1, 'net' => '13.0000'],
            ['pid' => 1, 'net' => '9.6000', 'group' => 5],
        ];

        $listPrice = $this->resolver($this->repository(), ['AT' => '0.0000'])->listPrice(1, 1, 'EUR', 'AT', self::NOW);

        self::assertNotNull($listPrice);
        self::assertSame('13.0000', $listPrice->net);
        self::assertSame([[]], array_values(array_unique($this->groupsAsked, SORT_REGULAR)));
    }

    /**
     * Abo-2b, P88: a subscription-only product is listed with its first model that
     * has a price — not with a leftover
     * one-off row (Abo-2).
     */
    public function testReinesAboZeigtDasErsteModellMitPreis(): void
    {
        $this->rows = [
            ['pid' => 1, 'net' => '99.0000'],
            ['pid' => 1, 'net' => '10.0000', 'rule' => 2],
            ['pid' => 1, 'net' => '1.0000', 'rule' => 4],
        ];

        $price = $this->resolver($this->repository(), ['AT' => '0.0000'], [3 => null, 2 => null, 4 => null])->listPrice(1, 1, 'EUR', 'AT', self::NOW);

        self::assertNotNull($price);
        self::assertSame(['10.0000', 2, false], [$price->net, $price->subscriptionRule, $price->alsoSubscription], 'Regel 3 hat keinen Preis, also Regel 2 — die Reihenfolge des Betreibers, nicht der billigste');
    }

    /** Abo-2b, P89: the one-off purchase stays the number, "also as a subscription" only with a price. */
    public function testEinmalkaufMitAbo(): void
    {
        $this->rows = [
            ['pid' => 1, 'net' => '9.0000'],
            ['pid' => 1, 'net' => '1.0000', 'rule' => 2],
        ];

        $price = $this->resolver($this->repository(), ['AT' => '0.0000'], [0 => null, 2 => null])->listPrice(1, 1, 'EUR', 'AT', self::NOW);
        self::assertSame(['9.0000', 0, true], [$price?->net, $price?->subscriptionRule, $price?->alsoSubscription]);

        $price = $this->resolver($this->repository(), ['AT' => '0.0000'], [0 => null, 7 => null])->listPrice(1, 1, 'EUR', 'AT', self::NOW);
        self::assertSame(['9.0000', 0, false], [$price?->net, $price?->subscriptionRule, $price?->alsoSubscription], 'ein Modell ohne Preis ist kein Angebot');
    }

    /** Abo-2b, P91: only models for everyone — the question is asked as a guest, not as a business customer. */
    public function testNurModelleFuerAlle(): void
    {
        $this->rows = [['pid' => 1, 'net' => '1.0000', 'rule' => 2]];

        self::assertNull($this->resolver($this->repository(), ['AT' => '0.0000'], [], [[]])->listPrice(1, 1, 'EUR', 'AT', self::NOW), 'kein Angebot für alle, keine Zeile');
    }

    /**
     * Rate 0 %: here gross is net — the subject of these tests is which
     * rows apply. How the rate is added is checked by the tests further up.
     */
    private function resolve(int $articleId, int $mainProductId, array $units = self::NO_UNITS): ArticlePrice|null
    {
        return $this->resolver($this->repository(), ['AT' => '0.0000'])
            ->forArticle($articleId, $mainProductId, 'EUR', 'AT', self::NOW, $units)
        ;
    }

    /**
     * @param array<string, string>        $rates
     * @param array<int, null>|null        $offered
     * @param array{list<int>}|null        $expect  the groups listPrice() must ask with
     */
    private function resolver(PriceRepositoryInterface $repository, array $rates, array|null $offered = null, array|null $expect = null): ArticlePriceResolver
    {
        $taxClasses = $this->createMock(ArticleTaxClassesInterface::class);
        $taxClasses->method('taxClassOf')->willReturn(1);

        // What SubscriptionOffers answers (rule id => rule); null: no offers service.
        $offers = null;

        if (null !== $offered) {
            $offers = $this->createMock(SubscriptionOffers::class);
            $offers->method('offered')->willReturnCallback(static function (int $articleId, array $groups) use ($offered, $expect): array {
                if (null !== $expect) {
                    self::assertSame($expect, [$groups]);
                }

                return $offered;
            });
        }

        // PriceCalculator is final — so the real one, with the same repository as the resolver.
        return new ArticlePriceResolver(new PriceCalculator($repository), $repository, new BasePriceCalculator(), $this->grossCalculator($rates), $taxClasses, $offers);
    }

    /**
     * Filters like the SQL in DbalPriceRepository: article, currency, group 0 or
     * the requested one, validity with NULL as an open bound. Order as stored.
     */
    private function repository(): PriceRepositoryInterface
    {
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findCandidates')->willReturnCallback(
            function (int $productId, string $currency, array $groups, int $timestamp, int $subscriptionRule = 0): array {
                $this->groupsAsked[] = $groups;
                $found = [];

                foreach ($this->rows as $row) {
                    $rowGroup = $row['group'] ?? null;

                    if (
                        $row['pid'] !== $productId
                        || ($row['rule'] ?? 0) !== $subscriptionRule
                        || ($row['currency'] ?? 'EUR') !== $currency
                        || (null !== $rowGroup && !\in_array($rowGroup, $groups, true))
                        || (null !== ($row['validFrom'] ?? null) && $row['validFrom'] > $timestamp)
                        || (null !== ($row['validTo'] ?? null) && $row['validTo'] <= $timestamp)
                    ) {
                        continue;
                    }

                    $found[] = new PriceRow($row['net'], $row['currency'] ?? 'EUR', $rowGroup, $row['minQuantity'] ?? '1.0000');
                }

                return $found;
            },
        );

        return $repository;
    }

    private function stepsOf(ArticlePrice $price): array
    {
        return array_map(static fn ($step): array => [$step->minQuantity, $step->gross], $price->scale);
    }

    private function articlePrice(int $ownerId, string $gross): ArticlePrice
    {
        return new ArticlePrice(new ResolvedPrice($gross, 'EUR', PriceSource::List, '1.0000'), $gross, $ownerId, []);
    }
}

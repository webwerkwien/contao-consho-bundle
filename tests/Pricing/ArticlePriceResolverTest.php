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
     * Jeder Test legt hier seine Zeilen aus tl_consho_price ab.
     *
     * @var list<array{pid: int, net: string, currency?: string, group?: int|null, minQuantity?: string, validFrom?: int|null, validTo?: int|null, rule?: int}>
     */
    private array $rows = [];

    /**
     * Welche Kundengruppen der Resolver beim Repository angefragt hat.
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
        // Eine Tabelle mit einer Zeile ist keine Staffel.
        $this->rows = [['pid' => 1, 'net' => '13.0000']];

        self::assertSame([], $this->resolve(1, 1)?->scale);
    }

    public function testAVariantWithoutOwnRowsInheritsTheWholePriceSetOfTheMainProduct(): void
    {
        // Entschieden von Michael am 2026-09-13: „leer = wie Hauptprodukt",
        // der ganze Satz samt Staffel.
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
        // Die Staffel des Hauptprodukts darf nicht in die Variante durchsickern.
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
        // Der Gast, und bis C-4 jeder: ohne Gruppen fragt der Resolver auch
        // keine an, und die Gruppenzeile ist für ihn nicht da.
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
        // Zwei gleichrangige Zeilen ab 1 — der Rechner nimmt die zuerst gelieferte
        // (Nebenbefund in Plan-Entscheidung 4). Die Staffel muss dieselbe Antwort
        // geben wie der Preis, sonst widersprechen sich zwei Zahlen auf der Seite.
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
        // Keine Ausnahme: die Seite zeigt dann keinen Preis (Plan-Entscheidung 10).
        $this->rows = [['pid' => 1, 'net' => '13.0000', 'currency' => 'CHF']];

        self::assertNull($this->resolve(1, 1));
    }

    public function testReturnsNullWhenAVariantAndItsMainProductHaveNoPrice(): void
    {
        self::assertNull($this->resolve(6, 1));
    }

    public function testCalculatesTheBasePriceFromTheGrossPrice(): void
    {
        // Netto 10,8333 bei 20 % → Brutto 13,00 für 0,25 l → 52,00 je Liter. Mit
        // einem Satz, nicht mit 0 %: Sonst bliebe der Test grün, wenn der
        // Grundpreis aus Netto entstünde — nach PAngV muss er brutto sein.
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
        // '13' und '13.0000' sind derselbe Betrag.
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
        // Variante 6 erbt die Preiszeile von Produkt 1, ist aber ermäßigt
        // besteuert (Klasse 2). Versteuert wird mit ihrer eigenen Klasse — so,
        // wie OrderFactory die Position versteuert.
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
        // C-4: die eine Stelle, an der sich das Argument ändert.
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
        // Die Zeile gehört Gruppe 5, gefragt wird für Gruppe 7: sie ist für
        // diesen Besucher nicht da. Ohne diesen Test sähe ein Repository, das
        // die Gruppe im WHERE vergisst, genauso grün aus.
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
        // ⚠️ Der Fall, den die alte Zeile `if (null !== $row->customerGroup)
        // continue;` still verschluckt hätte: Die Gruppe kauft ab 5, alle
        // anderen ab 10. Ohne die Gruppenzeile in den Schwellen zeigte die
        // Tabelle 1 und 10 — und der Händler fände seine eigene Staffel nicht.
        $this->rows = [
            ['pid' => 1, 'net' => '10.0000'],
            ['pid' => 1, 'net' => '9.0000', 'minQuantity' => '10.0000'],
            ['pid' => 1, 'net' => '8.0000', 'group' => 5, 'minQuantity' => '5.0000'],
        ];

        $price = $this->resolver($this->repository(), ['AT' => '0.0000'])
            ->forArticle(1, 1, 'EUR', 'AT', self::NOW, self::NO_UNITS, [5])
        ;

        self::assertNotNull($price);
        // Ab 10 steht keine eigene Stufe mehr: Der Gruppenpreis ab 5 gilt dort
        // auch (Spezifität schlägt den kleineren Betrag), und eine Zeile
        // „ab 10: 8,00" direkt unter „ab 5: 8,00" sagt nichts.
        self::assertSame(
            [['1.0000', '10.0000'], ['5.0000', '8.0000']],
            $this->stepsOf($price),
        );
    }

    public function testEineStufeDieNichtsAendertFaelltWeg(): void
    {
        // Derselbe Betrag auf zwei Schwellen — hier ohne Gruppen, damit die
        // Regel als eigene sichtbar ist und nicht als Nebenwirkung von C-4.
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
        // Abo-2: Einmalkauf 80, Monatsabo (Regel 5) 8 — die Zeile mit Regel 5
        // kostet 8, auch als billigster Preis gewönne sonst nichts Falsches.
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
        // Die Erbregel gilt je Vertrag: Die Variante hat einen eigenen
        // Einmalkauf-Preis, aber keinen Abopreis — für das Abo gilt der des
        // Grundprodukts, nicht ihr Einmalkauf-Preis und nicht „kein Preis".
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
        // Staffeln rechnet der Kalkulator je Schwelle neu nach — fragte er
        // dabei ohne Regel, stünde der Einmalkauf-Preis in der Abo-Staffel.
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
        // listPrice() füttert tl_consho_product_list_price, und die Projektion
        // hat keine Gruppendimension („Kein Preis je Kundengruppe in der
        // Materialisierung", Block A 3.6). Eine Gruppe hier hinein zu reichen
        // schriebe einen persönlichen Preis in eine geteilte Zeile.
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
     * Abo-2b, P88: ein reines Abo-Produkt steht mit seinem ersten Modell, das
     * einen Preis hat, in der Liste — nicht mit einer übrig gebliebenen
     * Einmalkauf-Zeile (Review Abo-2, L4a).
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

    /** Abo-2b, P89: Einmalkauf bleibt die Zahl, "auch im Abo" nur mit Preis. */
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

    /** Abo-2b, P91: nur Modelle für alle — gefragt wird als Gast, nicht als Geschäftskunde. */
    public function testNurModelleFuerAlle(): void
    {
        $this->rows = [['pid' => 1, 'net' => '1.0000', 'rule' => 2]];

        self::assertNull($this->resolver($this->repository(), ['AT' => '0.0000'], [], [[]])->listPrice(1, 1, 'EUR', 'AT', self::NOW), 'kein Angebot für alle, keine Zeile');
    }

    /**
     * Satz 0 %: Hier ist das Brutto das Netto — Thema dieser Tests ist, welche
     * Zeilen gelten. Wie der Satz hinzukommt, prüfen die Tests weiter oben.
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

        // PriceCalculator ist final — also echt, mit demselben Repository wie der Resolver.
        return new ArticlePriceResolver(new PriceCalculator($repository), $repository, new BasePriceCalculator(), $this->grossCalculator($rates), $taxClasses, $offers);
    }

    /**
     * Filtert wie das SQL in DbalPriceRepository: Artikel, Währung, Gruppe 0 oder
     * die angefragte, Gültigkeit mit NULL als offener Grenze. Reihenfolge wie abgelegt.
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

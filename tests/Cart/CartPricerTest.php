<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Contao\CoreBundle\Intl\Countries;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartItemRow;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRule;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

class CartPricerTest extends TestCase
{
    use TaxFixture;

    public function testDieZeilensummeEntstehtAusNettoMalMengeUndErstDannBrutto(): void
    {
        // 8.3333 net × 3 = 24.9999 net → 29.9999 gross at 20 %.
        // Unit gross 10.00 × 3 would be 30.00 — one cent off, and the
        // invoice would later contradict the cart.
        $pricer = $this->pricer(['8.3333' => 7]);

        $view = $pricer->price(
            [new CartItemRow(1, 7, '3.0000', 1_000)],
            'EUR',
            'AT',
            'AT',
            1_000,
        );

        self::assertSame('24.9999', $view->lines[0]->lineNet);
        self::assertSame('29.9999', $view->lines[0]->lineGross);
    }

    public function testEinePositionOhnePreisFliegtRausUndWirdGemeldet(): void
    {
        // No price in the shop's country means: not for sale. Showing it at 0.00
        // would be an offer nobody made.
        $pricer = $this->pricer([]);

        $view = $pricer->price([new CartItemRow(1, 7, '1.0000', 1_000)], 'EUR', 'AT', 'AT', 1_000);

        self::assertSame([], $view->lines);
        self::assertSame([7], $view->unpriced);
    }

    public function testOhneSteuersatzFuerKlasseUndLandFliegtDieZeileGenauSoRaus(): void
    {
        // A second way into the same situation: price yes, rate no. That too
        // means not for sale — a gross amount with a guessed rate would be a
        // price the order later contradicts.
        $pricer = $this->pricer(['10.0000' => 7], ['DE' => '19.0000']);

        $view = $pricer->price([new CartItemRow(1, 7, '1.0000', 1_000)], 'EUR', 'AT', 'AT', 1_000);

        self::assertSame([], $view->lines);
        self::assertSame([7], $view->unpriced);
    }

    public function testEineAbozeileWirdMitIhrerRegelBepreist(): void
    {
        // Abo-2: the same article twice, once as a monthly subscription (rule 5),
        // once as a one-off purchase — two lines, two prices.
        $view = $this->rulePricer()->price(
            [new CartItemRow(1, 7, '2.0000', 1_000, 5), new CartItemRow(2, 7, '1.0000', 1_000)],
            'EUR',
            'AT',
            'AT',
            1_000,
        );

        self::assertSame(['2.0000', '80.0000'], array_map(static fn ($line) => $line->lineNet, $view->lines));
        self::assertSame([5, 0], array_map(static fn ($line) => $line->subscriptionRule, $view->lines));
    }

    public function testEineUnbepreisteAbozeileWirdMitIhrerZeilenIdGemeldet(): void
    {
        // Rule 6 has no price. The article is also in the cart as a one-off
        // purchase — only the line id tells which of the two is blocking.
        $view = $this->rulePricer()->price(
            [new CartItemRow(1, 7, '1.0000', 1_000), new CartItemRow(2, 7, '1.0000', 1_000, 6)],
            'EUR',
            'AT',
            'AT',
            1_000,
        );

        self::assertSame([7], $view->unpriced);
        self::assertSame([2], $view->unpricedItems);
        self::assertSame([2], $view->withoutTax()->unpricedItems);
    }

    public function testDerWarenkorbFragtDieAngeboteMitDenGruppen(): void
    {
        // P11: the rule's groups are checked by SubscriptionOffers — since
        // variant A they alone decide, also about "full minimum term".
        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->expects(self::once())->method('offered')->with(7, [4])->willReturn([0 => null]);

        $this->rulePricer($offers)->price([new CartItemRow(1, 7, '1.0000', 1_000, 5)], 'EUR', 'AT', 'AT', 1_000, [4]);
    }

    public function testEinModellDasDasProduktNichtMehrAnbietetIstNichtVerkaeuflich(): void
    {
        // Abo-2: the operator removes rule 5 from the product
        // while it sits in the cart. The price line is still there — but it
        // is no longer an offer, and the line must not become an order.
        $items = [new CartItemRow(1, 7, '1.0000', 1_000, 5), new CartItemRow(2, 7, '1.0000', 1_000)];

        $view = $this->rulePricer($this->offersOf([0]))->price($items, 'EUR', 'AT', 'AT', 1_000);

        self::assertSame([1], $view->unpricedItems);
        self::assertSame([0], array_map(static fn ($line) => $line->subscriptionRule, $view->lines));
    }

    public function testEinEinmalkaufDenDasProduktNichtMehrAnbietetIstNichtVerkaeuflich(): void
    {
        // The same in the other direction: "one-off purchase" unticked, the old
        // one-off line in the cart — or the product type became physical and
        // no longer offers the subscription (A13).
        $items = [new CartItemRow(1, 7, '1.0000', 1_000, 5), new CartItemRow(2, 7, '1.0000', 1_000)];

        self::assertSame([2], $this->rulePricer($this->offersOf([5]))->price($items, 'EUR', 'AT', 'AT', 1_000)->unpricedItems);
    }

    public function testDieAngeboteWerdenJeArtikelEinmalGefragt(): void
    {
        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->expects(self::once())->method('offered')->willReturn([0 => null, 5 => null]);

        $this->rulePricer($offers)->price([new CartItemRow(1, 7, '1.0000', 1_000, 5), new CartItemRow(2, 7, '1.0000', 1_000)], 'EUR', 'AT', 'AT', 1_000);
    }

    /**
     * @param list<int> $rules what the product offers; 0 = one-off purchase
     */
    private function offersOf(array $rules): SubscriptionOffers
    {
        $offered = [];

        foreach ($rules as $id) {
            $offered[$id] = 0 === $id ? null : new SubscriptionRule($id, 'Regel '.$id, 1, 'year', 1, RenewalKind::Indefinite, 14, 30, 14, []);
        }

        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->method('offered')->willReturn($offered);

        return $offers;
    }

    public function testDieSummenZaehlenAlleZeilen(): void
    {
        $pricer = $this->pricer(['10.0000' => 7, '5.0000' => 9]);

        $view = $pricer->price(
            [new CartItemRow(1, 7, '2.0000', 1_000), new CartItemRow(2, 9, '1.0000', 1_000)],
            'EUR',
            'AT',
            'AT',
            1_000,
        );

        // The totals carry the currency's decimals, the quantity does not: a
        // quantity is not money and keeps four decimals (articles sold by the kilogram).
        self::assertSame('25.00', $view->net);
        self::assertSame('30.00', $view->gross);
        self::assertSame('3.0000', $view->quantity);
        self::assertFalse($view->isEmpty());
    }

    public function testEinLeererWarenkorbHatSummeNull(): void
    {
        $view = $this->pricer([])->price([], 'EUR', 'AT', 'AT', 1_000);

        self::assertTrue($view->isEmpty());
        self::assertSame('0.00', $view->gross);
    }

    public function testEinWarenkorbNurMitUnbezahlbaremIstNichtLeer(): void
    {
        // Surprising enough to be pinned down: not a single line in the
        // totals, and still not empty. Otherwise the cart page would show
        // "Your cart is empty" while it holds a line item the visitor could
        // not even remove.
        $view = $this->pricer([])->price([new CartItemRow(1, 7, '1.0000', 1_000)], 'EUR', 'AT', 'AT', 1_000);

        self::assertSame([], $view->lines);
        self::assertFalse($view->isEmpty());
        self::assertSame(1, $view->count());
    }

    public function testEineMengeMitNachkommastellen(): void
    {
        // A product sold by the kilogram: 2.5 × 4.4000 = 11.0000
        // net, gross 13.2000. The column is decimal(10,4) so that exactly
        // this works.
        $view = $this->pricer(['4.4000' => 7])->price(
            [new CartItemRow(1, 7, '2.5000', 1_000)],
            'EUR',
            'AT',
            'AT',
            1_000,
        );

        self::assertSame('11.0000', $view->lines[0]->lineNet);
        self::assertSame('13.2000', $view->lines[0]->lineGross);
        self::assertSame('2.5000', $view->quantity);
    }

    public function testZweiSteuerklasseninEinemWarenkorb(): void
    {
        // The case for which the totals are built line by line: 20 % on
        // one line, 10 % on the other. A total net of 20.0000 would have no
        // rate to turn it into gross — 10.0000 → 12.0000 and
        // 10.0000 → 11.0000 add up to 23.0000.
        $view = $this->pricerWithClasses(
            [7 => ['10.0000', 1], 9 => ['10.0000', 2]],
            [1 => '20.0000', 2 => '10.0000'],
        )->price(
            [new CartItemRow(1, 7, '1.0000', 1_000), new CartItemRow(2, 9, '1.0000', 1_000)],
            'EUR',
            'AT',
            'AT',
            1_000,
        );

        self::assertSame('12.0000', $view->lines[0]->lineGross);
        self::assertSame('11.0000', $view->lines[1]->lineGross);
        self::assertSame('20.00', $view->net);
        self::assertSame('23.00', $view->gross);

        // The class travels with the line — C-5 splits the shipping tax
        // by it (C4), and a second look into the catalogue at that point
        // would be a copy of exactly this assignment.
        self::assertSame(1, $view->lines[0]->taxClassId);
        self::assertSame(2, $view->lines[1]->taxClassId);
    }

    public function testDieSummeEntstehtAusDenGerundetenZeilenNichtAusDenRohen(): void
    {
        // The worked example: 8.3367 net, 20 %, two lines with quantity 1 each.
        //
        //   line gross raw     : 10.0040  → shown as 10.00, twice = 20.00
        //   sum of the raw ones: 20.0080 → shown as 20.01
        //
        // The visitor adds 10.00 + 10.00 and reads 20.01. Not an edge case:
        // it needs no tie, only enough accumulated remainders.
        $view = $this->pricerWithClasses(
            [7 => ['8.3367', 1], 9 => ['8.3367', 1]],
            [1 => '20.0000'],
        )->price(
            [new CartItemRow(1, 7, '1.0000', 1_000), new CartItemRow(2, 9, '1.0000', 1_000)],
            'EUR',
            'AT',
            'AT',
            1_000,
        );

        // The line itself keeps four decimals: net is the anchor, and the
        // line gross is still derived from the unrounded line net.
        self::assertSame('8.3367', $view->lines[0]->lineNet);
        self::assertSame('10.0040', $view->lines[0]->lineGross);

        // The line is rounded before it goes into the total.
        self::assertSame('20.00', $view->gross);
        self::assertSame('16.68', $view->net);
    }

    public function testDieAngezeigteZeileIstGenauDerBetragDerInDieSummeGing(): void
    {
        // The assertion the change hinges on: CartPricer rounds the line
        // for the total, MoneyFormatter rounds it for display — and both must
        // produce the same number, otherwise a column again ends in a total
        // its own lines do not add up to.
        // They do, because both use the same number of decimals (Currencies)
        // and the same rounding mode (half up).
        $view = $this->pricerWithClasses(
            [7 => ['8.3367', 1], 9 => ['8.3367', 1]],
            [1 => '20.0000'],
        )->price(
            [new CartItemRow(1, 7, '1.0000', 1_000), new CartItemRow(2, 9, '1.0000', 1_000)],
            'EUR',
            'AT',
            'AT',
            1_000,
        );

        $money = new MoneyFormatter();
        $angezeigt = array_map(
            static fn ($line): string => $money->format($line->lineGross, $view->currency, 'en'),
            $view->lines,
        );

        self::assertSame(['€10.00', '€10.00'], $angezeigt);
        self::assertSame('€20.00', $money->format($view->gross, $view->currency, 'en'));
    }

    /**
     * @param array<string, int>    $netByArticle net => article id
     * @param array<string, string> $rates        ISO code => rate
     */
    private function pricer(array $netByArticle, array $rates = ['AT' => '20.0000']): CartPricer
    {
        // The test stands on doubles: the price resolution itself is tested in
        // ArticlePriceResolverTest; here it is about lines and totals.
        // GrossCalculator is final and is therefore built for real (TaxFixture),
        // with fixed rates — only that way the rounding stays the shop's own.
        $prices = new class(array_flip($netByArticle)) implements CartLinePriceSource {
            /**
             * @param array<int, string> $netOf article id => net
             */
            public function __construct(private readonly array $netOf)
            {
            }

            public function forCartLine(int $articleId, int $mainProductId, string $currency, string $quantity, int $now, array $customerGroups = [], int $subscriptionRule = 0): CartLinePrice|null
            {
                return isset($this->netOf[$articleId]) ? new CartLinePrice($this->netOf[$articleId], 1) : null;
            }
        };

        // No variants in these tests: every article is its own
        // main product.
        $tree = $this->createMock(ArticleTreeInterface::class);
        $tree->method('articlesOf')->willReturnCallback(
            static fn (array $ids): array => array_combine($ids, $ids),
        );

        return new CartPricer($tree, $prices, $this->grossCalculator($rates));
    }

    /**
     * Article 7: one-off purchase 80, monthly subscription (rule 5) 1, rule 6 without a price.
     * Rate 0 % so that gross equals net — the subject is the rule.
     */
    private function rulePricer(SubscriptionOffers|null $offers = null): CartPricer
    {
        $prices = new class implements CartLinePriceSource {
            public function forCartLine(int $articleId, int $mainProductId, string $currency, string $quantity, int $now, array $customerGroups = [], int $subscriptionRule = 0): CartLinePrice|null
            {
                return match ($subscriptionRule) {
                    0 => new CartLinePrice('80.0000', 1),
                    5 => new CartLinePrice('1.0000', 1),
                    default => null,
                };
            }
        };

        $tree = $this->createMock(ArticleTreeInterface::class);
        $tree->method('articlesOf')->willReturnCallback(
            static fn (array $ids): array => array_combine($ids, $ids),
        );

        return new CartPricer($tree, $prices, $this->grossCalculator(['AT' => '0.0000']), $offers);
    }

    /**
     * Like pricer(), only with one tax class per article and one rate per
     * class. TaxFixture cannot do that: FixedTaxRates deliberately returns
     * one rate per country, whatever class is asked for — for tests in
     * which tax is not the subject. Here it is.
     *
     * @param array<int, array{0: string, 1: int}> $articles article id => [net, tax class]
     * @param array<int, string>                   $rates    tax class => rate
     */
    private function pricerWithClasses(array $articles, array $rates): CartPricer
    {
        $prices = new class($articles) implements CartLinePriceSource {
            /**
             * @param array<int, array{0: string, 1: int}> $articles
             */
            public function __construct(private readonly array $articles)
            {
            }

            public function forCartLine(int $articleId, int $mainProductId, string $currency, string $quantity, int $now, array $customerGroups = [], int $subscriptionRule = 0): CartLinePrice|null
            {
                if (!isset($this->articles[$articleId])) {
                    return null;
                }

                [$net, $taxClass] = $this->articles[$articleId];

                return new CartLinePrice($net, $taxClass);
            }
        };

        $repository = new class($rates) implements TaxRateRepositoryInterface {
            /**
             * @param array<int, string> $rates
             */
            public function __construct(private readonly array $rates)
            {
            }

            public function findRate(int $taxClassId, string $countryCode, int $timestamp): string|null
            {
                return 'AT' === $countryCode ? ($this->rates[$taxClassId] ?? null) : null;
            }

            public function findConfiguredCountries(int $taxClassId, int $timestamp): array
            {
                return ['AT'];
            }

            public function findTaxClassIdsUsedByShop(int $shopId): array
            {
                return array_keys($this->rates);
            }

            public function findClassesWithBoundaryIn(int $after, int $until): array
            {
                return [];
            }
        };

        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT']);

        $tree = $this->createMock(ArticleTreeInterface::class);
        $tree->method('articlesOf')->willReturnCallback(
            static fn (array $ids): array => array_combine($ids, $ids),
        );

        return new CartPricer($tree, $prices, new GrossCalculator(new TaxResolver($repository, new CountryProvider($countries))));
    }
}

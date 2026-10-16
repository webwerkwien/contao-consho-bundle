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
        // 8.3333 netto × 3 = 24.9999 netto → 29.9999 brutto bei 20 %.
        // Einzelbrutto 10.00 × 3 wären 30.00 — ein Cent daneben, und die
        // Rechnung widerspräche später dem Warenkorb.
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
        // Kein Preis im Land des Shops heißt: nicht verkäuflich. Sie mit 0,00
        // zu zeigen wäre ein Angebot, das niemand gemacht hat.
        $pricer = $this->pricer([]);

        $view = $pricer->price([new CartItemRow(1, 7, '1.0000', 1_000)], 'EUR', 'AT', 'AT', 1_000);

        self::assertSame([], $view->lines);
        self::assertSame([7], $view->unpriced);
    }

    public function testOhneSteuersatzFuerKlasseUndLandFliegtDieZeileGenauSoRaus(): void
    {
        // Zweiter Weg in dieselbe Lage: Preis ja, Satz nein. Auch das heißt
        // nicht verkäuflich — ein Brutto mit geratenem Satz wäre ein Preis,
        // dem die Bestellung später widerspricht.
        $pricer = $this->pricer(['10.0000' => 7], ['DE' => '19.0000']);

        $view = $pricer->price([new CartItemRow(1, 7, '1.0000', 1_000)], 'EUR', 'AT', 'AT', 1_000);

        self::assertSame([], $view->lines);
        self::assertSame([7], $view->unpriced);
    }

    public function testEineAbozeileWirdMitIhrerRegelBepreist(): void
    {
        // Abo-2: derselbe Artikel zweimal, einmal als Monatsabo (Regel 5),
        // einmal als Einmalkauf — zwei Zeilen, zwei Preise.
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
        // Regel 6 hat keinen Preis. Der Artikel steht auch als Einmalkauf im
        // Korb — nur die Zeilen-Id sagt, welche der beiden blockiert.
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
        // P11: Die Gruppen der Regel prüft SubscriptionOffers — seit
        // Variante A entscheiden sie allein, auch über „volle Mindestlaufzeit".
        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->expects(self::once())->method('offered')->with(7, [4])->willReturn([0 => null]);

        $this->rulePricer($offers)->price([new CartItemRow(1, 7, '1.0000', 1_000, 5)], 'EUR', 'AT', 'AT', 1_000, [4]);
    }

    public function testEinModellDasDasProduktNichtMehrAnbietetIstNichtVerkaeuflich(): void
    {
        // Fable-Review Abo-2, F1: Der Betreiber nimmt Regel 5 vom Produkt,
        // während sie im Warenkorb liegt. Die Preiszeile steht noch — sie ist
        // aber kein Angebot mehr, und die Zeile darf nicht zur Bestellung werden.
        $items = [new CartItemRow(1, 7, '1.0000', 1_000, 5), new CartItemRow(2, 7, '1.0000', 1_000)];

        $view = $this->rulePricer($this->offersOf([0]))->price($items, 'EUR', 'AT', 'AT', 1_000);

        self::assertSame([1], $view->unpricedItems);
        self::assertSame([0], array_map(static fn ($line) => $line->subscriptionRule, $view->lines));
    }

    public function testEinEinmalkaufDenDasProduktNichtMehrAnbietetIstNichtVerkaeuflich(): void
    {
        // Dasselbe in die andere Richtung: „Einmalkauf" abgehakt, die alte
        // Einmalkauf-Zeile im Korb — oder der Produkttyp wurde körperlich und
        // bietet das Abo nicht mehr an (A13).
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
     * @param list<int> $rules was das Produkt anbietet; 0 = Einmalkauf
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

        // Die Summen tragen die Stellen der Währung, die Menge nicht: eine
        // Menge ist kein Geld und bleibt vierstellig (Kilogramm-Artikel).
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
        // Überraschend genug, um festgehalten zu werden: keine einzige Zeile
        // in den Summen, und trotzdem nicht leer. Sonst zeigte die
        // Warenkorbseite „Ihr Warenkorb ist leer", während eine Position
        // darin liegt, die der Besucher nicht einmal entfernen könnte.
        $view = $this->pricer([])->price([new CartItemRow(1, 7, '1.0000', 1_000)], 'EUR', 'AT', 'AT', 1_000);

        self::assertSame([], $view->lines);
        self::assertFalse($view->isEmpty());
        self::assertSame(1, $view->count());
    }

    public function testEineMengeMitNachkommastellen(): void
    {
        // Ein Produkt, das in Kilogramm verkauft wird: 2,5 × 4,4000 = 11,0000
        // netto, brutto 13,2000. Die Spalte ist decimal(10,4), damit genau
        // das geht.
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
        // Der Fall, für den die Summen zeilenweise gebildet werden: 20 % auf
        // die eine Zeile, 10 % auf die andere. Ein Gesamtnetto von 20,0000
        // hätte keinen Satz, mit dem man es verbruttet — 10,0000 → 12,0000
        // und 10,0000 → 11,0000 ergeben zusammen 23,0000.
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

        // Die Klasse reist an der Zeile mit — C-5 teilt die Versandsteuer
        // danach auf (C4), und ein zweiter Blick in den Katalog an jener
        // Stelle wäre eine Kopie genau dieser Zuordnung.
        self::assertSame(1, $view->lines[0]->taxClassId);
        self::assertSame(2, $view->lines[1]->taxClassId);
    }

    public function testDieSummeEntstehtAusDenGerundetenZeilenNichtAusDenRohen(): void
    {
        // Befund 5, mit genau dem Zahlenbeispiel aus dem Abschlussreview:
        // 8.3367 netto, 20 %, zwei Zeilen à Menge 1.
        //
        //   Zeilenbrutto roh  : 10.0040  → angezeigt 10,00, zweimal = 20,00
        //   Summe aus den rohen: 20.0080 → angezeigt 20,01
        //
        // Der Besucher rechnet 10,00 + 10,00 und liest 20,01. Kein Eckfall:
        // es braucht keinen Gleichstand, nur genug angesammelte Reste.
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

        // Die Zeile selbst bleibt vierstellig: Netto ist der Anker, und das
        // Zeilenbrutto entsteht weiterhin aus dem ungerundeten Zeilennetto.
        self::assertSame('8.3367', $view->lines[0]->lineNet);
        self::assertSame('10.0040', $view->lines[0]->lineGross);

        // Gerundet wird die Zeile, bevor sie in die Summe geht.
        self::assertSame('20.00', $view->gross);
        self::assertSame('16.68', $view->net);
    }

    public function testDieAngezeigteZeileIstGenauDerBetragDerInDieSummeGing(): void
    {
        // Die Zusicherung, an der die Änderung hängt: CartPricer rundet die
        // Zeile für die Summe, MoneyFormatter rundet sie für die Anzeige —
        // und beide müssen dieselbe Zahl erzeugen, sonst steht unter einer
        // Spalte wieder eine Summe, die ihre eigenen Zeilen nicht ergibt.
        // Sie tun es, weil beide dieselbe Stellenzahl (Currencies) und
        // dieselbe Rundungsart (half up) verwenden.
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
     * @param array<string, int>    $netByArticle Netto => Artikel-Id
     * @param array<string, string> $rates        ISO-Code => Satz
     */
    private function pricer(array $netByArticle, array $rates = ['AT' => '20.0000']): CartPricer
    {
        // Der Test steht auf Doubles: die Preisauflösung selbst ist in
        // ArticlePriceResolverTest geprüft, hier geht es um Zeilen und Summen.
        // GrossCalculator ist final und wird deshalb echt gebaut (TaxFixture),
        // mit festen Sätzen — nur so bleibt die Rundung die des Hauses.
        $prices = new class(array_flip($netByArticle)) implements CartLinePriceSource {
            /**
             * @param array<int, string> $netOf Artikel-Id => Netto
             */
            public function __construct(private readonly array $netOf)
            {
            }

            public function forCartLine(int $articleId, int $mainProductId, string $currency, string $quantity, int $now, array $customerGroups = [], int $subscriptionRule = 0): CartLinePrice|null
            {
                return isset($this->netOf[$articleId]) ? new CartLinePrice($this->netOf[$articleId], 1) : null;
            }
        };

        // Keine Varianten in diesen Tests: jeder Artikel ist sein eigenes
        // Hauptprodukt.
        $tree = $this->createMock(ArticleTreeInterface::class);
        $tree->method('articlesOf')->willReturnCallback(
            static fn (array $ids): array => array_combine($ids, $ids),
        );

        return new CartPricer($tree, $prices, $this->grossCalculator($rates));
    }

    /**
     * Artikel 7: Einmalkauf 80, Monatsabo (Regel 5) 1, Regel 6 ohne Preis.
     * Satz 0 %, damit brutto gleich netto ist — Thema ist die Regel.
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
     * Wie pricer(), nur mit einer Steuerklasse je Artikel und einem Satz je
     * Klasse. TaxFixture kann das nicht: FixedTaxRates liefert absichtlich
     * einen Satz je Land, gleich welche Klasse gefragt wird — für Tests, in
     * denen die Steuer nicht das Thema ist. Hier ist sie es.
     *
     * @param array<int, array{0: string, 1: int}> $articles Artikel-Id => [Netto, Steuerklasse]
     * @param array<int, string>                   $rates    Steuerklasse => Satz
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog\Read;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\String\HtmlDecoder;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalProductSafetyRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSafetyResolver;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogAddressesInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogArticle;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogContext;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\ComparePrices;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTaxClasses;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\OneTimeOffer;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Shipping\DbalArticleShippingData;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;
use Webwerkwien\ContaoConshoBundle\Stock\ArticleAvailability;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockState;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSort;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogContextFactory;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogContextUnusable;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogReader;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Connect-1, CN-1: the context from a start point and the visible products
 * of it, against the real projection.
 */
class CatalogReaderTest extends TestCase
{
    use CatalogSchema;
    use TaxFixture;

    private const NOW = 1_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => 1, 'title' => 'Shop', 'fallbackLanguage' => 'de', 'country' => 'AT', 'deliveryCountries' => serialize(['AT', 'DE'])]);
        $this->insertRootPage(1, 'de', 1);
        $this->connection->update('tl_page', ['dns' => 'shop.example'], ['id' => 1]);
    }

    public function testDerKontextKommtVomStartpunkt(): void
    {
        $context = $this->factory()->forRootPage(1, '', [], self::NOW);

        self::assertSame([1, 1, 'de', 'de', 'EUR', 'AT', 'AT', ['AT', 'DE'], [], self::NOW], [
            $context->shopId, $context->rootPageId, $context->language, $context->fallback, $context->currency,
            $context->country, $context->shopCountry, $context->deliveryCountries, $context->groups, $context->now,
        ]);
    }

    public function testEinLieferlandWirdGrossGeschriebenUndGeprueft(): void
    {
        self::assertSame('DE', $this->factory()->forRootPage(1, ' de ')->country);

        try {
            $this->factory()->forRootPage(1, 'CH');
            self::fail('CH is not served');
        } catch (CatalogContextUnusable $e) {
            self::assertSame(CatalogContextUnusable::COUNTRY_NOT_SERVED, $e->reason);
        }
    }

    public function testOhneLieferlaenderIstJedesLandErlaubt(): void
    {
        $this->connection->update('tl_consho_shop', ['deliveryCountries' => null], ['id' => 1]);

        $context = $this->factory()->forRootPage(1, 'CH');

        self::assertSame('CH', $context->country);
        // Stated for the shop's own country only (K3, K4).
        self::assertSame(['AT'], $context->servedCountries());
    }

    /**
     * @dataProvider unusable
     *
     * @param array<string, mixed> $page
     */
    public function testUnbrauchbareStartpunkteNennenDenGrund(array $page, string $reason): void
    {
        $this->connection->update('tl_page', $page, ['id' => 1]);

        try {
            $this->factory()->forRootPage(1);
            self::fail('expected '.$reason);
        } catch (CatalogContextUnusable $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unusable(): iterable
    {
        yield 'keine Startseite' => [['type' => 'regular'], CatalogContextUnusable::NO_ROOT_PAGE];
        yield 'kein Shop' => [['consho_shop' => 0], CatalogContextUnusable::NO_SHOP];
        yield 'ein gelöschter Shop' => [['consho_shop' => 9], CatalogContextUnusable::NO_SHOP];
        yield 'keine Währung' => [['consho_currency' => ''], CatalogContextUnusable::NO_CURRENCY];
        yield 'keine Domain' => [['dns' => ''], CatalogContextUnusable::NO_DOMAIN];
        yield 'unveröffentlicht' => [['published' => ''], CatalogContextUnusable::NOT_PUBLISHED];
        // forRootPage() asks at the real time here: a start in 2100.
        yield 'noch nicht freigegeben' => [['start' => '4102444800'], CatalogContextUnusable::NOT_PUBLISHED];
        yield 'abgelaufen' => [['stop' => (string) self::NOW], CatalogContextUnusable::NOT_PUBLISHED];
    }

    /**
     * Contao's rule: published while stop > now — so stop = now is over
     * (H-A: the data set above asks at the real time).
     */
    public function testDieStopGrenzeIstExklusiv(): void
    {
        $this->connection->update('tl_page', ['stop' => (string) (self::NOW + 1)], ['id' => 1]);
        self::assertSame(1, $this->factory()->forRootPage(1, '', [], self::NOW)->rootPageId);

        $this->connection->update('tl_page', ['stop' => (string) self::NOW], ['id' => 1]);

        try {
            $this->factory()->forRootPage(1, '', [], self::NOW);
            self::fail('stopped at now');
        } catch (CatalogContextUnusable $e) {
            self::assertSame(CatalogContextUnusable::NOT_PUBLISHED, $e->reason);
        }
    }

    public function testFreigabeZeitraumIstKeinHindernis(): void
    {
        $this->connection->update('tl_page', ['start' => (string) (self::NOW - 60), 'stop' => (string) (self::NOW + 60)], ['id' => 1]);

        self::assertSame(1, $this->factory()->forRootPage(1, '', [], self::NOW)->rootPageId);
    }

    public function testOhneLandDasErsteBelieferteWennDasEigeneFehlt(): void
    {
        $this->connection->update('tl_consho_shop', ['deliveryCountries' => serialize(['DE', 'CH'])], ['id' => 1]);

        self::assertSame('DE', $this->factory()->forRootPage(1)->country);
    }

    public function testOhneDomainGiltDieDerAnwendung(): void
    {
        $this->connection->update('tl_page', ['dns' => ''], ['id' => 1]);

        $factory = fn (RequestContext $context): CatalogContextFactory => new CatalogContextFactory($this->connection, new ShopLanguages($this->connection), $context);

        self::assertSame('https://shop.example', $factory(new RequestContext('', 'GET', 'shop.example', 'https'))->forRootPage(1)->baseUrl);
        self::assertSame('http://shop.example:8080/sub', $factory(new RequestContext('/sub/', 'GET', 'shop.example', 'http', 8080))->forRootPage(1)->baseUrl);

        // Symfony's default host is no address anybody outside can open.
        try {
            $factory(new RequestContext())->forRootPage(1);
            self::fail('localhost is no domain');
        } catch (CatalogContextUnusable $e) {
            self::assertSame(CatalogContextUnusable::NO_DOMAIN, $e->reason);
        }
    }

    public function testDieDomainDesStartpunktsGehtVor(): void
    {
        $factory = new CatalogContextFactory($this->connection, new ShopLanguages($this->connection), new RequestContext('', 'GET', 'other.example', 'https'));

        self::assertSame('https://shop.example', $factory->forRootPage(1)->baseUrl);
    }

    public function testDasSteuerlandFolgtDemCheckout(): void
    {
        // Below the OSS threshold: the shop's rate, as TaxPlace says.
        self::assertSame('AT', $this->factory()->forRootPage(1, 'DE')->taxCountry());

        $this->connection->update('tl_consho_shop', ['taxAtDestination' => 1], ['id' => 1]);

        self::assertSame('DE', $this->factory()->forRootPage(1, 'DE')->taxCountry());
    }

    public function testDieProdukteKommenNachIdUndOhneUnsichtbare(): void
    {
        $this->product(3, 'Apfel');
        $this->product(1, 'Zitrone');
        $this->product(2, 'Birne', published: false);
        $this->product(4, 'Kiwi', priced: false);

        $reader = $this->reader();
        $context = $this->factory()->forRootPage(1, '', [], self::NOW);

        // By id, not by title — the title order would be 3, 1.
        self::assertSame([1, 3], $reader->mainIds($context));
        self::assertSame([3], $reader->mainIds($context, 1, 1));
        self::assertSame(2, $reader->count($context));
    }

    public function testObEinProduktSichtbarIst(): void
    {
        $this->product(1, 'Zitrone');
        $this->product(2, 'Birne', published: false);

        $reader = $this->reader();
        $context = $this->factory()->forRootPage(1, '', [], self::NOW);

        self::assertSame([true, false, false], [$reader->isVisible($context, 1), $reader->isVisible($context, 2), $reader->isVisible($context, 99)]);
    }

    public function testDieIdSortierungIstKeineBesucherSortierung(): void
    {
        self::assertFalse(ProductSort::isKnown(ProductSort::ID));
    }

    public function testEinAnderesLandAendertDieAuswahlNicht(): void
    {
        $this->product(1, 'Zitrone');

        $context = $this->factory()->forRootPage(1, 'DE', [], self::NOW);

        self::assertSame('DE', $context->country);
        self::assertSame([1], $this->reader()->mainIds($context));
    }

    // ── articles() (CN-2) ─────────────────────────────────────────────────

    public function testEinProduktOhneVariantenIstEinArtikel(): void
    {
        $this->insertManufacturer(['id' => 5, 'title' => 'Leki']);
        $this->product(1, 'Stock &amp; Stein');
        $this->connection->update('tl_consho_product', [
            'teaser' => 'Kurz &lt;3',
            'description' => '<p>Lang <strong>und</strong> gut</p>',
            'sku' => 'S-1',
            'gtin' => '4006381333931',
            'mpn' => 'M-1',
            'manufacturer' => 5,
            'images' => serialize([self::bin(1), self::bin(2)]),
        ], ['id' => 1]);

        [$article] = $this->articles(1);

        self::assertSame([1, 1, null, 'Stock & Stein', [], 'Kurz <3', 'Lang und gut', 'https://shop.example/p/1', ['https://shop.example/img/'.self::uuid(1), 'https://shop.example/img/'.self::uuid(2)], 'S-1', '4006381333931', 'M-1', 'Leki', true, null], [
            $article->articleId, $article->mainId, $article->groupId, $article->title, $article->options, $article->teaser,
            $article->description, $article->url, $article->images, $article->sku, $article->gtin, $article->mpn, $article->brand,
            $article->physical, $article->stock,
        ]);
        self::assertFalse($article->isVariant());
    }

    public function testDieEinheitenGehenAlsSatzMit(): void
    {
        $this->product(1, 'Essig', priced: false);
        $this->connection->update('tl_consho_product', ['unit' => 'ml', 'purchaseUnit' => '500.0000', 'referenceUnit' => '100.0000'], ['id' => 1]);
        $this->variant(10, 1, []);
        $this->variant(11, 1, []);
        // Variant 11 has its own content, variant 10 inherits the set.
        $this->connection->update('tl_consho_product', ['unit' => 'l', 'purchaseUnit' => '1.0000', 'referenceUnit' => '1.0000'], ['id' => 11]);

        [$inherited, $own] = $this->articles(1);

        self::assertSame(['ml', 500.0, 100.0], [$inherited->units['unit'], (float) $inherited->units['purchaseUnit'], (float) $inherited->units['referenceUnit']]);
        self::assertSame(['l', 1.0, 1.0], [$own->units['unit'], (float) $own->units['purchaseUnit'], (float) $own->units['referenceUnit']]);
    }

    public function testEineUngueltigeGtinFaelltWeg(): void
    {
        $this->product(1, 'A');
        $this->connection->update('tl_consho_product', ['gtin' => '4006381333932'], ['id' => 1]);

        self::assertSame('', $this->articles(1)[0]->gtin);
    }

    public function testJedeSichtbareVarianteIstEinArtikel(): void
    {
        $this->insertPropertyGroup(['id' => 3, 'title' => 'Größe']);
        $this->insertPropertyGroup(['id' => 4, 'title' => 'Farbe']);
        $this->insertPropertyOption(['id' => 31, 'pid' => 3, 'title' => 'M']);
        $this->insertPropertyOption(['id' => 41, 'pid' => 4, 'title' => 'Rot']);
        $this->insertPropertyOption(['id' => 42, 'pid' => 4, 'title' => 'Blau']);

        $this->product(1, 'Shirt', priced: false);
        $this->connection->update('tl_consho_product', ['gtin' => '4006381333931', 'images' => serialize([self::bin(1)])], ['id' => 1]);
        $this->variant(10, 1, [31, 41], images: [self::bin(7)]);
        $this->variant(11, 1, [42]);
        $this->variant(12, 1, [31], published: false);

        $articles = $this->articles(1);

        self::assertSame([10, 11], array_map(static fn ($a): int => $a->articleId, $articles));
        // Axes in the order the page lists them: by label, Farbe before Größe.
        self::assertSame(['Farbe' => 'Rot', 'Größe' => 'M'], $articles[0]->options);
        self::assertSame(['Farbe' => 'Blau'], $articles[1]->options);
        self::assertSame([1, 1], [$articles[0]->groupId, $articles[1]->groupId]);
        self::assertSame('https://shop.example/p/1?variant=10', $articles[0]->url);
        // Own images, else the main product's; the GTIN is never inherited.
        self::assertSame(['https://shop.example/img/'.self::uuid(7)], $articles[0]->images);
        self::assertSame(['https://shop.example/img/'.self::uuid(1)], $articles[1]->images);
        self::assertSame(['', ''], [$articles[0]->gtin, $articles[1]->gtin]);
        self::assertSame('Shirt', $articles[1]->title);
    }

    public function testVariantenOhneSichtbareSindKeinArtikel(): void
    {
        $this->product(1, 'Shirt', priced: false);
        $this->variant(10, 1, [], published: false);

        self::assertSame([], $this->articles(1));
    }

    public function testEineVarianteIstKeinHauptprodukt(): void
    {
        $this->product(1, 'Shirt', priced: false);
        $this->variant(10, 1, []);

        self::assertSame([], $this->articles(10));
        self::assertSame([], $this->articles(99));
    }

    public function testBestandUndArt(): void
    {
        $this->insertProductType(['id' => 2, 'title' => 'Download', 'kind' => 'digital']);
        $this->product(1, 'A');
        $this->product(2, 'B');
        $this->connection->update('tl_consho_product', ['productType' => 2], ['id' => 2]);

        $stock = [
            1 => new ArticleAvailability(StockState::Few, '2.0000', false, 1_234),
            2 => new ArticleAvailability(StockState::Available, null),
        ];

        $a = $this->articles(1, $stock)[0];
        $b = $this->articles(2, $stock)[0];

        self::assertSame([StockState::Few, 1_234, true], [$a->stock, $a->availableFrom, $a->physical]);
        // Unlimited says nothing; a download is not shipped.
        self::assertSame([null, null, false, 'Download'], [$b->stock, $b->availableFrom, $b->physical, $b->productType]);
    }

    // ── Price (CN-3, K1, K2) ──────────────────────────────────────────────

    public function testDerPreisIstDerEinmaligeImHeimatland(): void
    {
        $this->product(1, 'A');
        $this->insertPrice(['pid' => 1, 'net' => '10.0000', 'minQuantity' => '1.0000']);

        $price = $this->articles(1)[0]->price;

        self::assertNotNull($price);
        self::assertSame(['12.00', null], [self::money($price->price->gross), $price->compareGross]);
    }

    public function testUnterDerOssSchwelleRechnetDeutschlandMitDemHeimatsatz(): void
    {
        $this->product(1, 'A');
        $this->insertPrice(['pid' => 1, 'net' => '10.0000', 'minQuantity' => '1.0000']);

        self::assertSame('12.00', self::money($this->articles(1, country: 'DE')[0]->price?->price->gross ?? ''));
    }

    public function testMitOssZahltDeutschlandDenDeutschenSatz(): void
    {
        $this->connection->update('tl_consho_shop', ['taxAtDestination' => 1], ['id' => 1]);
        $this->product(1, 'A');
        $this->insertPrice(['pid' => 1, 'net' => '10.0000', 'minQuantity' => '1.0000']);

        self::assertSame('11.90', self::money($this->articles(1, country: 'DE')[0]->price?->price->gross ?? ''));
    }

    public function testDerStreichpreisFolgtDemSteuerland(): void
    {
        $this->connection->update('tl_consho_shop', ['taxAtDestination' => 1], ['id' => 1]);
        $this->product(1, 'A');
        // A promotion for everybody — cheaper than the regular row, with a
        // window (PriceCalculator, H2) — and the projection knows what it reduces.
        $this->insertPrice(['pid' => 1, 'net' => '15.0000', 'minQuantity' => '1.0000']);
        $this->insertPrice(['pid' => 1, 'net' => '10.0000', 'minQuantity' => '1.0000', 'validFrom' => self::NOW - 100]);
        $this->connection->update('tl_consho_product_list_price', ['compareNet' => '15.0000', 'compareGross' => '18.0000'], ['pid' => 1]);

        $home = $this->articles(1)[0]->price;
        $germany = $this->articles(1, country: 'DE')[0]->price;

        self::assertSame(['15.00', '18.00'], [self::money($home->compareNet ?? ''), self::money($home->compareGross ?? '')]);
        // Recalculated at 19 %, not the Austrian 18.00 beside a German price.
        self::assertSame(['15.00', '17.85'], [self::money($germany->compareNet ?? ''), self::money($germany->compareGross ?? '')]);
    }

    public function testNichtEinmaligVerkauftHatKeinenPreis(): void
    {
        $this->product(1, 'A');
        $this->insertPrice(['pid' => 1, 'net' => '10.0000', 'minQuantity' => '1.0000']);

        $article = $this->articles(1, onceSold: false)[0];

        // Still listed: whether a channel wants it is the exporter's call.
        self::assertSame([1, null], [$article->articleId, $article->price]);
    }

    // ── Minimum quantity (Connect-1) ───────────────────────────

    public function testDieMindestmengeKostetWasDerWarenkorbVerlangt(): void
    {
        $this->connection->update('tl_consho_shop', ['taxAtDestination' => 1], ['id' => 1]);
        $this->product(1, 'A');
        $this->connection->update('tl_consho_product', ['minPurchase' => '3.0000'], ['id' => 1]);
        $this->insertPrice(['pid' => 1, 'net' => '10.0000', 'minQuantity' => '1.0000']);
        // A scale price from three on: the line is priced at it, not at 10.
        $this->insertPrice(['pid' => 1, 'net' => '9.0000', 'minQuantity' => '3.0000']);

        $article = $this->articles(1, country: 'DE')[0];

        self::assertTrue($article->hasMinimumPurchase());
        self::assertSame('3', $article->minimumPurchase);
        self::assertNotNull($article->minimumLine);
        // 3 × 9.00 net, at the German rate under OSS — and the unit price stays the one at quantity one.
        self::assertSame(['3', '27.00', '32.13'], [$article->minimumLine->quantity, self::money($article->minimumLine->net), self::money($article->minimumLine->gross)]);
        self::assertSame('11.90', self::money($article->price?->price->gross ?? ''));
    }

    public function testOhneMindestmengeKeineZeile(): void
    {
        $this->product(1, 'A');
        $this->insertPrice(['pid' => 1, 'net' => '10.0000', 'minQuantity' => '1.0000']);

        $article = $this->articles(1)[0];

        self::assertSame(['1', null, false], [$article->minimumPurchase, $article->minimumLine, $article->hasMinimumPurchase()]);
    }

    public function testEineGebrocheneMindestmengeBleibtWieSieIst(): void
    {
        $this->product(1, 'A');
        $this->connection->update('tl_consho_product', ['minPurchase' => '2.5000'], ['id' => 1]);
        $this->insertPrice(['pid' => 1, 'net' => '10.0000', 'minQuantity' => '1.0000']);

        $article = $this->articles(1)[0];

        self::assertSame('2.5', $article->minimumPurchase);
        self::assertNotNull($article->minimumLine);
        self::assertSame('30.00', self::money($article->minimumLine->gross));
    }

    public function testDieMindestmengeKommtOhneNachkommanullen(): void
    {
        // As MySQL hands decimal(10,4) over; SQLite would already say "3".
        self::assertSame(
            ['3', '2.5', '1', '1', '1'],
            array_map(CatalogReader::minimumOf(...), ['3.0000', '2.5000', '0.0000', '', 'x']),
        );
    }

    public function testOhneEinmaligenVerkaufKeineZeile(): void
    {
        $this->product(1, 'A');
        $this->connection->update('tl_consho_product', ['minPurchase' => '3.0000'], ['id' => 1]);
        $this->insertPrice(['pid' => 1, 'net' => '10.0000', 'minQuantity' => '1.0000']);

        self::assertNull($this->articles(1, onceSold: false)[0]->minimumLine);
    }

    /**
     * @param array<int, ArticleAvailability> $stock
     *
     * @return list<CatalogArticle>
     */
    private function articles(int $mainId, array $stock = [], string $country = '', bool $onceSold = true): array
    {
        return $this->makeReader($stock, $onceSold)->articles($this->factory()->forRootPage(1, $country, [], self::NOW), $mainId);
    }

    private static function money(string $amount): string
    {
        return '' === $amount ? '' : (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * @param array<int, ArticleAvailability> $stock
     */
    private function makeReader(array $stock = [], bool $onceSold = true): CatalogReader
    {
        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->method('offered')->willReturn($onceSold ? [0 => null] : []);

        $prices = new DbalPriceRepository($this->connection);
        $gross = $this->grossCalculator(['AT' => '20.0000', 'DE' => '19.0000']);
        $taxClasses = new DbalArticleTaxClasses($this->connection);
        $resolver = new ArticlePriceResolver(new PriceCalculator($prices), $prices, new BasePriceCalculator(), $gross, $taxClasses);

        $availability = $this->createMock(Availability::class);
        $availability->method('of')->willReturnCallback(static fn (array $ids): array => array_intersect_key($stock, array_flip($ids)));

        $addresses = new class implements CatalogAddressesInterface {
            public function product(int $mainId, int $variantId, CatalogContext $context): string
            {
                return $context->baseUrl.'/p/'.$mainId.($variantId > 0 ? '?variant='.$variantId : '');
            }

            public function images(array $uuids, CatalogContext $context): array
            {
                return array_map(static fn (string $uuid): string => $context->baseUrl.'/img/'.$uuid, $uuids);
            }
        };

        $insertTags = $this->createMock(InsertTagParser::class);
        $insertTags->method('replaceInline')->willReturnArgument(0);

        return new CatalogReader(
            new ProductQuery($this->connection),
            new ProductDetailRepository($this->connection),
            new VariantRepository($this->connection, new VisibilityCriteria()),
            new ProductSafetyResolver(new DbalProductSafetyRepository($this->connection)),
            $availability,
            new DbalArticleShippingData($this->connection),
            $addresses,
            new HtmlDecoder($insertTags),
            $this->connection,
            new OneTimeOffer($resolver, $offers, new ComparePrices($this->connection), $gross, $taxClasses),
        );
    }

    /**
     * @param list<int>    $options
     * @param list<string> $images
     */
    private function variant(int $id, int $of, array $options, bool $published = true, array $images = []): void
    {
        $this->connection->insert('tl_consho_product', ['id' => $id, 'pid' => $of, 'published' => $published ? 1 : 0, 'title' => 'Variante '.$id, 'images' => [] === $images ? null : serialize($images)]);

        foreach ($options as $optionId) {
            $this->insertProductOption(['pid' => $id, 'optionId' => $optionId]);
        }
    }

    private static function bin(int $n): string
    {
        return StringUtil::uuidToBin(self::uuid($n));
    }

    private static function uuid(int $n): string
    {
        return \sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    private function factory(): CatalogContextFactory
    {
        return new CatalogContextFactory($this->connection, new ShopLanguages($this->connection));
    }

    private function reader(): CatalogReader
    {
        (new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        ))->rebuild(self::NOW);

        return $this->makeReader();
    }

    private function product(int $id, string $title, bool $published = true, bool $priced = true): void
    {
        $this->connection->insert('tl_consho_product', ['id' => $id, 'pid' => 0, 'published' => $published ? 1 : 0, 'title' => $title]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => $id, 'shop' => 1]);

        if ($priced) {
            $this->connection->insert('tl_consho_product_list_price', ['pid' => $id, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);
        }
    }
}

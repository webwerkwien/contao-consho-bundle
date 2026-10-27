<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\FigureBuilder;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\JsonLd\JsonLdManager;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContext;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlDecoder;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\FrontendUser;
use Contao\PageModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalCatalogSchedule;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityLabelImages;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalProductSafetyRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetail;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogAddressesInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogContext;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogReader;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSafetyInfo;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSafetyResolver;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantAxisView;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Confirmation\ConfirmationPurpose;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\ProductDetailController;
use Webwerkwien\ContaoConshoBundle\Controller\Page\ProductPageController;
use Webwerkwien\ContaoConshoBundle\Controller\StockNoticeResult;
use Webwerkwien\ContaoConshoBundle\Customer\GuestOrderConfirmation;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\ComparePrices;
use Webwerkwien\ContaoConshoBundle\Pricing\OneTimeOffer;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTaxClasses;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Seo\ProductHead;
use Webwerkwien\ContaoConshoBundle\Seo\ProductJsonLd;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingRates;
use Webwerkwien\ContaoConshoBundle\Shipping\DbalArticleShippingData;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingRate;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockNotices;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLineTerms;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRule;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * The element with real collaborators against SQLite — repositories,
 * variant matrix, price calculator, safety information, ProductUrlGenerator
 * and ProductHead. Only Contao's frame is doubled (page and shop model,
 * ContentUrlGenerator, image studio).
 *
 * A unit test for the controller after all: FragmentTemplate is final, but
 * it can be built with a closure — the template data can thus be checked
 * without Twig and without a container. The 404 rules for `?variant=` are
 * too easily silently wrong to tick them off only in the browser.
 *
 * Base data as on a real installation: page 123 "Produkt" below start point
 * 1 (de, shop 1, EUR). Product 1 "Schwedenbitter" with the variants 3
 * (250 ml, inherits € 13.00 from 1 and € 10.80 from 10) and 6 (750 ml, own
 * price € 29.00), plus variant 8 (1 l, unpublished). Product 2 with exactly
 * one variant 7 and without a price.
 */
class ProductDetailControllerTest extends TestCase
{
    use CatalogSchema;
    use TaxFixture;

    private const PRODUCT = 1;
    private const OTHER_PRODUCT = 2;
    private const ML_250_VARIANT = 3;
    private const ML_750_VARIANT = 6;
    private const OTHER_VARIANT = 7;
    private const UNPUBLISHED_VARIANT = 8;

    private const VOLUME = 1;
    private const ML_250 = 11;
    private const ML_750 = 12;
    private const ML_1000 = 13;

    private const ELEMENT = 42;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        // ShopLanguages reads the languages from the start points.
        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'language' => 'de', 'consho_shop' => 1]);

        $this->insertShop(['id' => 1, 'title' => 'Shop', 'fallbackLanguage' => 'de', 'country' => 'AT']);

        $this->insertPropertyGroup(['id' => self::VOLUME, 'title' => 'Füllmenge', 'filterable' => 0]);
        $this->insertPropertyOption(['id' => self::ML_250, 'pid' => self::VOLUME, 'title' => '250 ml', 'sorting' => 64]);
        $this->insertPropertyOption(['id' => self::ML_750, 'pid' => self::VOLUME, 'title' => '750 ml', 'sorting' => 128]);
        $this->insertPropertyOption(['id' => self::ML_1000, 'pid' => self::VOLUME, 'title' => '1 l', 'sorting' => 256]);

        $this->insertManufacturer(['id' => 1, 'title' => 'Swiss Herbs AG', 'country' => 'at']);

        $this->insertProduct([
            'id' => self::PRODUCT,
            'title' => 'Schwedenbitter',
            'alias' => 'schwedenbitter',
            'teaser' => 'Nach alter Rezeptur',
            'description' => '<p>Kräuter und Alkohol.</p>',
            'safetyWarnings' => '<p>Enthält Alkohol.</p>',
            'manufacturer' => 1,
            'minimumAge' => 18,
            'published' => 1,
        ]);
        $this->insertProductShop(['pid' => self::PRODUCT, 'shop' => 1]);

        $this->insertVariant(self::ML_250_VARIANT, self::PRODUCT, self::ML_250, 1, ['sku' => 'SB-250']);
        $this->insertVariant(self::ML_750_VARIANT, self::PRODUCT, self::ML_750, 2, ['sku' => 'SB-750']);
        $this->insertVariant(self::UNPUBLISHED_VARIANT, self::PRODUCT, self::ML_1000, 3, ['published' => 0]);

        // Scale on the main product; variant 3 has no row of its own and inherits
        // the whole set, variant 6 has its own price.
        $this->insertPrice(['pid' => self::PRODUCT, 'net' => '10.8333', 'minQuantity' => '1.0000']);
        $this->insertPrice(['pid' => self::PRODUCT, 'net' => '9.0000', 'minQuantity' => '10.0000']);
        $this->insertPrice(['pid' => self::ML_750_VARIANT, 'net' => '24.1667', 'minQuantity' => '1.0000']);

        $this->insertProduct(['id' => self::OTHER_PRODUCT, 'title' => 'Kräutersalz', 'alias' => 'kraeutersalz', 'published' => 1]);
        $this->insertProductShop(['pid' => self::OTHER_PRODUCT, 'shop' => 1]);
        $this->insertVariant(self::OTHER_VARIANT, self::OTHER_PRODUCT, self::ML_250, 1);
    }

    // ── Without a product page ───────────────────────────────────────────

    public function testWithoutTheProductAttributeTheElementRendersNothing(): void
    {
        // The element stands on another page or in a layout section.
        // Even a nonsensical ?variant= must not trigger a 404 there.
        $result = $this->render('https://example.org/impressum.html?variant=abc', productId: null);

        self::assertNull($result['data']['product']);
        self::assertSame('', $result['head']->getCanonicalUri());
    }

    public function testOutsideAShopTheElementRendersNothing(): void
    {
        $result = $this->render('https://example.org/produkt/schwedenbitter.html', shopOfRoot: 0);

        self::assertNull($result['data']['product']);
    }

    // ── Without a variant choice ─────────────────────────────────────────

    public function testWithoutAChoiceThePageShowsTheProductWithAFromPrice(): void
    {
        $result = $this->render('https://example.org/produkt/schwedenbitter.html');
        $data = $result['data'];

        self::assertInstanceOf(ProductDetail::class, $data['product']);
        self::assertSame(self::PRODUCT, $data['product']->articleId);

        // No preselection: the variants cost differently, so "ab" (from) with the
        // cheapest price — and no scale, because no article is fixed yet.
        self::assertIsArray($data['price']);
        self::assertTrue($data['price']['isFrom']);
        self::assertSame('13,00 €', $this->spaces($data['price']['formatted']));
        self::assertSame([], $data['price']['scale']);

        // The purchase area does not know an article yet.
        self::assertNull($data['purchase']['articleId']);
    }

    public function testEinGastSiehtBruttoUndDenBruttoHinweis(): void
    {
        // The starting point the three tests below measure against. Without it
        // every "net" would be green even with a broken resolver.
        $this->netPriceGroup();

        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6')['data'];

        self::assertIsArray($data['price']);
        self::assertFalse($data['price']['showsNet']);
        self::assertSame('29,00 €', $this->spaces($data['price']['formatted']));
    }

    /**
     * Block H (H-2): a promotion for everyone, and the projection knows its
     * reference price — the page strikes it through. Without a promotion not.
     */
    public function testDieAktionZeigtDenStreichpreisDerProjektion(): void
    {
        $this->connection->insert('tl_consho_price', ['pid' => self::ML_750_VARIANT, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '20.0000', 'minQuantity' => '1.0000', 'validFrom' => time() - 100]);
        $this->connection->insert('tl_consho_product_list_price', ['pid' => self::ML_750_VARIANT, 'currency' => 'EUR', 'country' => 'AT', 'net' => '20.0000', 'gross' => '24.0000', 'compareNet' => '24.1667', 'compareGross' => '29.0000']);

        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6')['data'];

        self::assertSame('24,00 €', $this->spaces($data['price']['formatted']));
        self::assertSame('29,00 €', $this->spaces($data['price']['compare'] ?? ''));
    }

    /**
     * Connect-1 (K2): the catalogue reader names, for every article, the
     * price and the struck price this page shows for it — the feed a
     * comparison site checks against the page cannot disagree with it.
     */
    public function testDerLesedienstNenntDenPreisDerSeite(): void
    {
        // Variant 6 on promotion, variant 3 inheriting the main product's scale.
        $this->connection->insert('tl_consho_price', ['pid' => self::ML_750_VARIANT, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '20.0000', 'minQuantity' => '1.0000', 'validFrom' => time() - 100]);
        $this->connection->insert('tl_consho_product_list_price', ['pid' => self::ML_750_VARIANT, 'currency' => 'EUR', 'country' => 'AT', 'net' => '20.0000', 'gross' => '24.0000', 'compareNet' => '24.1667', 'compareGross' => '29.0000']);

        $page = [];

        foreach ([self::ML_250_VARIANT, self::ML_750_VARIANT] as $variant) {
            $price = $this->render('https://example.org/produkt/schwedenbitter.html?variant='.$variant)['data']['price'];
            $page[$variant] = [$this->spaces($price['formatted']), $this->spaces($price['compare'] ?? ''), $this->spaces($price['basePrice']['formatted'] ?? '')];
        }

        $money = new MoneyFormatter();
        $read = [];

        foreach ($this->catalogReader()->articles(new CatalogContext(1, 1, 'de', 'de', 'EUR', 'AT', 'AT', false, [], 'https://example.org', [], time()), self::PRODUCT) as $article) {
            $price = $article->price;
            self::assertNotNull($price);

            $read[$article->articleId] = [
                $this->spaces($money->format($price->price->gross, 'EUR', 'de')),
                null === $price->compareGross ? '' : $this->spaces($money->format($price->compareGross, 'EUR', 'de')),
                '' === $price->price->basePrice ? '' : $this->spaces($money->format($price->price->basePrice, 'EUR', 'de')),
            ];
        }

        self::assertSame(['24,00 €', '29,00 €'], \array_slice($page[self::ML_750_VARIANT], 0, 2), 'Ausgangspunkt: die Seite streicht durch');
        self::assertSame($page, $read);
    }

    /**
     * Connect-1 (S6): the Offer of a determined article carries the struck
     * price, the shipping per delivery country and the return window — what
     * the shop's data says, at the page's gross.
     */
    public function testDasAngebotNenntStreichpreisVersandUndRueckgabe(): void
    {
        $this->connection->insert('tl_consho_price', ['pid' => self::ML_750_VARIANT, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '20.0000', 'minQuantity' => '1.0000', 'validFrom' => time() - 100]);
        $this->connection->insert('tl_consho_product_list_price', ['pid' => self::ML_750_VARIANT, 'currency' => 'EUR', 'country' => 'AT', 'net' => '20.0000', 'gross' => '24.0000', 'compareNet' => '24.1667', 'compareGross' => '29.0000']);

        $rates = $this->createMock(ArticleShippingRates::class);
        $rates->method('cheapest')->willReturnCallback(static fn (CatalogContext $context, string $country, int $articleId, string $net, int $age): ShippingRate => new ShippingRate(1, 'Paket', $country, '5.0000', 'AT' === $country ? '6.0000' : '7.0000'));

        $offer = $this->offerOf($this->render(
            'https://example.org/produkt/schwedenbitter.html?variant='.self::ML_750_VARIANT,
            shopRow: ['deliveryCountries' => serialize(['AT', 'DE']), 'deliveryDaysMin' => 2, 'deliveryDaysMax' => 4, 'withdrawalDays' => 14],
            shippingRates: $rates,
        )['jsonLd']);

        self::assertSame(['24.00', 'https://schema.org/StrikethroughPrice', '29.00'], [$offer['price'] ?? null, $offer['priceSpecification']['priceType'] ?? null, $offer['priceSpecification']['price'] ?? null]);
        self::assertSame(
            [['AT', '6.00', 2, 4], ['DE', '7.00', 2, 4]],
            array_map(static fn (array $s): array => [$s['shippingDestination']['addressCountry'], $s['shippingRate']['value'], $s['deliveryTime']['transitTime']['minValue'], $s['deliveryTime']['transitTime']['maxValue']], $offer['shippingDetails'] ?? []),
        );
        self::assertSame(['AT', 'DE'], $offer['hasMerchantReturnPolicy']['applicableCountry'] ?? null);
        self::assertSame(14, $offer['hasMerchantReturnPolicy']['merchantReturnDays'] ?? null);
    }

    /**
     * The Offer of the Consho product in the page's schema.org graph.
     *
     * @return array<string, mixed>
     */
    private function offerOf(string $script): array
    {
        $data = json_decode(trim(strip_tags($script)), true, 512, JSON_THROW_ON_ERROR);

        foreach ($data['@graph'] ?? [] as $node) {
            if ('Product' === ($node['@type'] ?? null)) {
                return $node['offers'];
            }
        }

        self::fail('no Product in the graph');
    }

    /**
     * K3/K4: what is not shipped has no shipping costs and no return window
     * in the markup (Connect-1).
     */
    public function testEinDownloadHatKeinenVersandUndKeineRueckgabe(): void
    {
        $rates = $this->createMock(ArticleShippingRates::class);
        $rates->expects(self::never())->method('cheapest');

        $articles = $this->createMock(ArticleShippingDataInterface::class);
        $articles->method('of')->willReturnCallback(static fn (array $ids): array => array_fill_keys($ids, ['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000', 'kind' => 'digital']));

        $offer = $this->offerOf($this->render(
            'https://example.org/produkt/schwedenbitter.html?variant='.self::ML_750_VARIANT,
            shopRow: ['deliveryCountries' => serialize(['AT', 'DE']), 'deliveryDaysMin' => 2, 'deliveryDaysMax' => 4, 'withdrawalDays' => 14],
            shippingRates: $rates,
            articles: $articles,
        )['jsonLd']);

        self::assertSame('29.00', $offer['price'] ?? null, 'Ausgangspunkt: ein Angebot');
        self::assertArrayNotHasKey('shippingDetails', $offer);
        self::assertArrayNotHasKey('hasMerchantReturnPolicy', $offer);
    }

    public function testOhneBestimmtenArtikelKeineAngebotsdetails(): void
    {
        $jsonLd = $this->render('https://example.org/produkt/schwedenbitter.html', shopRow: ['withdrawalDays' => 14, 'deliveryDaysMax' => 3])['jsonLd'];

        self::assertStringContainsString('AggregateOffer', $jsonLd);
        self::assertStringNotContainsString('MerchantReturnPolicy', $jsonLd);
    }

    public function testOhneAktionKeinStreichpreis(): void
    {
        $this->connection->insert('tl_consho_product_list_price', ['pid' => self::ML_750_VARIANT, 'currency' => 'EUR', 'country' => 'AT', 'net' => '24.1667', 'gross' => '29.0000', 'compareNet' => '30.0000', 'compareGross' => '36.0000']);

        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6')['data'];

        self::assertArrayNotHasKey('compare', $data['price']);
    }

    // ── Model choice (Abo-2, P10) ─────────────────────────────────────────

    public function testEinReinerEinmalkaufHatKeineModellwahl(): void
    {
        $this->subscriptionProduct();

        $data = $this->render('https://example.org/produkt/ebook.html', productId: 10, offers: $this->offersOf([0]))['data'];

        self::assertSame([], $data['plans']);
        self::assertSame('96,00 €', $this->spaces($data['price']['formatted']));
    }

    public function testEinmalkaufZuerstUndVorausgewaehltDannDieAbos(): void
    {
        $this->subscriptionProduct();

        $data = $this->render('https://example.org/produkt/ebook.html', productId: 10, offers: $this->offersOf([0, 5]))['data'];

        self::assertSame(
            [
                ['rule' => 0, 'label' => null, 'price' => '96,00 €', 'terms' => null, 'checked' => true],
                ['rule' => 5, 'label' => 'Monatsabo', 'price' => '1,20 €', 'terms' => 'Bedingungen 5 zu 1.2000', 'checked' => false],
            ],
            array_map(fn (array $plan): array => array_merge($plan, ['price' => $this->spaces($plan['price'])]), $data['plans']),
        );
        // At the top the page keeps showing the one-time purchase.
        self::assertSame('96,00 €', $this->spaces($data['price']['formatted']));
    }

    public function testEinReinesAboZeigtObenDasErsteModell(): void
    {
        $this->subscriptionProduct();

        $rendered = $this->render('https://example.org/produkt/ebook.html', productId: 10, offers: $this->offersOf([5]));

        self::assertSame([5], array_column($rendered['data']['plans'], 'rule'));
        self::assertTrue($rendered['data']['plans'][0]['checked']);
        self::assertSame('1,20 €', $this->spaces($rendered['data']['price']['formatted']));
        self::assertSame('Bedingungen 5 zu 1.2000', $rendered['data']['price']['terms']);
        // A monthly amount is not an offer price in the sense of schema.org.
        self::assertStringNotContainsString('"price"', $rendered['jsonLd']);
    }

    public function testEinModellOhnePreisBietetDieSeiteNichtAn(): void
    {
        $this->subscriptionProduct();

        $data = $this->render('https://example.org/produkt/ebook.html', productId: 10, offers: $this->offersOf([0, 6, 5]))['data'];

        self::assertSame([0, 5], array_column($data['plans'], 'rule'));
    }

    public function testEinUebriggebliebenerEinmalkaufPreisIstKeinAngebot(): void
    {
        // Abo-2: sold only as a subscription, the only subscription (rule
        // 6) without a price, and an old one-time purchase row is still there.
        // The page showed it as the price, it went into the markup too, and the
        // click ended in "nicht erhältlich" (not available).
        $this->subscriptionProduct();

        $rendered = $this->render('https://example.org/produkt/ebook.html', productId: 10, offers: $this->offersOf([6]));

        self::assertNull($rendered['data']['price']);
        self::assertSame([], $rendered['data']['plans']);
        self::assertStringNotContainsString('"price"', $rendered['jsonLd']);
    }

    public function testDieAngeboteFragenMitDerGruppe(): void
    {
        $this->subscriptionProduct();
        $this->netPriceGroup();

        $offers = $this->createMock(SubscriptionOffers::class);
        // The groups alone decide (P11; there is no business-customer lock any more).
        // Once: the page asks, and OneTimeOffer gets the answer (Connect-1).
        $offers->expects(self::once())->method('offered')->with(10, [3])->willReturn([0 => null]);

        $this->render('https://example.org/produkt/ebook.html', productId: 10, memberGroups: [3], offers: $offers);
    }

    /**
     * Block G (G7): a sold-out variant keeps its page and link, says so and
     * has no purchase button; the markup says OutOfStock. Without managed
     * stock the page says nothing at all.
     */
    public function testAusverkauftBleibtDieSeiteOhneKaufknopf(): void
    {
        $before = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6')['data'];
        self::assertNull($before['stock'], 'ohne Bestand kein Hinweis');
        self::assertSame(self::ML_750_VARIANT, $before['purchase']['articleId']);

        $this->connection->update('tl_consho_product', ['stockTracked' => 1], ['id' => self::PRODUCT]);
        (new StockRows($this->connection))->sync(self::PRODUCT, 100);
        $this->connection->update('tl_consho_stock', ['quantity' => '5.0000'], ['article' => self::ML_250_VARIANT]);
        $this->connection->update('tl_consho_stock', ['availableFrom' => 1_798_761_600], ['article' => self::ML_750_VARIANT]);

        $rendered = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6');
        $data = $rendered['data'];

        self::assertSame('unavailable', $data['stock']['state']);
        self::assertNotNull($data['stock']['availableFrom']);
        self::assertNull($data['purchase']['articleId']);
        self::assertStringContainsString('schema.org\/OutOfStock"', $rendered['jsonLd']);

        $options = [];

        foreach ($data['axes'] as $axis) {
            foreach ($axis->options as $option) {
                $options[$option->targetId ?? 0] = [$option->unavailable, null !== $option->url];
            }
        }

        self::assertSame([false, true], $options[self::ML_250_VARIANT]);
        self::assertSame([true, true], $options[self::ML_750_VARIANT], 'gekennzeichnet, aber weiter verlinkt');

        // Choice open: available as long as one variant is.
        self::assertStringContainsString('schema.org\/InStock"', $this->render('https://example.org/produkt/schwedenbitter.html')['jsonLd']);
    }

    /**
     * Block G (G12, G15): where the purchase button is missing, the wish
     * stands — for guests the notice to log in; the mail's link shows the
     * confirmation.
     */
    public function testBenachrichtigenWoNichtsMehrDaIst(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_stock_notice');
        $this->connection->update('tl_consho_product', ['stockTracked' => 1], ['id' => self::PRODUCT]);
        (new StockRows($this->connection))->sync(self::PRODUCT, 100);

        $guest = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6')['data'];
        self::assertSame(['articleId' => self::ML_750_VARIANT, 'main' => self::PRODUCT, 'pageId' => 123, 'state' => 'login'], $guest['stockNotice']);
        self::assertNull($guest['emailConfirm']);

        $this->connection->insert('tl_consho_stock_notice', ['article' => self::ML_750_VARIANT, 'main' => self::PRODUCT, 'member' => 5]);
        self::assertSame('subscribed', $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberId: 5)['data']['stockNotice']['state']);
        self::assertSame('subscribe', $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberId: 8)['data']['stockNotice']['state']);

        // Available: no wish.
        $this->connection->update('tl_consho_stock', ['quantity' => '2.0000'], ['article' => self::ML_750_VARIANT]);
        self::assertNull($this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberId: 5)['data']['stockNotice']);

        $confirmations = $this->createMock(GuestOrderConfirmation::class);
        $confirmations->expects(self::once())->method('state')->with('cosn-abc', 5, 'kundin@example.org', ConfirmationPurpose::StockNotice)->willReturn('pending');

        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6&consho_confirm=cosn-abc&consho_stock_notice=subscribed', memberId: 5, confirmations: $confirmations)['data'];
        self::assertSame('pending', $data['emailConfirm']['state']);
        self::assertSame('cosn-abc', $data['emailConfirm']['token']);
        self::assertSame(StockNoticeResult::Subscribed, $data['stockNoticeResult']);
    }

    public function testSolangeEineVarianteFehltGibtEsKeineModellwahl(): void
    {
        // Since Abo-2b it is asked anyway — per variant, for the from-price.
        self::assertSame([], $this->render('https://example.org/produkt/schwedenbitter.html', offers: $this->offersOf([0, 5]))['data']['plans']);
    }

    /**
     * Abo-2b: a variant product only as a subscription. The tile prints
     * "ab 1,20 € / Monat" (from € 1.20 / month); the page showed no price,
     * because the from-price only asked one-time purchase rows. Now the same
     * figure, with the terms of the cheapest variant — and none of it in the
     * markup.
     */
    public function testEinVariantenproduktNurImAboZeigtDenAbPreisSeinesModells(): void
    {
        // Variant 250 ml inherits rule 5 from the main product, 750 ml has its own.
        $this->insertPrice(['pid' => self::PRODUCT, 'net' => '1.0000', 'minQuantity' => '1.0000', 'subscriptionRule' => 5]);
        $this->insertPrice(['pid' => self::ML_750_VARIANT, 'net' => '2.0000', 'minQuantity' => '1.0000', 'subscriptionRule' => 5]);

        $rendered = $this->render('https://example.org/produkt/schwedenbitter.html', offers: $this->offersOf([5]));
        $price = $rendered['data']['price'];

        self::assertIsArray($price);
        self::assertSame('1,20 €', $this->spaces($price['formatted']));
        self::assertTrue($price['isFrom']);
        self::assertSame('Bedingungen 5 zu 1.2000', $price['terms']);
        self::assertStringNotContainsString('"price"', $rendered['jsonLd']);
        self::assertStringNotContainsString('"lowPrice"', $rendered['jsonLd']);
    }

    public function testEinMitgliedEinerNettogruppeSiehtNettoUndDenNettoHinweis(): void
    {
        // ⚠️ Through the controller, not past the resolver: a test that feeds
        // PriceAudienceResolver directly does not check whether the controller
        // asks it at all — and that is exactly what is new here.
        $this->netPriceGroup();

        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberGroups: [3])['data'];

        self::assertIsArray($data['price']);
        // 24.1667 net, rounded to the currency's digits — the same 29.00 gross
        // as for the guest, only without the 20 % in it.
        self::assertTrue($data['price']['showsNet']);
        self::assertSame('24,17 €', $this->spaces($data['price']['formatted']));
    }

    public function testEinMitgliedAusserhalbDerNettogruppeSiehtBrutto(): void
    {
        $this->netPriceGroup();

        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberGroups: [4])['data'];

        self::assertIsArray($data['price']);
        self::assertFalse($data['price']['showsNet']);
        self::assertSame('29,00 €', $this->spaces($data['price']['formatted']));
    }

    public function testEinGruppenpreisGiltAufDerDetailseite(): void
    {
        $this->netPriceGroup();
        $this->insertPrice(['pid' => self::ML_750_VARIANT, 'net' => '10.0000', 'currency' => 'EUR', 'minQuantity' => '1.0000', 'customerGroup' => 3]);

        // Gross, because the group is not in netPriceGroups: 10.00 + 20 %.
        // So the price changes, the display does not — the two questions are
        // separate, and this test keeps them apart.
        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberGroups: [4])['data'];

        self::assertIsArray($data['price']);
        self::assertFalse($data['price']['showsNet']);
        self::assertSame('29,00 €', $this->spaces($data['price']['formatted']));

        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberGroups: [3])['data'];

        self::assertTrue($data['price']['showsNet']);
        self::assertSame('10,00 €', $this->spaces($data['price']['formatted']));
    }

    public function testDerGrundpreisLiestSichWieDerPreisDarueber(): void
    {
        /*
         * ⚠️ C-4: `basePriceNet` exists in the code, and this is the test that
         * hits it.
         *
         * § 4 PAngV wants the unit price "in the same way" as the total
         * price. A gross price per litre under a net bottle price compares
         * nothing — and nobody would notice, because both figures are right
         * on their own.
         */
        $this->netPriceGroup();
        $this->connection->update(
            'tl_consho_product',
            ['unit' => 'ml', 'purchaseUnit' => '750.0000', 'referenceUnit' => '1000.0000'],
            ['id' => self::ML_750_VARIANT],
        );

        $brutto = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6')['data']['price'];
        $netto = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberGroups: [3])['data']['price'];

        self::assertIsArray($brutto['basePrice']);
        self::assertIsArray($netto['basePrice']);

        // 29.00 for 0.75 l → 38.67 per litre; net 24.17 → 32.22 per litre.
        self::assertSame('38,67 €', $this->spaces($brutto['basePrice']['formatted']));
        self::assertSame('32,22 €', $this->spaces($netto['basePrice']['formatted']));
    }

    public function testDieStaffelStufenLesenSichWieDerPreisDarueber(): void
    {
        // A gross scale under a net price would be a second, contradictory
        // offer on the same page.
        $this->netPriceGroup();
        $this->insertPrice(['pid' => self::ML_750_VARIANT, 'net' => '20.0000', 'currency' => 'EUR', 'minQuantity' => '10.0000']);

        $brutto = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6')['data']['price']['scale'];
        $netto = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberGroups: [3])['data']['price']['scale'];

        self::assertSame(['29,00 €', '24,00 €'], array_map(fn (array $s): string => $this->spaces($s['formatted']), $brutto));
        self::assertSame(['24,17 €', '20,00 €'], array_map(fn (array $s): string => $this->spaces($s['formatted']), $netto));
    }

    public function testDerAbPreisFolgtDemAngezeigtenBetrag(): void
    {
        // Without a chosen variant: "ab" (from) across all variants. This path
        // has to calculate net too, otherwise a gross from-price stands above a
        // net product page.
        $this->netPriceGroup();

        $brutto = $this->render('https://example.org/produkt/schwedenbitter.html')['data']['price'];
        $netto = $this->render('https://example.org/produkt/schwedenbitter.html', memberGroups: [3])['data']['price'];

        self::assertTrue($brutto['isFrom']);
        self::assertSame('13,00 €', $this->spaces($brutto['formatted']));

        self::assertTrue($netto['isFrom']);
        self::assertTrue($netto['showsNet']);
        // 13.00 gross = 10.8333 net, rounded to the currency's digits.
        self::assertSame('10,83 €', $this->spaces($netto['formatted']));
    }

    public function testDasMarkupBleibtBruttoAuchFuerEinNettoMitglied(): void
    {
        // schema.org `price` is the amount to pay including tax. A net figure
        // there would be a false statement, not another representation of a
        // true one.
        $this->netPriceGroup();

        $jsonLd = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberGroups: [3])['jsonLd'];

        self::assertStringContainsString('29.00', $jsonLd);
        self::assertStringNotContainsString('24.17', $jsonLd);
    }

    /**
     * Group 3 sees net, group 4 does not. Both exist.
     */
    /**
     * Product 10 "E-Book" without variants: one-time purchase 80.00 net,
     * monthly subscription (rule 5) 1.00 net, rule 6 without a price. Gross
     * with 20 %.
     */
    private function subscriptionProduct(): void
    {
        $this->insertProduct(['id' => 10, 'title' => 'E-Book', 'alias' => 'ebook', 'published' => 1]);
        $this->insertProductShop(['pid' => 10, 'shop' => 1]);
        $this->insertPrice(['pid' => 10, 'net' => '80.0000', 'minQuantity' => '1.0000']);
        $this->insertPrice(['pid' => 10, 'net' => '1.0000', 'minQuantity' => '1.0000', 'subscriptionRule' => 5]);
    }

    /**
     * @param list<int> $rules the offered models in this order; 0 = one-time purchase
     */
    private function offersOf(array $rules): SubscriptionOffers
    {
        return $this->offersPerArticle([], $rules);
    }

    /**
     * Offers of their own per article (Abo-2b) — otherwise "variant A once,
     * variant B only as a subscription" cannot be built.
     *
     * @param array<int, list<int>> $perArticle article ID => models; 0 = one-time purchase
     * @param list<int>             $default    for all other articles
     */
    private function offersPerArticle(array $perArticle, array $default): SubscriptionOffers
    {
        $build = static function (array $rules): array {
            $offered = [];

            foreach ($rules as $id) {
                $offered[$id] = 0 === $id ? null : new SubscriptionRule($id, 5 === $id ? 'Monatsabo' : 'Regel '.$id, 1, 'year', 1, RenewalKind::Indefinite, 14, 30, 14, []);
            }

            return $offered;
        };

        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->method('offered')->willReturnCallback(static fn (int $articleId): array => $build($perArticle[$articleId] ?? $default));

        return $offers;
    }

    /** Mixed: 250 ml only as a subscription and cheaper, 750 ml once. */
    public function testGemischteVariantenAboIstDerAbPreis(): void
    {
        $this->insertPrice(['pid' => self::PRODUCT, 'net' => '1.0000', 'minQuantity' => '1.0000', 'subscriptionRule' => 5]);

        $rendered = $this->render('https://example.org/produkt/schwedenbitter.html', offers: $this->offersPerArticle([self::ML_250_VARIANT => [5]], [0]));

        self::assertSame('1,20 €', $this->spaces($rendered['data']['price']['formatted']));
        self::assertSame('Bedingungen 5 zu 1.2000', $rendered['data']['price']['terms']);
        self::assertStringContainsString('29.00', $rendered['jsonLd'], 'im Markup nur der Einmalkauf der 750-ml-Variante');
        self::assertStringNotContainsString('1.20', $rendered['jsonLd']);
    }

    /** Mixed: the cheapest is the one-time purchase — no terms. */
    public function testGemischteVariantenEinmalkaufIstDerAbPreis(): void
    {
        $this->insertPrice(['pid' => self::ML_750_VARIANT, 'net' => '99.0000', 'minQuantity' => '1.0000', 'subscriptionRule' => 5]);

        $rendered = $this->render('https://example.org/produkt/schwedenbitter.html', offers: $this->offersPerArticle([self::ML_750_VARIANT => [5]], [0]));

        self::assertSame('13,00 €', $this->spaces($rendered['data']['price']['formatted']));
        self::assertNull($rendered['data']['price']['terms']);
    }

    /** Tie: like the tile — the one-time purchase first, even if the subscription comes first. */
    public function testGleichstandNimmtDenEinmalkaufWieDieKachel(): void
    {
        // 250 ml (first in the matrix) only as a subscription at 13.00 gross, 750 ml once at 13.00.
        $this->connection->update('tl_consho_price', ['net' => '10.8333'], ['pid' => self::ML_750_VARIANT, 'minQuantity' => '1.0000']);
        $this->insertPrice(['pid' => self::PRODUCT, 'net' => '10.8333', 'minQuantity' => '1.0000', 'subscriptionRule' => 5]);

        $rendered = $this->render('https://example.org/produkt/schwedenbitter.html', offers: $this->offersPerArticle([self::ML_250_VARIANT => [5]], [0]));

        self::assertSame('13,00 €', $this->spaces($rendered['data']['price']['formatted']));
        self::assertNull($rendered['data']['price']['terms'], 'gleiche Zahl, aber die Kachel sagt Einmalkauf');
    }

    private function netPriceGroup(): void
    {
        $this->connection->insert('tl_member_group', ['id' => 3, 'name' => 'Händler']);
        $this->connection->insert('tl_member_group', ['id' => 4, 'name' => 'Newsletter']);
        $this->connection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => 1]);
    }

    public function testEineAbgelehnteHinzufuegungWirdAusDerAdresseGelesen(): void
    {
        // C-3c: the add route sends the visitor back to this page with
        // ?consho_notice=<case> — here it becomes a CartNotice again.
        $result = $this->render('https://example.org/produkt/schwedenbitter.html?consho_notice=unavailable');

        self::assertSame(CartNotice::Unavailable, $result['data']['notice']);
    }

    public function testEinErfundenerGrundInDerAdresseErgibtKeineMeldung(): void
    {
        // Looked up strictly as an enum, never passed on as text: whatever does
        // not match one of our cases is null — and the template renders nothing
        // for null. Otherwise the address would be a way to put foreign text on
        // a cached page.
        foreach (['<script>', 'other_shop', '', 'UNAVAILABLE'] as $wert) {
            $result = $this->render('https://example.org/produkt/schwedenbitter.html?consho_notice='.rawurlencode($wert));

            self::assertNull($result['data']['notice'], sprintf('„%s" darf keine Meldung ergeben', $wert));
        }
    }

    public function testEinErfolgLaesstSichNichtUeberDieAdresseVortaeuschen(): void
    {
        // `Added` is a case of the same enum — a bare tryFrom() would accept it.
        // Then a shared link would show every visitor "wurde in den Warenkorb
        // gelegt" (was added to the cart) without anything having happened, and
        // the page would stay that way in the shared cache. The route never
        // writes this value into an address; it is not read either (C-3c).
        $result = $this->render('https://example.org/produkt/schwedenbitter.html?consho_notice=added');

        self::assertNull($result['data']['notice']);
    }

    public function testEinFeldStattEinesWertesErgibtKeineFehlerantwort(): void
    {
        // ?consho_notice[]=1 comes from crawlers. getString() answers that with
        // a 400 — a product page that fails on it only fills the error log.
        $result = $this->render('https://example.org/produkt/schwedenbitter.html?consho_notice%5B%5D=1');

        self::assertNull($result['data']['notice']);
        self::assertSame(200, $result['response']->getStatusCode());
    }

    public function testOhneParameterGibtEsKeineMeldung(): void
    {
        $result = $this->render('https://example.org/produkt/schwedenbitter.html');

        self::assertNull($result['data']['notice']);
    }

    public function testDerPreisStehtInDerWaehrungDesStartpunktsNichtInDerDesShops(): void
    {
        /*
         * Spec 4.0: the currency hangs off the start point, the home country
         * off the shop. The shop of this test is set to EUR and to Austria; the
         * start point sells in pounds. The euro prices stay next to them — if
         * the element took the shop's again, the from-price of € 13.00 would
         * stand here instead of the £ 24.00 from the pound scale.
         *
         * Only variant 6 gets a pound price: that makes "ab" (from) moot, and
         * the statement does not depend on an amount two rows share.
         */
        $this->insertPrice(['pid' => self::ML_750_VARIANT, 'net' => '20.0000', 'currency' => 'GBP', 'minQuantity' => '1.0000']);

        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', currency: 'GBP')['data'];

        // 20.00 net plus 20 % from Austria = 24.00 gross.
        self::assertIsArray($data['price']);
        self::assertSame('GBP', $data['price']['currency']);
        self::assertSame((new MoneyFormatter())->format('24.0000', 'GBP', 'de'), $data['price']['formatted']);
    }

    public function testEveryOptionLinksToAVisibleVariantAndTheUnpublishedOneToNothing(): void
    {
        $axes = $this->render('https://example.org/produkt/schwedenbitter.html')['data']['axes'];

        self::assertCount(1, $axes);
        self::assertInstanceOf(VariantAxisView::class, $axes[0]);
        self::assertSame('Füllmenge', $axes[0]->label);

        $urls = [];

        foreach ($axes[0]->options as $option) {
            $urls[$option->label] = $option->url;
        }

        // Path without host, like the tile links; the address itself comes from
        // ProductUrlGenerator. 1 l has only the unpublished variant 8.
        self::assertSame([
            '250 ml' => '/produkt/schwedenbitter.html?variant=3',
            '750 ml' => '/produkt/schwedenbitter.html?variant=6',
            '1 l' => null,
        ], $urls);
    }

    public function testTheHeadNamesTheProductAndItsCanonicalAddress(): void
    {
        $head = $this->render('https://example.org/produkt/schwedenbitter.html')['head'];

        self::assertSame('Schwedenbitter', $head->getTitle());
        self::assertSame('Nach alter Rezeptur', $head->getMetaDescription());
        self::assertSame('https://example.org/produkt/schwedenbitter.html', $head->getCanonicalUri());
    }

    public function testTheOnlyVisibleVariantIsTheArticleWithoutAChoice(): void
    {
        // Product 2 has exactly one variant — it is the purchasable unit.
        // No price row anywhere: no price area, the page stays up.
        $data = $this->render('https://example.org/produkt/kraeutersalz.html', productId: self::OTHER_PRODUCT)['data'];

        self::assertInstanceOf(ProductDetail::class, $data['product']);
        self::assertSame(self::OTHER_VARIANT, $data['product']->articleId);
        self::assertSame(self::OTHER_VARIANT, $data['purchase']['articleId']);
        self::assertNull($data['price']);
    }

    // ── With ?variant= ───────────────────────────────────────────────────

    public function testAChosenVariantInheritsTheWholePriceSetWithItsScale(): void
    {
        $result = $this->render('https://example.org/produkt/schwedenbitter.html?variant=3');
        $data = $result['data'];

        self::assertSame(self::ML_250_VARIANT, $data['product']->articleId);
        self::assertSame('SB-250', $data['product']->sku);

        self::assertFalse($data['price']['isFrom']);
        self::assertSame('13,00 €', $this->spaces($data['price']['formatted']));
        self::assertSame(
            [['quantity' => '1', 'formatted' => '13,00 €'], ['quantity' => '10', 'formatted' => '10,80 €']],
            array_map(fn (array $step): array => ['quantity' => $step['quantity'], 'formatted' => $this->spaces($step['formatted'])], $data['price']['scale']),
        );

        self::assertSame(self::ML_250_VARIANT, $data['purchase']['articleId']);
    }

    public function testAChosenVariantWithItsOwnPriceShowsThatOne(): void
    {
        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6')['data'];

        self::assertSame(self::ML_750_VARIANT, $data['product']->articleId);
        self::assertSame('29,00 €', $this->spaces($data['price']['formatted']));
        self::assertSame([], $data['price']['scale']);

        $selected = array_values(array_filter($data['axes'][0]->options, static fn ($option): bool => $option->selected));
        self::assertCount(1, $selected);
        self::assertSame('750 ml', $selected[0]->label);
    }

    public function testAVariantAddressIsCanonicalToItsProduct(): void
    {
        $head = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6')['head'];

        self::assertSame('Schwedenbitter', $head->getTitle());
        self::assertSame('https://example.org/produkt/schwedenbitter.html', $head->getCanonicalUri());
    }

    public function testAVariantAddressCarriesTheProductsMetaDescription(): void
    {
        // The field applies only to the main product. Even a meta description
        // entered on the variant or a short text of the variant does not change
        // the head — the address is canonical to the product.
        $this->connection->update('tl_consho_product', ['metaDescription' => 'Schwedenbitter nach Originalrezept'], ['id' => self::PRODUCT]);
        $this->connection->update('tl_consho_product', ['metaDescription' => 'Nur die große Flasche', 'teaser' => 'Variantenteaser'], ['id' => self::ML_750_VARIANT]);

        $head = $this->render('https://example.org/produkt/schwedenbitter.html?variant='.self::ML_750_VARIANT)['head'];

        self::assertSame('Schwedenbitter nach Originalrezept', $head->getMetaDescription());
    }

    public function testAChosenVariantInheritsTheProductsWarnings(): void
    {
        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=3')['data'];

        self::assertInstanceOf(ProductSafetyInfo::class, $data['safety']);
        self::assertSame('<p>Enthält Alkohol.</p>', $data['safety']->warnings);
        self::assertSame('Swiss Herbs AG', $data['safety']->manufacturerName);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesConshoNeverBuilds(): iterable
    {
        yield 'leer' => ['variant='];
        yield 'null' => ['variant=0'];
        yield 'Buchstaben' => ['variant=abc'];
        yield 'führende Null' => ['variant=03'];
        yield 'Ziffern mit Rest' => ['variant=3a'];
        yield 'negativ' => ['variant=-3'];
        yield 'Leerzeichen' => ['variant=%203'];
        yield 'Array' => ['variant[]=3'];
        yield 'Variante eines anderen Produkts' => ['variant=7'];
        yield 'das Hauptprodukt selbst' => ['variant=1'];
        yield 'unveröffentlichte Variante' => ['variant=8'];
        yield 'unbekannte ID' => ['variant=999'];
    }

    #[DataProvider('addressesConshoNeverBuilds')]
    public function testAVariantParameterConshoNeverBuildsIsNotFound(string $query): void
    {
        // 404, no redirect.
        $this->expectException(PageNotFoundException::class);

        $this->render('https://example.org/produkt/schwedenbitter.html?'.$query);
    }

    // ── Safety information in another language ──────────────────────────

    public function testAVariantWhoseOwnSafetyTextsAreNotTranslatedIsNotFoundInThatLanguage(): void
    {
        /*
         * Visibility decides per variant, display per field. Variant 6 has
         * safety information of its own, but no warnings of its own and no
         * French row. The resolver alone would deliver the inherited French
         * warning — the page must not even let it get that far.
         */
        $this->frenchProduct();
        $this->connection->update('tl_consho_product', ['safetyInformation' => '<p>Kühl lagern.</p>'], ['id' => self::ML_750_VARIANT]);

        $this->expectException(PageNotFoundException::class);

        $this->render('https://example.org/produkt/amer-suedois.html?variant='.self::ML_750_VARIANT, language: 'fr');
    }

    public function testAVariantWithoutOwnSafetyTextsShowsTheProductsTranslatedWarning(): void
    {
        // The counter-check: without texts of its own the main product's French
        // warning applies — never the German one.
        $this->frenchProduct();

        $data = $this->render('https://example.org/produkt/amer-suedois.html?variant='.self::ML_250_VARIANT, language: 'fr')['data'];

        self::assertInstanceOf(ProductSafetyInfo::class, $data['safety']);
        self::assertSame('<p>Contient de l’alcool.</p>', $data['safety']->warnings);
    }

    // ── Images ───────────────────────────────────────────────────────────

    public function testWithoutImagesTheStudioIsNotAsked(): void
    {
        $studio = $this->createMock(Studio::class);
        $studio->expects(self::never())->method('createFigureBuilder');

        $data = $this->render('https://example.org/produkt/schwedenbitter.html', studio: $studio)['data'];

        self::assertSame([], $data['figures']);
    }

    public function testImagesAreBuiltWithTheElementsSizeAndADeletedFileDropsOut(): void
    {
        $first = random_bytes(16);
        $second = random_bytes(16);
        $this->connection->update('tl_consho_product', ['images' => serialize([$first, $second])], ['id' => self::PRODUCT]);

        $size = ['', '', '5'];

        $builder = $this->createMock(FigureBuilder::class);
        $builder->expects(self::once())->method('setSize')->with($size)->willReturnSelf();
        $builder->expects(self::once())->method('setLightboxGroupIdentifier')->with('lb'.self::ELEMENT)->willReturnSelf();
        $builder->expects(self::once())->method('enableLightbox')->with(true)->willReturnSelf();
        $builder
            ->expects(self::exactly(2))
            ->method('fromUuid')
            ->willReturnCallback(static function (string $uuid) use ($builder, $first, $second): FigureBuilder {
                // In the stored order.
                static $expected = null;
                $expected ??= [StringUtil::binToUuid($first), StringUtil::binToUuid($second)];
                self::assertSame(array_shift($expected), $uuid);

                return $builder;
            })
        ;
        // Both files are missing in the file manager — no figure, no error.
        $builder->method('buildIfResourceExists')->willReturn(null);

        $studio = $this->createMock(Studio::class);
        $studio->method('createFigureBuilder')->willReturn($builder);

        $data = $this->render(
            'https://example.org/produkt/schwedenbitter.html',
            studio: $studio,
            element: ['conshoGallerySize' => serialize($size), 'fullsize' => '1'],
        )['data'];

        self::assertSame([], $data['figures']);
    }

    // ── GARAN label (Block GARAN, GA3) ───────────────────────────────────

    public function testDasLabelDesHauptproduktsStehtAufDerSeite(): void
    {
        $durability = $this->createMock(DurabilityLabelImages::class);
        $durability->method('of')->with([self::PRODUCT])->willReturn([self::PRODUCT => ['years' => 5, 'src' => 'files/garan.png', 'width' => 300, 'height' => 316]]);

        $data = $this->render('https://example.org/produkt/schwedenbitter.html', durability: $durability)['data'];

        self::assertSame(['years' => 5, 'src' => 'files/garan.png', 'width' => 300, 'height' => 316], $data['garan']);
    }

    public function testOhneGarantieKeinLabel(): void
    {
        self::assertNull($this->render('https://example.org/produkt/schwedenbitter.html')['data']['garan']);
    }

    // ── Partial template of the product type ─────────────────────────────

    public function testTheTypesPartialReachesTheTemplateAsATwigPath(): void
    {
        $this->insertProductType(['id' => 5, 'title' => 'Bitter', 'detailPartial' => 'consho_product_type/bitter']);
        $this->connection->update('tl_consho_product', ['productType' => 5], ['id' => self::PRODUCT]);

        $data = $this->render('https://example.org/produkt/schwedenbitter.html')['data'];

        self::assertSame('@Contao/consho_product_type/bitter.html.twig', $data['partial']);
    }

    public function testAProductWithoutAPartialHasNone(): void
    {
        self::assertSame('', $this->render('https://example.org/produkt/schwedenbitter.html')['data']['partial']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function storedPartials(): iterable
    {
        yield 'Variante der Kennung' => ['consho_product_type/wein', '@Contao/consho_product_type/wein.html.twig'];
        yield 'Unterordner' => ['consho_product_type/getraenke/wein', '@Contao/consho_product_type/getraenke/wein.html.twig'];

        // Contao's finder forms the identifier from the file name, capital
        // letters included. Whoever creates "Wein.html.twig" should see it rendered.
        yield 'Großbuchstaben' => ['consho_product_type/Wein', '@Contao/consho_product_type/Wein.html.twig'];

        yield 'leer' => ['', ''];
        yield 'nur die Kennung' => ['consho_product_type', ''];
        yield 'Kennung mit Schrägstrich' => ['consho_product_type/', ''];
        yield 'leeres Segment' => ['consho_product_type//wein', ''];
        yield 'aus dem Ordner heraus' => ['consho_product_type/../content_element/text', ''];
        yield 'fremde Vorlage' => ['content_element/text', ''];
        yield 'ähnlicher Name' => ['consho_product_types/wein', ''];
        yield 'mit Endung' => ['consho_product_type/wein.html.twig', ''];
    }

    #[DataProvider('storedPartials')]
    public function testOnlyAVariantOfTheTypeIdentifierBecomesAPartial(string $stored, string $expected): void
    {
        self::assertSame($expected, ProductDetailController::partial($stored));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * A French start point in the same shop and a complete French
     * translation of the main product, including the warning.
     */
    private function frenchProduct(): void
    {
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'language' => 'fr', 'consho_shop' => 1]);
        $this->insertTranslation([
            'ptable' => 'tl_consho_product',
            'pid' => self::PRODUCT,
            'language' => 'fr',
            'title' => 'Amer suédois',
            'alias' => 'amer-suedois',
            'safetyWarnings' => '<p>Contient de l’alcool.</p>',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertVariant(int $id, int $pid, int $optionId, int $sorting, array $overrides = []): void
    {
        $this->insertProduct(['id' => $id, 'pid' => $pid, 'title' => 'Variante '.$id, 'sorting' => $sorting, 'published' => 1, ...$overrides]);
        $this->insertProductOption(['pid' => $id, 'optionId' => $optionId]);
    }

    // ── JSON-LD (B-6) ───────────────────────────────────────────────────

    public function testAnOpenVariantChoiceIsMarkedUpAsAnAggregateOffer(): void
    {
        // The same prices as the from-price: variant 3 inherits € 13.00, variant 6 costs € 29.00.
        $product = $this->productMarkup($this->render('https://example.org/produkt/schwedenbitter.html')['jsonLd']);

        self::assertNotNull($product);
        self::assertSame('AggregateOffer', $product['offers']['@type'] ?? null);
        self::assertSame('13.00', $product['offers']['lowPrice'] ?? null);
        self::assertSame('29.00', $product['offers']['highPrice'] ?? null);
        self::assertSame(2, $product['offers']['offerCount'] ?? null, 'Die unveröffentlichte Variante 8 zählt nicht.');
        self::assertSame('https://example.org/produkt/schwedenbitter.html', $product['offers']['url'] ?? null);
        self::assertArrayNotHasKey('gtin', $product, 'Ohne gewählte Variante keine GTIN — sie wird nie vererbt.');
    }

    public function testAChosenVariantIsMarkedUpWithItsOwnOffer(): void
    {
        $uri = 'https://example.org/produkt/schwedenbitter.html?variant='.self::ML_750_VARIANT;

        $product = $this->productMarkup($this->render($uri)['jsonLd']);

        self::assertNotNull($product);
        self::assertSame('Offer', $product['offers']['@type'] ?? null);
        self::assertSame('29.00', $product['offers']['price'] ?? null);
        self::assertSame($uri, $product['offers']['url'] ?? null);
    }

    public function testAProductWithoutAPriceGetsNoMarkup(): void
    {
        // E3: product 2 has exactly one variant and no price.
        self::assertNull($this->productMarkup($this->render('https://example.org/produkt/anderes.html', productId: self::OTHER_PRODUCT)['jsonLd']));
    }

    /**
     * @return array<string, mixed>|null the Product node in the page's schema.org graph
     */
    private function productMarkup(string $script): ?array
    {
        $json = json_decode(trim(strip_tags($script)), true);
        $nodes = \is_array($json) ? ($json['@graph'] ?? [$json]) : [];

        foreach (\is_array($nodes) ? $nodes : [] as $node) {
            if (\is_array($node) && 'Product' === ($node['@type'] ?? null)) {
                return $node;
            }
        }

        return null;
    }

    /**
     * NumberFormatter separates amount and currency with a non-breaking
     * space. For the comparisons here only what is shown counts.
     */
    private function spaces(string $formatted): string
    {
        return str_replace(["\u{a0}", "\u{202f}"], ' ', $formatted);
    }

    public function testThePageCarriesTheCatalogueAndItsMainProductTag(): void
    {
        // B-7b: the detail page once stayed stale after a title change.
        self::assertSame([CatalogCacheTags::CATALOG, 'contao.db.tl_consho_product.1'], $this->render('/produkt/schwedenbitter.html')['tags']);
    }

    public function testWithoutAProductNothingIsTagged(): void
    {
        self::assertSame([], $this->render('/produkt/schwedenbitter.html', productId: null)['tags']);
    }

    public function testThePageLivesUntilItsNextScheduledChange(): void
    {
        // B-7b, E2: if publication ends in an hour, the cache must not hold longer.
        $this->connection->update('tl_consho_product', ['stop' => (string) (time() + 3600)], ['id' => self::PRODUCT]);

        $maxAge = $this->render('/produkt/schwedenbitter.html')['response']->getMaxAge();

        self::assertNotNull($maxAge);
        self::assertGreaterThanOrEqual(3590, $maxAge);
        self::assertLessThanOrEqual(3600, $maxAge);
    }

    public function testWithoutAScheduledChangeTheLifetimeIsLeftToThePage(): void
    {
        self::assertNull($this->render('/produkt/schwedenbitter.html')['response']->getMaxAge());
    }

    /**
     * A logged-in member with these groups, or nobody.
     *
     * A real FrontendUser instead of a mock, as in CartResolverTest: the
     * resolver decides on `instanceof FrontendUser`, and a mock of another
     * class would bypass this branch.
     *
     * @param list<int>|null $groups
     */
    private function tokenStorage(array|null $groups): TokenStorageInterface
    {
        $storage = new TokenStorage();

        if (null === $groups) {
            return $storage;
        }

        $user = (new \ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
        $user->id = 1;
        $user->username = 'member1';
        $user->groups = serialize(array_map('strval', $groups));

        $storage->setToken(new UsernamePasswordToken($user, 'frontend'));

        return $storage;
    }

    /**
     * @param array<string, mixed> $element
     * @param list<int>|null       $memberGroups null = nobody logged in (C-4)
     *
     * @return array{data: array<string, mixed>, head: HtmlHeadBag, jsonLd: string, tags: list<string>, response: Response}
     */
    private function render(
        string $uri,
        ?int $productId = self::PRODUCT,
        int $shopOfRoot = 1,
        ?Studio $studio = null,
        array $element = [],
        string $language = 'de',
        string $currency = 'EUR',
        array|null $memberGroups = null,
        SubscriptionOffers|null $offers = null,
        int|null $memberId = null,
        GuestOrderConfirmation|null $confirmations = null,
        DurabilityLabelImages|null $durability = null,
        array $shopRow = [],
        ArticleShippingRates|null $shippingRates = null,
        ArticleShippingDataInterface|null $articles = null,
    ): array {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => 123, 'type' => 'regular', 'rootId' => 1, 'language' => $language][$key] ?? null);

        // `consho_currency` is only in the row of the START POINT, not in that
        // of the product page below it: the column is only maintained at the top,
        // and a double that handed it out everywhere would be more capable than
        // the original and leave the tree walk unchecked.
        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => 1, 'type' => 'root', 'rootId' => 1, 'language' => $language][$key] ?? null);
        $root->method('row')->willReturn(['consho_shop' => $shopOfRoot, 'consho_currency' => $currency]);

        $productPage = $this->createMock(PageModel::class);
        $productPage->method('row')->willReturn(['conshoPathTemplate' => '{alias}']);

        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => 1, 'currency' => 'EUR', 'country' => 'AT'][$key] ?? null);
        $shop->method('row')->willReturn(['id' => 1, 'currency' => 'EUR', 'country' => 'AT', ...$shopRow]);

        $pages = [123 => $productPage, 1 => $root];

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(
            static fn (string $name, array $args): ?PageModel => \in_array($name, ['findById', 'findByPk'], true) ? ($pages[$args[0]] ?? null) : null,
        );

        $shopAdapter = $this->createMock(Adapter::class);
        $shopAdapter->method('__call')->willReturnCallback(
            static fn (string $name, array $args): ?ShopModel => 'findByPk' === $name && 1 === $args[0] ? $shop : null,
        );

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => ShopModel::class === $class ? $shopAdapter : $pageAdapter,
        );

        // Like Symfony's generator: {alias} is known to the route, everything
        // else is appended as a query.
        $contentUrls = $this->createMock(ContentUrlGenerator::class);
        $contentUrls->method('generate')->willReturnCallback(
            static function (object $content, array $values, int $referenceType): string {
                $alias = $values['alias'] ?? '';
                unset($values['alias']);

                $path = '/produkt/'.$alias.'.html'.([] === $values ? '' : '?'.http_build_query($values));

                return UrlGeneratorInterface::ABSOLUTE_PATH === $referenceType ? $path : 'https://example.org'.$path;
            },
        );

        $productPages = $this->createMock(ProductPageRepositoryInterface::class);
        $productPages->method('findForRootPage')->willReturn(123);
        $productPages->method('urlValues')->willReturnCallback(
            fn (int $id): array => ['alias' => (string) $this->connection->fetchOne('SELECT alias FROM tl_consho_product WHERE id = ?', [$id])],
        );

        $shops = new ShopResolver($framework);
        $languages = new ShopLanguages($this->connection);
        $urls = new ProductUrlGenerator($contentUrls, $productPages, $framework, $shops, $languages);

        $head = (new HtmlHeadBag())->setTitle('Produkt')->setMetaDescription('Unsere Produkte');
        $context = new ResponseContext();
        $context->add($head);
        $context->add(new JsonLdManager($context));

        $request = Request::create($uri);
        $request->attributes->set('pageModel', $page);
        $request->attributes->set(ResponseContext::REQUEST_ATTRIBUTE_NAME, $context);

        if (null !== $productId) {
            $request->attributes->set(ProductPageController::PRODUCT_ATTRIBUTE, $productId);
        }

        $stack = new RequestStack();
        $stack->push($request);

        $insertTags = $this->createMock(InsertTagParser::class);
        $insertTags->method('replaceInline')->willReturnArgument(0);

        $prices = new DbalPriceRepository($this->connection);
        $resolver = new ArticlePriceResolver(new PriceCalculator($prices), $prices, new BasePriceCalculator(), $this->grossCalculator(), new DbalArticleTaxClasses($this->connection));

        // The sentence itself is checked in SubscriptionTermsTest; what counts
        // here is which rule and which amount go into it.
        $lineTerms = $this->createMock(SubscriptionLineTerms::class);
        $lineTerms->method('of')->willReturnCallback(
            static fn (int $rule, string $amount): string => 'Bedingungen '.$rule.' zu '.$amount,
        );

        $controller = new class(
            $shops,
            $languages,
            new ProductDetailRepository($this->connection),
            new VariantRepository($this->connection, new VisibilityCriteria()),
            $resolver,
            new OneTimeOffer($resolver, $offers ?? $this->soldOnce(), new ComparePrices($this->connection), $this->grossCalculator(), new DbalArticleTaxClasses($this->connection)),
            new PriceAudienceResolver($this->tokenStorage($memberGroups), $this->connection),
            new MoneyFormatter(),
            new ProductSafetyResolver(new DbalProductSafetyRepository($this->connection)),
            $urls,
            new ProductHead(new ResponseContextAccessor($stack), $urls, new HtmlDecoder($insertTags)),
            $studio ?? $this->createMock(Studio::class),
            new ProductJsonLd(new ResponseContextAccessor($stack), new HtmlDecoder($insertTags)),
            new DbalCatalogSchedule($this->connection),
            $offers,
            $lineTerms,
            $articles,
            new Availability($this->connection),
            $this->security($memberId),
            new StockNotices($this->connection, new Availability($this->connection)),
            $confirmations,
            null,
            $durability,
            $shippingRates,
        ) extends ProductDetailController {
            public function respond(FragmentTemplate $template, ContentModel $model, Request $request): Response
            {
                return $this->getResponse($template, $model, $request);
            }
        };

        $model = $this->createMock(ContentModel::class);
        $model->method('row')->willReturn(['id' => self::ELEMENT, 'conshoGallerySize' => '', 'fullsize' => '', ...$element]);
        $model->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => self::ELEMENT, 'fullsize' => '', ...$element][$key] ?? null);

        // tagResponse() reaches the tag manager through the controller's service locator.
        $tags = [];
        $tagManager = $this->createMock(CacheTagManager::class);
        $tagManager->method('tagWith')->willReturnCallback(static function (array|object|string|null $target) use (&$tags): void {
            $tags = [...$tags, ...(array) $target];
        });

        $container = new Container();
        $container->set('contao.cache.tag_manager', $tagManager);
        $controller->setContainer($container);

        $template = new FragmentTemplate('content_element/consho_product_detail', static fn (): Response => new Response());

        $response = $controller->respond($template, $model, $request);

        return ['data' => $template->getData(), 'head' => $head, 'jsonLd' => $context->get(JsonLdManager::class)->collectFinalScriptFromGraphs(), 'tags' => $tags, 'response' => $response];
    }

    /**
     * The catalogue reader over the same database and the same price
     * building blocks as render() (Connect-1, K2).
     */
    private function catalogReader(): CatalogReader
    {
        $prices = new DbalPriceRepository($this->connection);
        $taxClasses = new DbalArticleTaxClasses($this->connection);
        $resolver = new ArticlePriceResolver(new PriceCalculator($prices), $prices, new BasePriceCalculator(), $this->grossCalculator(), $taxClasses);

        $insertTags = $this->createMock(InsertTagParser::class);
        $insertTags->method('replaceInline')->willReturnArgument(0);

        $addresses = $this->createMock(CatalogAddressesInterface::class);
        $addresses->method('images')->willReturn([]);

        return new CatalogReader(
            new ProductQuery($this->connection),
            new ProductDetailRepository($this->connection),
            new VariantRepository($this->connection, new VisibilityCriteria()),
            new ProductSafetyResolver(new DbalProductSafetyRepository($this->connection)),
            new Availability($this->connection),
            new DbalArticleShippingData($this->connection),
            $addresses,
            new HtmlDecoder($insertTags),
            $this->connection,
            new OneTimeOffer($resolver, $this->soldOnce(), new ComparePrices($this->connection), $this->grossCalculator(), $taxClasses),
        );
    }

    /**
     * Without the plans of Abo-2 every article is sold once — what the page
     * did before, when SubscriptionOffers was optional.
     */
    private function soldOnce(): SubscriptionOffers
    {
        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->method('offered')->willReturn([0 => null]);

        return $offers;
    }

    private function security(int|null $memberId): Security
    {
        $user = null;

        if (null !== $memberId) {
            $user = $this->createMock(FrontendUser::class);
            $user->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => $memberId, 'email' => 'kundin@example.org'][$key] ?? null);
        }

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

}

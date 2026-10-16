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
 * Das Element mit echten Zulieferern gegen SQLite — Repositories, Varianten-
 * matrix, Preisrechner, Sicherheitsangaben, ProductUrlGenerator und
 * ProductHead. Gedoppelt sind nur Contaos Rahmen (Seiten- und Shopmodell,
 * ContentUrlGenerator, Bildstudio).
 *
 * Abweichung vom Plan (Task 10, Step 4 „kein Unit-Test für den Controller"):
 * FragmentTemplate ist zwar final, lässt sich aber mit einer Closure bauen —
 * die Template-Daten sind damit prüfbar, ohne Twig und ohne Container. Die
 * 404-Regeln für `?variant=` sind zu leicht still falsch, um sie nur im
 * Browser abzuhaken.
 *
 * Grundbestand wie auf dem Testserver: Seite 123 „Produkt" unter Startpunkt 1
 * (de, Shop 1, EUR). Produkt 1 „Schwedenbitter" mit den Varianten 3 (250 ml,
 * erbt 13,00 € ab 1 und 10,80 € ab 10) und 6 (750 ml, eigener Preis 29,00 €),
 * dazu Variante 8 (1 l, unveröffentlicht). Produkt 2 mit genau einer Variante 7
 * und ohne Preis.
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

        // ShopLanguages liest die Sprachen aus den Startpunkten.
        $this->connection->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                type TEXT NOT NULL DEFAULT '',
                language TEXT NOT NULL DEFAULT '',
                consho_shop INTEGER NOT NULL DEFAULT 0
            )",
        );
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'language' => 'de', 'consho_shop' => 1]);

        $this->insertShop(['id' => 1, 'title' => 'Shop', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);

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

        // Staffel am Hauptprodukt; Variante 3 hat keine eigene Zeile und erbt
        // den ganzen Satz, Variante 6 hat einen eigenen Preis.
        $this->insertPrice(['pid' => self::PRODUCT, 'net' => '10.8333', 'minQuantity' => '1.0000']);
        $this->insertPrice(['pid' => self::PRODUCT, 'net' => '9.0000', 'minQuantity' => '10.0000']);
        $this->insertPrice(['pid' => self::ML_750_VARIANT, 'net' => '24.1667', 'minQuantity' => '1.0000']);

        $this->insertProduct(['id' => self::OTHER_PRODUCT, 'title' => 'Kräutersalz', 'alias' => 'kraeutersalz', 'published' => 1]);
        $this->insertProductShop(['pid' => self::OTHER_PRODUCT, 'shop' => 1]);
        $this->insertVariant(self::OTHER_VARIANT, self::OTHER_PRODUCT, self::ML_250, 1);
    }

    // ── Ohne Produktseite ────────────────────────────────────────────────

    public function testWithoutTheProductAttributeTheElementRendersNothing(): void
    {
        // Das Element steht auf einer anderen Seite oder in einem Layout-Bereich.
        // Auch ein unsinniges ?variant= darf dort keine 404 auslösen.
        $result = $this->render('https://example.org/impressum.html?variant=abc', productId: null);

        self::assertNull($result['data']['product']);
        self::assertSame('', $result['head']->getCanonicalUri());
    }

    public function testOutsideAShopTheElementRendersNothing(): void
    {
        $result = $this->render('https://example.org/produkt/schwedenbitter.html', shopOfRoot: 0);

        self::assertNull($result['data']['product']);
    }

    // ── Ohne Variantenwahl ───────────────────────────────────────────────

    public function testWithoutAChoiceThePageShowsTheProductWithAFromPrice(): void
    {
        $result = $this->render('https://example.org/produkt/schwedenbitter.html');
        $data = $result['data'];

        self::assertInstanceOf(ProductDetail::class, $data['product']);
        self::assertSame(self::PRODUCT, $data['product']->articleId);

        // Keine Vorwahl (Entscheidung 3): Die Varianten kosten verschieden, also
        // „ab" mit dem günstigsten Preis — und keine Staffel, weil noch kein
        // Artikel feststeht.
        self::assertIsArray($data['price']);
        self::assertTrue($data['price']['isFrom']);
        self::assertSame('13,00 €', $this->spaces($data['price']['formatted']));
        self::assertSame([], $data['price']['scale']);

        // Der Kaufbereich kennt noch keinen Artikel.
        self::assertNull($data['purchase']['articleId']);
    }

    public function testEinGastSiehtBruttoUndDenBruttoHinweis(): void
    {
        // Der Ausgangspunkt, gegen den die drei Tests darunter messen. Ohne
        // ihn wäre jedes „netto" auch mit einem kaputten Resolver grün.
        $this->netPriceGroup();

        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6')['data'];

        self::assertIsArray($data['price']);
        self::assertFalse($data['price']['showsNet']);
        self::assertSame('29,00 €', $this->spaces($data['price']['formatted']));
    }

    /**
     * Block H (H-2): eine Aktion für alle, und die Projektion kennt ihren
     * Bezugspreis — die Seite streicht ihn durch. Ohne Aktion nicht.
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
     * in the markup (Fable review Connect-1, W4 — the branch had no test).
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

    // ── Modellwahl (Abo-2, P10) ───────────────────────────────────────────

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
        // Die Seite zeigt oben weiter den Einmalkauf.
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
        // Ein Monatsbetrag ist kein Angebotspreis im Sinne von schema.org.
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
        // Fable-Review Abo-2, L4a: nur im Abo verkauft, das einzige Abo (Regel
        // 6) ohne Preis, und eine alte Einmalkauf-Zeile steht noch da. Die
        // Seite zeigte sie als Preis, ins Markup kam sie auch, und der Klick
        // endete in „nicht erhältlich".
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
        // Die Gruppen allein entscheiden (P11; seit Variante A keine Geschäftskunden-Sperre mehr).
        // Once: the page asks, and OneTimeOffer gets the answer (review Connect-1, H5).
        $offers->expects(self::once())->method('offered')->with(10, [3])->willReturn([0 => null]);

        $this->render('https://example.org/produkt/ebook.html', productId: 10, memberGroups: [3], offers: $offers);
    }

    /**
     * Block G (G7): Eine ausverkaufte Variante behält Seite und Link, sagt es
     * und hat keinen Kaufknopf; das Markup sagt OutOfStock. Ohne geführten
     * Bestand sagt die Seite gar nichts.
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

        // Wahl offen: lieferbar, solange eine Variante es ist.
        self::assertStringContainsString('schema.org\/InStock"', $this->render('https://example.org/produkt/schwedenbitter.html')['jsonLd']);
    }

    /**
     * Block G (G12, G15): Wo der Kaufknopf fehlt, steht der Wunsch — für Gäste
     * der Hinweis, sich anzumelden; der Link der Mail zeigt das Bestätigen.
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

        // Lieferbar: kein Wunsch.
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
        // Gefragt wird seit Abo-2b trotzdem — je Variante, für den Ab-Preis.
        self::assertSame([], $this->render('https://example.org/produkt/schwedenbitter.html', offers: $this->offersOf([0, 5]))['data']['plans']);
    }

    /**
     * Fable-Review Abo-2b, S1: ein Variantenprodukt nur im Abo. Die Kachel
     * druckt „ab 1,20 € / Monat"; die Seite zeigte keinen Preis, weil der
     * Ab-Preis nur Einmalkauf-Zeilen fragte. Jetzt dieselbe Zahl, mit den
     * Bedingungen der billigsten Variante — und nichts davon im Markup.
     */
    public function testEinVariantenproduktNurImAboZeigtDenAbPreisSeinesModells(): void
    {
        // Variante 250 ml erbt Regel 5 vom Hauptprodukt, 750 ml hat eine eigene.
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
        // ⚠️ Durch den Controller, nicht am Resolver vorbei (Regel 24): Ein
        // Test, der PriceAudienceResolver direkt füttert, prüft nicht, ob der
        // Controller ihn überhaupt fragt — und genau das ist hier neu.
        $this->netPriceGroup();

        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberGroups: [3])['data'];

        self::assertIsArray($data['price']);
        // 24,1667 netto, gerundet auf die Stelle der Währung — dieselben
        // 29,00 brutto wie beim Gast, nur ohne die 20 % darin.
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

        // Brutto, weil die Gruppe nicht in netPriceGroups steht: 10,00 + 20 %.
        // Der Preis ändert sich also, die Darstellung nicht — die beiden
        // Fragen sind getrennt, und dieser Test hält sie auseinander.
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
         * ⚠️ Im Abschlussreview C-4 als Prüflücke gemeldet: `basePriceNet` gab
         * es im Code, aber keinen einzigen Treffer in den Tests.
         *
         * § 4 PAngV will den Grundpreis „in derselben Weise" wie den
         * Gesamtpreis. Ein Brutto-Literpreis unter einem Netto-Flaschenpreis
         * vergleicht nichts — und fiele niemandem auf, weil beide Zahlen für
         * sich stimmen.
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

        // 29,00 auf 0,75 l → 38,67 je Liter; netto 24,17 → 32,22 je Liter.
        self::assertSame('38,67 €', $this->spaces($brutto['basePrice']['formatted']));
        self::assertSame('32,22 €', $this->spaces($netto['basePrice']['formatted']));
    }

    public function testDieStaffelStufenLesenSichWieDerPreisDarueber(): void
    {
        // Eine Staffel in brutto unter einem Netto-Preis wäre ein zweites,
        // widersprechendes Angebot auf derselben Seite.
        $this->netPriceGroup();
        $this->insertPrice(['pid' => self::ML_750_VARIANT, 'net' => '20.0000', 'currency' => 'EUR', 'minQuantity' => '10.0000']);

        $brutto = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6')['data']['price']['scale'];
        $netto = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberGroups: [3])['data']['price']['scale'];

        self::assertSame(['29,00 €', '24,00 €'], array_map(fn (array $s): string => $this->spaces($s['formatted']), $brutto));
        self::assertSame(['24,17 €', '20,00 €'], array_map(fn (array $s): string => $this->spaces($s['formatted']), $netto));
    }

    public function testDerAbPreisFolgtDemAngezeigtenBetrag(): void
    {
        // Ohne gewählte Variante: „ab" über alle Varianten. Auch dieser Weg
        // muss netto rechnen, sonst steht über einer Netto-Produktseite ein
        // Brutto-„ab"-Preis.
        $this->netPriceGroup();

        $brutto = $this->render('https://example.org/produkt/schwedenbitter.html')['data']['price'];
        $netto = $this->render('https://example.org/produkt/schwedenbitter.html', memberGroups: [3])['data']['price'];

        self::assertTrue($brutto['isFrom']);
        self::assertSame('13,00 €', $this->spaces($brutto['formatted']));

        self::assertTrue($netto['isFrom']);
        self::assertTrue($netto['showsNet']);
        // 13,00 brutto = 10,8333 netto, auf die Stelle der Währung gerundet.
        self::assertSame('10,83 €', $this->spaces($netto['formatted']));
    }

    public function testDasMarkupBleibtBruttoAuchFuerEinNettoMitglied(): void
    {
        // schema.org `price` ist der zu zahlende Betrag einschließlich Steuer.
        // Eine Netto-Zahl dort wäre eine falsche Aussage, keine andere
        // Darstellung einer richtigen.
        $this->netPriceGroup();

        $jsonLd = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', memberGroups: [3])['jsonLd'];

        self::assertStringContainsString('29.00', $jsonLd);
        self::assertStringNotContainsString('24.17', $jsonLd);
    }

    /**
     * Gruppe 3 sieht netto, Gruppe 4 nicht. Beide existieren.
     */
    /**
     * Produkt 10 „E-Book" ohne Varianten: Einmalkauf 80,00 netto, Monatsabo
     * (Regel 5) 1,00 netto, Regel 6 ohne Preis. Brutto mit 20 %.
     */
    private function subscriptionProduct(): void
    {
        $this->insertProduct(['id' => 10, 'title' => 'E-Book', 'alias' => 'ebook', 'published' => 1]);
        $this->insertProductShop(['pid' => 10, 'shop' => 1]);
        $this->insertPrice(['pid' => 10, 'net' => '80.0000', 'minQuantity' => '1.0000']);
        $this->insertPrice(['pid' => 10, 'net' => '1.0000', 'minQuantity' => '1.0000', 'subscriptionRule' => 5]);
    }

    /**
     * @param list<int> $rules die angebotenen Modelle in dieser Reihenfolge; 0 = Einmalkauf
     */
    private function offersOf(array $rules): SubscriptionOffers
    {
        return $this->offersPerArticle([], $rules);
    }

    /**
     * Je Artikel eigene Angebote (zweiter Fable-Review Abo-2b, 3) — sonst
     * lässt sich „Variante A einmalig, Variante B nur im Abo" nicht bauen.
     *
     * @param array<int, list<int>> $perArticle Artikel-ID => Modelle; 0 = Einmalkauf
     * @param list<int>             $default    für alle anderen Artikel
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

    /** Gemischt: 250 ml nur im Abo und billiger, 750 ml einmalig. */
    public function testGemischteVariantenAboIstDerAbPreis(): void
    {
        $this->insertPrice(['pid' => self::PRODUCT, 'net' => '1.0000', 'minQuantity' => '1.0000', 'subscriptionRule' => 5]);

        $rendered = $this->render('https://example.org/produkt/schwedenbitter.html', offers: $this->offersPerArticle([self::ML_250_VARIANT => [5]], [0]));

        self::assertSame('1,20 €', $this->spaces($rendered['data']['price']['formatted']));
        self::assertSame('Bedingungen 5 zu 1.2000', $rendered['data']['price']['terms']);
        self::assertStringContainsString('29.00', $rendered['jsonLd'], 'im Markup nur der Einmalkauf der 750-ml-Variante');
        self::assertStringNotContainsString('1.20', $rendered['jsonLd']);
    }

    /** Gemischt: die billigste ist der Einmalkauf — keine Bedingungen. */
    public function testGemischteVariantenEinmalkaufIstDerAbPreis(): void
    {
        $this->insertPrice(['pid' => self::ML_750_VARIANT, 'net' => '99.0000', 'minQuantity' => '1.0000', 'subscriptionRule' => 5]);

        $rendered = $this->render('https://example.org/produkt/schwedenbitter.html', offers: $this->offersPerArticle([self::ML_750_VARIANT => [5]], [0]));

        self::assertSame('13,00 €', $this->spaces($rendered['data']['price']['formatted']));
        self::assertNull($rendered['data']['price']['terms']);
    }

    /** Gleichstand: wie die Kachel — der Einmalkauf zuerst, auch wenn das Abo vorne steht. */
    public function testGleichstandNimmtDenEinmalkaufWieDieKachel(): void
    {
        // 250 ml (zuerst in der Matrix) nur im Abo zu 13,00 brutto, 750 ml einmalig zu 13,00.
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
        // C-3c: Die Add-Route schickt den Besucher mit ?consho_notice=<fall>
        // zurück auf diese Seite — hier wird daraus wieder ein CartNotice.
        $result = $this->render('https://example.org/produkt/schwedenbitter.html?consho_notice=unavailable');

        self::assertSame(CartNotice::Unavailable, $result['data']['notice']);
    }

    public function testEinErfundenerGrundInDerAdresseErgibtKeineMeldung(): void
    {
        // Streng als Aufzählung nachgeschlagen, nie als Text weitergereicht:
        // Was nicht zu einem unserer Fälle passt, ist null — und die Vorlage
        // rendert für null nichts. Sonst wäre die Adresse ein Weg, fremden
        // Text auf eine gecachte Seite zu bringen.
        foreach (['<script>', 'other_shop', '', 'UNAVAILABLE'] as $wert) {
            $result = $this->render('https://example.org/produkt/schwedenbitter.html?consho_notice='.rawurlencode($wert));

            self::assertNull($result['data']['notice'], sprintf('„%s" darf keine Meldung ergeben', $wert));
        }
    }

    public function testEinErfolgLaesstSichNichtUeberDieAdresseVortaeuschen(): void
    {
        // `Added` ist ein Fall derselben Aufzählung — ein blankes tryFrom()
        // nähme ihn an. Dann zeigte ein geteilter Link jedem Besucher „wurde
        // in den Warenkorb gelegt", ohne dass etwas passiert ist, und die
        // Seite bliebe so im geteilten Cache stehen. Die Route schreibt diesen
        // Wert nie in eine Adresse; gelesen wird er auch nicht (Review C-3c).
        $result = $this->render('https://example.org/produkt/schwedenbitter.html?consho_notice=added');

        self::assertNull($result['data']['notice']);
    }

    public function testEinFeldStattEinesWertesErgibtKeineFehlerantwort(): void
    {
        // ?consho_notice[]=1 kommt von Crawlern. getString() beantwortet das
        // mit einer 400 — eine Produktseite, die daran scheitert, füllt nur
        // das Fehlerlog.
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
         * Spec 4.0: Die Währung hängt am Startpunkt, das Heimatland am Shop.
         * Der Shop dieses Tests steht auf EUR und auf Österreich; der
         * Startpunkt verkauft in Pfund. Die Euro-Preise bleiben daneben
         * stehen — nähme das Element wieder die des Shops, stünde hier der
         * „ab"-Preis von 13,00 € statt der 24,00 £ aus der Pfund-Staffel.
         *
         * Nur Variante 6 bekommt einen Pfund-Preis: Damit ist „ab" hinfällig
         * und die Aussage hängt nicht an einem Betrag, den zwei Zeilen teilen.
         */
        $this->insertPrice(['pid' => self::ML_750_VARIANT, 'net' => '20.0000', 'currency' => 'GBP', 'minQuantity' => '1.0000']);

        $data = $this->render('https://example.org/produkt/schwedenbitter.html?variant=6', currency: 'GBP')['data'];

        // 20,00 netto plus 20 % aus Österreich = 24,00 brutto.
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

        // Pfad ohne Host, wie die Kachel-Links; die Adresse selbst kommt aus
        // ProductUrlGenerator. 1 l hat nur die unveröffentlichte Variante 8.
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
        // Produkt 2 hat genau eine Variante — sie ist die kaufbare Einheit.
        // Ohne Preiszeile irgendwo: kein Preisbereich, die Seite bleibt stehen.
        $data = $this->render('https://example.org/produkt/kraeutersalz.html', productId: self::OTHER_PRODUCT)['data'];

        self::assertInstanceOf(ProductDetail::class, $data['product']);
        self::assertSame(self::OTHER_VARIANT, $data['product']->articleId);
        self::assertSame(self::OTHER_VARIANT, $data['purchase']['articleId']);
        self::assertNull($data['price']);
    }

    // ── Mit ?variant= ────────────────────────────────────────────────────

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
        // Das Feld gilt nur fürs Hauptprodukt. Selbst eine an der Variante
        // eingetragene Meta-Beschreibung oder ein Kurztext der Variante ändern
        // den Kopf nicht — die Adresse ist kanonisch zum Produkt.
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
        // Entscheidung 3 und Michaels Antwort 2: 404, keine Weiterleitung.
        $this->expectException(PageNotFoundException::class);

        $this->render('https://example.org/produkt/schwedenbitter.html?'.$query);
    }

    // ── Sicherheitsangaben in einer anderen Sprache ─────────────────────

    public function testAVariantWhoseOwnSafetyTextsAreNotTranslatedIsNotFoundInThatLanguage(): void
    {
        /*
         * Aus der Prüfung von Task 7/8: Die Sichtbarkeit entscheidet je
         * Variante, die Anzeige je Feld. Variante 6 hat eigene
         * Sicherheitsinformationen, aber keine eigenen Warnhinweise und keine
         * französische Zeile. Der Resolver allein würde den geerbten
         * französischen Warnhinweis liefern — die Seite darf es gar nicht so
         * weit kommen lassen.
         */
        $this->frenchProduct();
        $this->connection->update('tl_consho_product', ['safetyInformation' => '<p>Kühl lagern.</p>'], ['id' => self::ML_750_VARIANT]);

        $this->expectException(PageNotFoundException::class);

        $this->render('https://example.org/produkt/amer-suedois.html?variant='.self::ML_750_VARIANT, language: 'fr');
    }

    public function testAVariantWithoutOwnSafetyTextsShowsTheProductsTranslatedWarning(): void
    {
        // Die Gegenprobe: ohne eigene Texte gilt der französische Hinweis des
        // Hauptprodukts — nie der deutsche.
        $this->frenchProduct();

        $data = $this->render('https://example.org/produkt/amer-suedois.html?variant='.self::ML_250_VARIANT, language: 'fr')['data'];

        self::assertInstanceOf(ProductSafetyInfo::class, $data['safety']);
        self::assertSame('<p>Contient de l’alcool.</p>', $data['safety']->warnings);
    }

    // ── Bilder ───────────────────────────────────────────────────────────

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
                // In der gespeicherten Reihenfolge.
                static $expected = null;
                $expected ??= [StringUtil::binToUuid($first), StringUtil::binToUuid($second)];
                self::assertSame(array_shift($expected), $uuid);

                return $builder;
            })
        ;
        // Beide Dateien fehlen in der Dateiverwaltung — keine Figur, kein Fehler.
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

    // ── GARAN-Label (Block GARAN, GA3) ───────────────────────────────────

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

    // ── Teilvorlage des Produkttyps ──────────────────────────────────────

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

        // Contaos Finder bildet die Kennung aus dem Dateinamen, Großbuchstaben
        // inklusive. Wer „Wein.html.twig" anlegt, soll sie auch gerendert sehen.
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

    // ── Hilfen ───────────────────────────────────────────────────────────

    /**
     * Ein französischer Startpunkt im selben Shop und eine vollständige
     * französische Übersetzung des Hauptprodukts, samt Warnhinweis.
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
        // Dieselben Preise wie der Ab-Preis: Variante 3 erbt 13,00 €, Variante 6 kostet 29,00 €.
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
        // E3: Produkt 2 hat genau eine Variante und keinen Preis.
        self::assertNull($this->productMarkup($this->render('https://example.org/produkt/anderes.html', productId: self::OTHER_PRODUCT)['jsonLd']));
    }

    /**
     * @return array<string, mixed>|null der Product-Knoten im schema.org-Graphen der Seite
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
     * NumberFormatter trennt Betrag und Währung mit einem geschützten
     * Leerzeichen. Für die Vergleiche hier zählt nur, was dasteht.
     */
    private function spaces(string $formatted): string
    {
        return str_replace(["\u{a0}", "\u{202f}"], ' ', $formatted);
    }

    public function testThePageCarriesTheCatalogueAndItsMainProductTag(): void
    {
        // B-7b: Auf c5 blieb die Detailseite nach einer Titeländerung veraltet.
        self::assertSame([CatalogCacheTags::CATALOG, 'contao.db.tl_consho_product.1'], $this->render('/produkt/schwedenbitter.html')['tags']);
    }

    public function testWithoutAProductNothingIsTagged(): void
    {
        self::assertSame([], $this->render('/produkt/schwedenbitter.html', productId: null)['tags']);
    }

    public function testThePageLivesUntilItsNextScheduledChange(): void
    {
        // B-7b, E2: Endet die Veröffentlichung in einer Stunde, darf der Cache nicht länger halten.
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
     * Ein angemeldetes Mitglied mit diesen Gruppen, oder niemand.
     *
     * Echter FrontendUser statt Mock, wie in CartResolverTest: Der Resolver
     * entscheidet an `instanceof FrontendUser`, und ein Mock einer anderen
     * Klasse liefe an diesem Zweig vorbei.
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
     * @param list<int>|null       $memberGroups null = niemand angemeldet (C-4)
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

        // `consho_currency` steht nur in der Zeile des STARTPUNKTS, nicht in
        // der der Produktseite darunter: Die Spalte wird nur oben gepflegt,
        // und ein Doppel, das sie überall herausgäbe, wäre fähiger als das
        // Original und machte den Baumlauf ungeprüft.
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

        // Wie Symfonys Generator: {alias} kennt die Route, alles andere hängt er
        // als Query an.
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

        // Der Satz selbst ist in SubscriptionTermsTest geprüft; hier zählt,
        // welcher Regel und welcher Betrag hineingehen.
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

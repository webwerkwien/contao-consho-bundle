<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\FrontendUser;
use Contao\PageModel;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Webwerkwien\ContaoConshoBundle\Cart\CartAgeLimit;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;
use Webwerkwien\ContaoConshoBundle\Cart\CartResolver;
use Webwerkwien\ContaoConshoBundle\Cart\CartTitles;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Cart\CartToken;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Cart\PurchaseCheck;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Cart\CodeAttemptLimit;
use Webwerkwien\ContaoConshoBundle\Cart\CodeEntry;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherWallet;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\CartController;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTaxClasses;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTree;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\ProductConditions;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLineTerms;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRenewal;
use Webwerkwien\ContaoConshoBundle\Tests\Cart\CartSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * The cart element against real collaborators on an SQLite database —
 * modelled on AddToCartControllerTest. CartResolver, DbalCartRepository,
 * CartPricer, CartTitles and PurchaseCheck are `final` and work here on a
 * connection that carries both test schemas (catalogue and cart). Only what
 * a running Contao would need is doubled: the framework with its adapters,
 * page and shop record, and the URL generator.
 *
 * Base data: shop 1 (EUR, AT, default language de) below start point 5,
 * the cart page 7. Main product 1 "Wanderstock" with the variants 2
 * (WS-120) and 3 (WS-140) — both are called "Wanderstock" in the cart,
 * which is the point here. Plus article 21 "Rucksack" without variants and
 * **without an article number**, for the case that none is maintained.
 */
class CartControllerTest extends TestCase
{
    use CartSchema;
    use CatalogSchema;
    use TaxFixture;

    private const SHOP = 1;
    private const ROOT_PAGE = 5;
    private const CART_PAGE = 7;
    private const ELEMENT = 42;

    private const MAIN = 1;
    private const SHORT = 2;

    // DL: the kind of every article; null = no kind reader.
    private string|null $kind = null;
    private const LONG = 3;
    private const PLAIN = 21;

    private const CART_URL = 'https://shop.example/warenkorb.html';

    /** E4: what the checkout's readiness reports. */
    private array $issues = [];

    private string $token;
    private PageModel $page;
    private PageModel $root;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->createCatalogSchema($connection);
        $this->createRootPageTable($connection);

        $this->cartConnection = $connection;
        $this->createCartTables();

        // Block H (H-4): the cart rules from the DCA.
        foreach (['tl_consho_cart_rule', 'tl_consho_cart_rule_redemption', 'tl_consho_voucher', 'tl_consho_tax_class'] as $table) {
            DcaSchema::create($connection, $table);
        }

        $this->insertShop(['id' => self::SHOP, 'currency' => 'EUR', 'country' => 'AT', 'fallbackLanguage' => 'de']);
        $this->insertRootPage(self::ROOT_PAGE, 'de', self::SHOP);

        // Main product with two variants. The title is only on the main product,
        // the article number on every variant — exactly the split CartTitles
        // reflects.
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock', 'sku' => 'WS', 'published' => 1]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        $this->insertProduct(['id' => self::SHORT, 'pid' => self::MAIN, 'title' => 'rot / 120 cm', 'sku' => 'WS-120', 'published' => 1]);
        $this->insertPrice(['pid' => self::SHORT, 'net' => '10.0000']);

        $this->insertProduct(['id' => self::LONG, 'pid' => self::MAIN, 'title' => 'rot / 140 cm', 'sku' => 'WS-140', 'published' => 1]);
        $this->insertPrice(['pid' => self::LONG, 'net' => '10.0000']);

        // Without an article number: the field is not mandatory in
        // tl_consho_product, and a small shop often does not maintain it.
        $this->insertProduct(['id' => self::PLAIN, 'title' => 'Rucksack', 'published' => 1]);
        $this->insertProductShop(['pid' => self::PLAIN, 'shop' => self::SHOP]);
        $this->insertPrice(['pid' => self::PLAIN, 'net' => '20.0000']);

        $this->token = CartToken::generate();
        $this->root = $this->pageModel(self::ROOT_PAGE, [
            'consho_shop' => self::SHOP,
            'consho_currency' => 'EUR',
            'consho_checkoutPage' => self::CART_PAGE,
        ]);
        $this->page = $this->pageModel(self::CART_PAGE, []);
    }

    /**
     * @dataProvider handlungen
     *
     * @param array<string, string> $post
     */
    public function testEineHandlungWirdAusDemFormularGelesen(array $post, string|null $expected, int $itemId): void
    {
        $request = new Request([], $post);

        $action = CartController::actionOf($request);

        self::assertSame($expected, $action?->name);
        // `->` instead of `?->`: the `??` already catches the null case, and
        // PHPStan (level 6) rejects the double safeguard as superfluous.
        self::assertSame($itemId, $action->itemId ?? 0);
    }

    /**
     * @return iterable<string, array{array<string, string>, string|null, int}>
     */
    public static function handlungen(): iterable
    {
        yield 'Menge setzen' => [['consho_cart_action' => 'update', 'item' => '5', 'quantity' => '3'], 'update', 5];
        yield 'entfernen' => [['consho_cart_action' => 'remove', 'item' => '5'], 'remove', 5];
        yield 'nichts' => [[], null, 0];
        yield 'unbekannte Handlung' => [['consho_cart_action' => 'drop_table', 'item' => '5'], null, 0];
        yield 'ohne Position' => [['consho_cart_action' => 'remove'], null, 0];
    }

    public function testMengeNullEntferntDiePosition(): void
    {
        // Whoever types 0 wants the line gone. An error message "invalid
        // quantity" would be pedantry.
        self::assertTrue(CartController::removesLine('0'));
        self::assertTrue(CartController::removesLine('0.0000'));
        self::assertFalse(CartController::removesLine('1'));
        self::assertFalse(CartController::removesLine('keine Zahl'));
    }

    // ── What the element builds when displaying ─────────────────────────────

    public function testZweiVariantenEinesProduktsSindImWarenkorbAuseinanderzuhalten(): void
    {
        // The point: both lines are called "Wanderstock" because CartTitles
        // deliberately returns the main product's title. The article number
        // stands next to it per variant and makes labels and buttons
        // distinguishable.
        $this->fill([self::SHORT => '1.0000', self::LONG => '2.0000']);

        $lines = $this->rowsOf($this->render()['data'], 'lines');

        self::assertCount(2, $lines);
        self::assertSame('Wanderstock', $lines[0]['title']);
        self::assertSame('Wanderstock', $lines[1]['title']);

        self::assertSame('WS-120', $lines[0]['sku']);
        self::assertSame('WS-140', $lines[1]['sku']);
        self::assertNotSame($lines[0]['sku'], $lines[1]['sku']);
    }

    public function testEineAbozeileTraegtIhreBedingungenMitDemBetragDerZeile(): void
    {
        // Abo-2 (A10, P15): the terms on the line, built from the amount the
        // line shows — 1.00 net × 2 = 2.40 gross.
        $cartId = $this->fill([]);
        $this->cartConnection->insert('tl_consho_price', ['pid' => self::SHORT, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '1.0000', 'subscriptionRule' => 5]);
        (new DbalCartRepository($this->cartConnection))->addItem($cartId, self::SHORT, '2.0000', 1_000, 5);
        (new DbalCartRepository($this->cartConnection))->addItem($cartId, self::SHORT, '1.0000', 1_000);

        $lines = $this->rowsOf($this->render()['data'], 'lines');

        self::assertSame('Bedingungen 5 zu 2.4000', $lines[0]['terms']);
        self::assertNull($lines[1]['terms']);
    }

    /**
     * DL: "zzgl. Versandkosten" (plus shipping) only when something is shipped.
     */
    public function testOhneWareKeinVersandhinweis(): void
    {
        $cartId = $this->fill([]);
        (new DbalCartRepository($this->cartConnection))->addItem($cartId, self::SHORT, '1.0000', 1_000);

        $this->kind = 'service';
        self::assertFalse($this->render()['data']['shipped']);

        $this->kind = 'physical';
        self::assertTrue($this->render()['data']['shipped']);
    }

    public function testEineVerlaengerungNenntDasAboUndBehaeltIhreMenge(): void
    {
        // Abo-7b (P67): "verlängert" (renews) with the subscription number, and
        // a quantity change — even from a self-built form — changes nothing.
        $cartId = $this->fill([]);
        $this->cartConnection->insert('tl_consho_price', ['pid' => self::SHORT, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '1.0000', 'subscriptionRule' => 5]);
        $repository = new DbalCartRepository($this->cartConnection);
        $repository->addRenewal($cartId, self::SHORT, '2.0000', 5, 31, 1_000);
        $repository->addItem($cartId, self::SHORT, '1.0000', 1_000, 5);

        $lines = $this->rowsOf($this->render()['data'], 'lines');
        self::assertSame(['WEB-00084-1', ''], array_column($lines, 'renews'));

        $renewal = (int) $this->cartConnection->fetchOne('SELECT id FROM tl_consho_cart_item WHERE renews = 31');
        $this->submit(['consho_cart_action' => 'update', 'item' => (string) $renewal, 'quantity' => '5']);
        self::assertSame(2.0, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item WHERE id = ?', [$renewal]));

        // Removing works.
        $this->submit(['consho_cart_action' => 'remove', 'item' => (string) $renewal]);
        self::assertSame(1, $this->countItems());
    }

    public function testEinGastMitAboErfaehrtVorDerKasseDassEsEinKontoBraucht(): void
    {
        // P12: the checkout requires logging in — the cart says so beforehand.
        $cartId = $this->fill([self::SHORT => '1.0000']);

        self::assertFalse($this->render()['data']['account_required']);

        $this->cartConnection->insert('tl_consho_price', ['pid' => self::SHORT, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '1.0000', 'subscriptionRule' => 5]);
        (new DbalCartRepository($this->cartConnection))->addItem($cartId, self::SHORT, '1.0000', 1_000, 5);

        self::assertTrue($this->render()['data']['account_required']);
    }

    public function testEinMitgliedBrauchtDenHinweisNicht(): void
    {
        $repository = new DbalCartRepository($this->cartConnection);
        $cartId = $repository->create(null, 1, self::SHOP, 1_000);
        $this->cartConnection->insert('tl_consho_price', ['pid' => self::SHORT, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '1.0000', 'subscriptionRule' => 5]);
        $repository->addItem($cartId, self::SHORT, '1.0000', 1_000, 5);

        $template = new FragmentTemplate('content_element/consho_cart', static fn (): Response => new Response());
        $this->controller(asMember: true)->respond($template, $this->model(), $this->request());

        self::assertCount(1, $this->rowsOf($template->getData(), 'lines'), 'der Korb des Mitglieds wurde gefunden');
        self::assertFalse($template->getData()['account_required']);
    }

    public function testDerWarenkorbVerlinktDenCheckout(): void
    {
        // 4.2: "weiter zum Checkout" (on to checkout). Without this link the page
        // would only be reachable by someone typing its address.
        $this->fill([self::SHORT => '1.0000']);

        self::assertSame(self::CART_URL, $this->render()['data']['checkout_url']);
    }

    public function testEineGesperrteKasseWirdNichtVerlinkt(): void
    {
        // E4 (C-12): instead of a link to an empty page, the checkout's sentence.
        $this->fill([self::SHORT => '1.0000']);
        $this->issues = [new ConfigurationIssue(ConfigurationFinding::PaymentMethodMissing)];

        $data = $this->render()['data'];

        self::assertNull($data['checkout_url']);
        self::assertTrue($data['checkout_blocked']);
    }

    public function testEinBefundEinesAnderenShopsSperrtNicht(): void
    {
        $this->fill([self::SHORT => '1.0000']);
        $this->issues = [new ConfigurationIssue(ConfigurationFinding::PaymentMethodMissing, shopId: 99)];

        $data = $this->render()['data'];

        self::assertSame(self::CART_URL, $data['checkout_url']);
        self::assertFalse($data['checkout_blocked']);
    }

    public function testEinLeererWarenkorbVerlinktNichts(): void
    {
        // A "Zur Kasse" (to checkout) above an empty cart leads to a page that
        // immediately says that nothing is there.
        self::assertNull($this->render()['data']['checkout_url']);
    }

    public function testEineZeileOhneArtikelnummerTraegtIhrePosition(): void
    {
        // If none is maintained, `sku` stays empty and the template falls back
        // to the position. It counts from 1 and runs in the order in which the
        // table is rendered.
        $this->fill([self::SHORT => '1.0000', self::PLAIN => '1.0000']);

        $lines = $this->rowsOf($this->render()['data'], 'lines');

        self::assertSame('WS-120', $lines[0]['sku']);
        self::assertSame(1, $lines[0]['position']);

        self::assertSame('', $lines[1]['sku']);
        self::assertSame(2, $lines[1]['position']);
    }

    public function testEineZeileOhnePreisStehtSeparatUndZaehltDiePositionWeiter(): void
    {
        // The unpriceable line is a second list, but stands below the priced
        // ones in the table. If its position started at 1 again, two lines
        // would carry the same feature.
        $this->fill([self::SHORT => '1.0000', self::PLAIN => '1.0000']);
        $this->cartConnection->delete('tl_consho_price', ['pid' => self::PLAIN]);

        $data = $this->render()['data'];

        $lines = $this->rowsOf($data, 'lines');
        $unpriced = $this->rowsOf($data, 'unpriced');

        self::assertCount(1, $lines);
        self::assertSame(1, $lines[0]['position']);

        self::assertCount(1, $unpriced);
        self::assertSame('Rucksack', $unpriced[0]['title']);
        self::assertSame('', $unpriced[0]['sku']);
        self::assertSame(2, $unpriced[0]['position']);

        // isEmpty() is false as long as an unpriceable line is there.
        self::assertFalse($data['is_empty']);
    }

    public function testDieZeilensummeKommtFertigFormatiertAusDemFormatierer(): void
    {
        $this->fill([self::SHORT => '3.0000']);

        $lines = $this->rowsOf($this->render()['data'], 'lines');

        // 10.00 net × 3 = 30.00 net → 36.00 gross at 20 %.
        self::assertSame((new MoneyFormatter())->format('36.0000', 'EUR', 'de'), $lines[0]['lineGross']);

        // The quantity without its zeros, because it goes into an input field.
        self::assertSame('3', $lines[0]['quantity']);
    }

    public function testOhneWaehrungAmStartpunktBleibtDasElementStumm(): void
    {
        /*
         * The window between deploy and `contao:migrate`: the start point
         * does not carry a currency yet. Measured what would come out without
         * this lock — `MoneyFormatter::format('0.00', '', 'de')` yields
         * "0,00 XXX", because `Currencies::getFractionDigits('')` falls back
         * to DEFAULT instead of throwing and ICU puts an unknown code as a
         * placeholder. And that also with an empty cart, so on every view of
         * the cart page.
         */
        $this->root = $this->pageModel(self::ROOT_PAGE, ['consho_shop' => self::SHOP, 'consho_currency' => '']);
        $this->fill([self::SHORT => '1.0000']);

        $result = $this->render();

        /*
         * What is checked is what the element passes to the template, NOT the
         * content of the response: this test's FragmentTemplate closes over an
         * empty Response, so `getContent()` is '' in both cases. Measured —
         * with an assertion on the content, removing the lock survives the
         * whole suite.
         */
        self::assertSame([], $result['data']);
        self::assertSame('', $result['response']->getContent());
    }

    public function testGepreistWirdInDerWaehrungDesStartpunktsNichtInDerDesShops(): void
    {
        /*
         * Spec 4.0: the currency belongs to the audience and hangs off the
         * start point, the home country to the invoice issuer and hangs off the
         * shop. The shop of this test is set to EUR and to Austria — it is
         * priced in pounds anyway, because the start point says so, and the
         * 20 % still come from AT.
         *
         * The euro price of 10.00 stays next to it: if the element took the
         * shop's currency again, it would find it and calculate 24.00 instead
         * of 12.00 — so the difference is not only the currency symbol.
         */
        $this->root = $this->pageModel(self::ROOT_PAGE, ['consho_shop' => self::SHOP, 'consho_currency' => 'GBP']);
        $this->insertPrice(['pid' => self::SHORT, 'net' => '5.0000', 'currency' => 'GBP']);

        $this->fill([self::SHORT => '2.0000']);

        $data = $this->render()['data'];
        $lines = $this->rowsOf($data, 'lines');

        // 5.00 net × 2 = 10.00 net → 12.00 gross at 20 %.
        $inPfund = (new MoneyFormatter())->format('12.0000', 'GBP', 'de');

        self::assertCount(1, $lines);
        self::assertSame($inPfund, $lines[0]['lineGross']);
        self::assertSame($inPfund, $data['total']);
        self::assertNotSame((new MoneyFormatter())->format('12.0000', 'EUR', 'de'), $data['total']);
    }

    // ── Whom the cart calculates for (C-4) ──────────────────────────────────

    public function testEinGastSiehtDenKorbBrutto(): void
    {
        $this->netPriceGroups([3]);
        $this->fill([self::SHORT => '2.0000']);

        $data = $this->render()['data'];

        self::assertFalse($data['showsNet']);
        // 10.00 net × 2 = 20.00 net → 24.00 gross at 20 %.
        self::assertSame((new MoneyFormatter())->format('24.0000', 'EUR', 'de'), $data['total']);
        self::assertSame((new MoneyFormatter())->format('24.0000', 'EUR', 'de'), $this->rowsOf($data, 'lines')[0]['lineGross']);
    }

    public function testEinMitgliedEinerNettogruppeSiehtDenKorbNetto(): void
    {
        // ⚠️ Line AND total, both: a net total over gross lines would be a
        // calculation that visibly does not add up — and exactly the kind of
        // error a test on the total alone would let through.
        $this->netPriceGroups([3]);
        $this->fill([self::SHORT => '2.0000']);

        $data = $this->render(memberGroups: [3])['data'];

        self::assertTrue($data['showsNet']);
        self::assertSame((new MoneyFormatter())->format('20.0000', 'EUR', 'de'), $data['total']);
        self::assertSame((new MoneyFormatter())->format('20.0000', 'EUR', 'de'), $this->rowsOf($data, 'lines')[0]['lineGross']);
    }

    public function testEinGruppenpreisGiltAuchImKorb(): void
    {
        // The cart has to confirm the product page it was added from.
        $this->netPriceGroups([]);
        $this->insertPrice(['pid' => self::SHORT, 'net' => '8.0000', 'customerGroup' => 3]);
        $this->fill([self::SHORT => '1.0000']);

        // 8.00 net → 9.60 gross; without the group it would be 12.00.
        self::assertSame((new MoneyFormatter())->format('9.6000', 'EUR', 'de'), $this->render(memberGroups: [3])['data']['total']);
        self::assertSame((new MoneyFormatter())->format('12.0000', 'EUR', 'de'), $this->render()['data']['total']);
    }

    // ── Age rating (C19) ────────────────────────────────────────────────────

    public function testOhneAltersfreigabeStehtKeinHinweisImKorb(): void
    {
        $this->fill([self::SHORT => '1.0000']);

        self::assertSame(0, $this->render()['data']['minimum_age']);
    }

    public function testDieHoechsteAltersfreigabeDesKorbsStehtDaran(): void
    {
        $this->cartConnection->update('tl_consho_product', ['minimumAge' => 16], ['id' => self::SHORT]);
        $this->cartConnection->update('tl_consho_product', ['minimumAge' => 18], ['id' => self::PLAIN]);

        $this->fill([self::SHORT => '1.0000', self::PLAIN => '1.0000']);

        self::assertSame(18, $this->render()['data']['minimum_age']);
    }

    /**
     * @param list<int> $groups
     */
    private function netPriceGroups(array $groups): void
    {
        $this->cartConnection->insert('tl_member_group', ['id' => 3, 'name' => 'Händler']);
        $this->cartConnection->update(
            'tl_consho_shop',
            ['netPriceGroups' => [] === $groups ? null : serialize(array_map('strval', $groups))],
            ['id' => self::SHOP],
        );
    }

    /**
     * Block G (G5): the cart holds nothing back and says what is left —
     * summed per article, the figure only where the line wants more.
     */
    public function testDerKorbSagtWasVomBestandUebrigIst(): void
    {
        $this->cartConnection->update('tl_consho_product', ['stockTracked' => 1], ['id' => self::PLAIN]);
        (new StockRows($this->cartConnection))->sync(self::PLAIN, 100);
        $this->cartConnection->update('tl_consho_stock', ['quantity' => '1.0000'], ['article' => self::PLAIN]);

        $this->fill([self::PLAIN => '2.0000', self::SHORT => '1.0000']);

        $lines = array_column($this->render()['data']['lines'], 'stock', 'articleId');

        self::assertSame(['state' => 'available', 'short' => true, 'left' => '1'], $lines[self::PLAIN]);
        self::assertNull($lines[self::SHORT], 'ohne Bestand kein Hinweis');
    }

    public function testDieNotizWirdBeimRendernGenauEinmalGelesen(): void
    {
        // takeNotice() reads and clears in one go (7.1). If it stayed, the
        // visitor would read "Der Artikel wurde in den Warenkorb gelegt" (the
        // article was added to the cart) again on every further view of the cart.
        $cartId = $this->fill([self::SHORT => '1.0000']);
        (new DbalCartRepository($this->cartConnection))->setNotice($cartId, CartNotice::Added);

        self::assertSame(CartNotice::Added, $this->render()['data']['notice']);
        self::assertNull($this->render()['data']['notice']);
        self::assertSame('', $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testDieAntwortIstPrivatUndWirdNichtGespeichert(): void
    {
        // This page shows the cart of ONE visitor. `private` alone is not
        // enough: a shared cache must not store it in the first place.
        $this->fill([self::SHORT => '1.0000']);

        $response = $this->render()['response'];

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function testOhneShopBleibtDasElementStumm(): void
    {
        // No page, no shop — then the element has nothing to show, and above
        // all it must not create a cart.
        $this->root = $this->pageModel(self::ROOT_PAGE, ['consho_shop' => 0]);

        $response = $this->render()['response'];

        self::assertSame('', $response->getContent());
    }

    // ── What a submission does ──────────────────────────────────────────────

    public function testEinAbsendenAntwortetMitEinerUmleitungAufDieWarenkorbseite(): void
    {
        // Thrown, not returned: a content element renders as a sub-request
        // whose Response travels into the page as a string. The address comes
        // from the ContentUrlGenerator, not from the request.
        $this->fill([self::SHORT => '1.0000']);
        $itemId = $this->itemId(self::SHORT);

        self::assertSame(
            self::CART_URL,
            $this->submit(['consho_cart_action' => 'update', 'item' => (string) $itemId, 'quantity' => '2']),
        );

        self::assertSame(2.0, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item'));
    }

    public function testEineGetippteNullNimmtDieZeileWeg(): void
    {
        $this->fill([self::SHORT => '1.0000']);

        $this->submit(['consho_cart_action' => 'update', 'item' => (string) $this->itemId(self::SHORT), 'quantity' => '0']);

        self::assertSame(0, $this->countItems());
    }

    public function testEntfernenNimmtGenauDieseZeileWeg(): void
    {
        $this->fill([self::SHORT => '1.0000', self::LONG => '1.0000']);

        $this->submit(['consho_cart_action' => 'remove', 'item' => (string) $this->itemId(self::SHORT)]);

        self::assertSame(1, $this->countItems());
        self::assertSame(self::LONG, (int) $this->cartConnection->fetchOne('SELECT product FROM tl_consho_cart_item'));
    }

    public function testEineInzwischenGeaenderteMindestmengeGreiftBeiJederMengenaenderung(): void
    {
        // A cart stays open for days. If the merchant changes the minimum
        // quantity in the meantime, the check has to apply again on change —
        // otherwise the cart would produce a quantity the purchase button has
        // long been rejecting.
        $cartId = $this->fill([self::PLAIN => '1.0000']);

        $this->cartConnection->update('tl_consho_product', ['minPurchase' => '5.0000'], ['id' => self::PLAIN]);

        $this->submit(['consho_cart_action' => 'update', 'item' => (string) $this->itemId(self::PLAIN), 'quantity' => '2']);

        self::assertSame(1.0, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item'));
        self::assertSame(
            CartNotice::InvalidQuantity->value,
            $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart WHERE id = ?', [$cartId]),
        );
    }

    public function testEineGerateneFremdePositionBleibtUnberuehrt(): void
    {
        // The line id stands in the form as a small number. Every method of the
        // repository has the pair (cart, line) in its WHERE, and articleOf() is
        // there exactly for that: a guessed number reads as "not yours", not as
        // someone else's line.
        $cartId = $this->fill([self::PLAIN => '1.0000']);

        $repository = new DbalCartRepository($this->cartConnection);
        $foreignCart = $repository->create(CartToken::hash(CartToken::generate()), 0, self::SHOP, 1_000);
        $repository->addItem($foreignCart, self::SHORT, '4.0000', 1_000);

        $foreignItem = (int) $this->cartConnection->fetchOne('SELECT id FROM tl_consho_cart_item WHERE pid = ?', [$foreignCart]);

        $this->submit(['consho_cart_action' => 'update', 'item' => (string) $foreignItem, 'quantity' => '9']);
        $this->submit(['consho_cart_action' => 'remove', 'item' => (string) $foreignItem]);

        self::assertSame(
            4.0,
            (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item WHERE id = ?', [$foreignItem]),
        );
        self::assertSame(2, $this->countItems());

        // And no notice in one's own cart: articleOf() bails out before the
        // purchase check. Without this safeguard the check would continue with
        // article id 0 and write the visitor a "Dieser Artikel ist derzeit nicht
        // erhältlich" (this article is currently unavailable) about an article
        // they never touched.
        self::assertSame('', $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testEinFremdesFormularAufDerselbenSeiteWirdNichtBeantwortet(): void
    {
        // A contact form in a second element posts to the same address. Without
        // an action of its own no redirect may come from here — otherwise the
        // cart swallows the foreign submission.
        $this->fill([self::SHORT => '1.0000']);

        $data = $this->render($this->request('POST', ['betreff' => 'Hallo']))['data'];

        self::assertCount(1, $this->rowsOf($data, 'lines'));
        self::assertSame(1, $this->countItems());
    }

    // ── Block H (H-4): discount code in the cart (W1, W6) ─────────────────

    public function testDerCodeWirdGemerktWieGetipptOhneRandleerzeichen(): void
    {
        $cartId = $this->fill([self::PLAIN => '1']);

        $this->submit(['consho_cart_action' => 'code', 'discount_code' => '  Sommer24 ']);

        self::assertSame('Sommer24', (string) $this->cartConnection->fetchOne('SELECT discountCode FROM tl_consho_cart WHERE id = ?', [$cartId]));

        $this->submit(['consho_cart_action' => 'removeCode']);

        self::assertSame('', (string) $this->cartConnection->fetchOne('SELECT discountCode FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testDerRabattStehtImWarenkorbUndMindertDieSumme(): void
    {
        $cartId = $this->fill([self::PLAIN => '1']);
        $this->cartConnection->insert('tl_consho_cart_rule', ['tstamp' => 1, 'title' => 'Sommerrabatt', 'code' => 'Sommer24', 'published' => 1, 'effect' => 'percent', 'percent' => '10']);
        $this->cartConnection->update('tl_consho_cart', ['discountCode' => 'Sommer24'], ['id' => $cartId]);

        $data = $this->render()['data'];

        // Rucksack 20 net = 24 gross; 10 % = € 2.40.
        self::assertSame('applied', $data['code_status']);
        self::assertIsArray($data['discount']);
        self::assertSame('Sommerrabatt', $data['discount']['title']);
        self::assertSame('-2,40 €', str_replace(["\u{a0}", "\u{2212}"], [' ', '-'], (string) $data['discount']['amount']));
        self::assertSame('24,00 €', str_replace("\u{a0}", ' ', (string) $data['subtotal']));
        self::assertSame('21,60 €', str_replace("\u{a0}", ' ', (string) $data['total']));
    }

    public function testEinFalschGeschriebenerCodeWirdGemeldet(): void
    {
        $cartId = $this->fill([self::PLAIN => '1']);
        $this->cartConnection->insert('tl_consho_cart_rule', ['tstamp' => 1, 'title' => 'Sommerrabatt', 'code' => 'Sommer24', 'published' => 1, 'effect' => 'percent', 'percent' => '10']);
        $this->cartConnection->update('tl_consho_cart', ['discountCode' => 'SOMMER24'], ['id' => $cartId]);

        $data = $this->render()['data'];

        self::assertSame('unknown', $data['code_status']);
        self::assertNull($data['discount']);
        self::assertSame('24,00 €', str_replace("\u{a0}", ' ', (string) $data['total']));
    }

    // ── Value vouchers (WG-5, E3) ─────────────────────────────────────────

    public function testGutscheinWirdEingeloestUndMitGuthabenGezeigt(): void
    {
        $cartId = $this->fill([self::PLAIN => '1']);
        $this->cartConnection->insert('tl_consho_voucher', ['code' => 'AAAABBBBCCCCDDDD', 'shop' => self::SHOP, 'currency' => 'EUR', 'faceValue' => '25', 'balance' => '20']);

        $this->submit(['consho_cart_action' => 'voucher', 'voucher_code' => 'aaaa-bbbb-cccc-dddd']);

        self::assertSame('AAAABBBBCCCCDDDD', (string) $this->cartConnection->fetchOne('SELECT vouchers FROM tl_consho_cart WHERE id = ?', [$cartId]));

        $data = $this->render()['data'];

        self::assertTrue($data['voucher_enabled']);
        self::assertTrue($data['voucher_payable']);
        self::assertSame('…DDDD', $data['vouchers'][0]['code']);
        self::assertSame('usable', $data['vouchers'][0]['status']);
        self::assertSame('20,00 €', str_replace("\u{a0}", ' ', $data['vouchers'][0]['balance']));
        // Credited only in the checkout: the total stays.
        self::assertSame('24,00 €', str_replace("\u{a0}", ' ', (string) $data['total']));

        $this->submit(['consho_cart_action' => 'removeVoucher', 'voucher' => 'AAAA-BBBB-CCCC-DDDD']);

        self::assertSame('', (string) $this->cartConnection->fetchOne('SELECT vouchers FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    /**
     * WG-7: if only non-taxable items are in the cart, the total says nothing
     * about tax; a taxable article next to it brings the sentence back.
     */
    public function testNurNichtSteuerbaresImKorbOhneSteuersatz(): void
    {
        $this->cartConnection->insert('tl_consho_tax_class', ['id' => 7, 'title' => 'Gutschein', 'notTaxable' => 1]);
        $this->cartConnection->update('tl_consho_product', ['taxClass' => 7], ['id' => self::PLAIN]);
        $this->fill([self::PLAIN => '1']);

        self::assertTrue($this->render()['data']['untaxed']);

        $this->cartConnection->update('tl_consho_product', ['taxClass' => 1], ['id' => self::PLAIN]);

        self::assertFalse($this->render()['data']['untaxed']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<int, string> $lines article id => quantity, in the order
     *                                  in which they go into the cart
     */
    private function fill(array $lines): int
    {
        $repository = new DbalCartRepository($this->cartConnection);
        $cartId = $repository->create(CartToken::hash($this->token), 0, self::SHOP, 1_000);

        foreach ($lines as $articleId => $quantity) {
            $repository->addItem($cartId, $articleId, $quantity, 1_000);
        }

        return $cartId;
    }

    private function itemId(int $articleId): int
    {
        return (int) $this->cartConnection->fetchOne('SELECT id FROM tl_consho_cart_item WHERE product = ?', [$articleId]);
    }

    private function countItems(): int
    {
        return (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item');
    }

    private function kindReader(): ArticleShippingDataInterface|null
    {
        if (null === $this->kind) {
            return null;
        }

        return new class($this->kind) implements ArticleShippingDataInterface {
            public function __construct(private readonly string $kind)
            {
            }

            public function of(array $articleIds): array
            {
                return array_fill_keys($articleIds, ['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000', 'kind' => $this->kind]);
            }
        };
    }

    /**
     * @return array{data: array<string, mixed>, response: Response}
     */
    private function render(Request|null $request = null, array|null $memberGroups = null): array
    {
        $template = new FragmentTemplate('content_element/consho_cart', static fn (): Response => new Response());

        $response = $this->controller($memberGroups)->respond($template, $this->model(), $request ?? $this->request());

        return ['data' => $template->getData(), 'response' => $response];
    }

    /**
     * A submission, and the address it redirects to.
     *
     * @param array<string, string> $post
     */
    private function submit(array $post): string
    {
        try {
            $this->controller()->respond(
                new FragmentTemplate('content_element/consho_cart', static fn (): Response => new Response()),
                $this->model(),
                $this->request('POST', $post),
            );
        } catch (RedirectResponseException $exception) {
            return (string) $exception->getResponse()->headers->get('Location');
        }

        self::fail('Ein POST mit einer Handlung muss mit einer Umleitung beantwortet werden.');
    }

    /**
     * The rows of one of the two lists, with a type that can be checked
     * against.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(array $data, string $key): array
    {
        $rows = $data[$key] ?? null;

        self::assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param array<string, string> $post
     */
    private function request(string $method = 'GET', array $post = []): Request
    {
        $request = Request::create(self::CART_URL, $method, $post);
        $request->cookies->set(CartToken::NAME, $this->token);
        $request->attributes->set('pageModel', $this->page);

        return $request;
    }

    private function model(): ContentModel
    {
        $model = $this->createMock(ContentModel::class);
        $model->method('row')->willReturn(['id' => self::ELEMENT]);

        return $model;
    }

    /**
     * A logged-in member with these groups, or nobody (C-4).
     *
     * A real FrontendUser instead of a mock, as in CartResolverTest: the
     * resolver decides on `instanceof FrontendUser`.
     *
     * @param list<int>|null $groups
     */
    private function tokenStorage(array|null $groups): TokenStorage
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
     * @param bool $asMember the cart is looked up as member 1 instead of via
     *                       the cookie — only for the question whether someone is logged in
     */
    private function controller(array|null $memberGroups = null, bool $asMember = false): CartController&TestableCartElement
    {
        $prices = new DbalPriceRepository($this->cartConnection);

        // The sentence itself is checked in SubscriptionTermsTest; what counts
        // here is which rule and which line amount go into it.
        $lineTerms = $this->createMock(SubscriptionLineTerms::class);
        $lineTerms->method('of')->willReturnCallback(
            static fn (int $rule, string $amount): string|null => $rule > 0 ? 'Bedingungen '.$rule.' zu '.$amount : null,
        );

        $renewals = $this->createStub(SubscriptionRenewal::class);
        $renewals->method('numbersOf')->willReturnCallback(static fn (array $ids): array => \in_array(31, $ids, true) ? [31 => 'WEB-00084-1'] : []);

        $discounts = new CartDiscounts($this->cartConnection, new ProductConditions($this->cartConnection));

        return new class(
            new CartResolver(new DbalCartRepository($this->cartConnection), $asMember ? $this->tokenStorage([]) : new TokenStorage()),
            new DbalCartRepository($this->cartConnection),
            new CartPricer(
                new DbalArticleTree($this->cartConnection),
                new ArticlePriceResolver(
                    new PriceCalculator($prices),
                    $prices,
                    new BasePriceCalculator(),
                    $this->grossCalculator(),
                    new DbalArticleTaxClasses($this->cartConnection),
                ),
                $this->grossCalculator(),
            ),
            new CartTitles($this->cartConnection),
            new PurchaseCheck($this->cartConnection, new VisibilityCriteria()),
            new ShopResolver($this->framework()),
            new ShopLanguages($this->cartConnection),
            new MoneyFormatter(),
            $this->urlGenerator(),
            new PriceAudienceResolver($this->tokenStorage($memberGroups), $this->cartConnection),
            new CartAgeLimit($this->cartConnection),
            new ShopPageUrls($this->framework(), new ShopResolver($this->framework()), $this->urlGenerator()),
            new CodeEntry(
                new DbalCartRepository($this->cartConnection),
                $discounts,
                new CodeAttemptLimit(new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 10, 'interval' => '15 minutes'], new InMemoryStorage())),
                new VoucherWallet($this->cartConnection),
            ),
            $lineTerms,
            $renewals,
            $this->kindReader(),
            new Availability($this->cartConnection),
            $discounts,
            new VoucherWallet($this->cartConnection),
            new \Webwerkwien\ContaoConshoBundle\Tax\NotTaxableArticles($this->cartConnection, new \Webwerkwien\ContaoConshoBundle\Tax\ContaoTaxRateRepository($this->cartConnection)),
            new CheckoutReadiness([new class($this->issues) implements ConfigurationIssueSource {
                /** @param list<ConfigurationIssue> $issues */
                public function __construct(private readonly array $issues)
                {
                }

                public function issues(): array
                {
                    return $this->issues;
                }
            }]),
        ) extends CartController implements TestableCartElement {
            public function respond(FragmentTemplate $template, ContentModel $model, Request $request): Response
            {
                return $this->getResponse($template, $model, $request);
            }
        };
    }

    private function framework(): ContaoFramework
    {
        $pages = [self::ROOT_PAGE => $this->root, self::CART_PAGE => $this->page];

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(
            static fn (string $method, array $parameters): PageModel|null => $pages[(int) ($parameters[0] ?? 0)] ?? null,
        );

        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnMap([['id', self::SHOP], ['currency', 'EUR'], ['country', 'AT']]);

        $shopAdapter = $this->createMock(Adapter::class);
        $shopAdapter->method('__call')->willReturnCallback(
            static fn (string $method, array $parameters): ShopModel|null => self::SHOP === (int) ($parameters[0] ?? 0) ? $shop : null,
        );

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnMap([
            [PageModel::class, $pageAdapter],
            [ShopModel::class, $shopAdapter],
        ]);

        return $framework;
    }

    private function urlGenerator(): ContentUrlGenerator
    {
        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturnCallback(
            static function (object $content, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string {
                self::assertSame(UrlGeneratorInterface::ABSOLUTE_URL, $referenceType, 'eine Umleitung braucht eine absolute Adresse');

                return self::CART_URL;
            },
        );

        return $urls;
    }

    /**
     * The row carries `consho_currency` only where the caller passes it — that
     * is, at the start point. The cart page itself does not get it: the column
     * is only maintained at the start point, and a double that handed it out
     * everywhere would be more capable than the original.
     *
     * @param array<string, int|string> $row
     */
    private function pageModel(int $id, array $row): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', $id],
            // Derived from the identifier and not passed in: what ShopResolver
            // recognises the start point by is `type` — and two statements that
            // are supposed to say the same thing drift apart at some point.
            ['type', self::ROOT_PAGE === $id ? 'root' : 'regular'],
            ['rootId', self::ROOT_PAGE],
            ['language', 'de'],
        ]);
        $page->method('row')->willReturn($row + ['id' => $id]);

        return $page;
    }
}

/**
 * `getResponse()` is protected. The anonymous subclass above makes it
 * reachable via `respond()`; this interface gives the return value of
 * `controller()` a name that static analysis knows.
 */
interface TestableCartElement
{
    public function respond(FragmentTemplate $template, ContentModel $model, Request $request): Response;
}

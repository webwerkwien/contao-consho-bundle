<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\FrontendUser;
use Contao\PageModel;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;
use Webwerkwien\ContaoConshoBundle\Cart\CartResolver;
use Webwerkwien\ContaoConshoBundle\Cart\CartToken;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Cart\PurchaseCheck;
use Webwerkwien\ContaoConshoBundle\Cart\Quantity;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Controller\AddToCartController;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Tests\Cart\CartSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * The route that is the first in Consho to accept something from the open web.
 *
 * The checks run against real collaborators: PurchaseCheck, CartResolver and
 * DbalCartRepository are `final` and cannot be doubled anyway — they work
 * here on an in-memory SQLite database that carries both test schemas
 * (catalogue and cart) and shares one connection. Only what a running Contao
 * would need is doubled: the framework with its adapters, the page and shop
 * records, and the URL generator.
 */
class AddToCartControllerTest extends TestCase
{
    use CartSchema;
    use CatalogSchema;

    private const SHOP = 1;
    private const OTHER_SHOP = 2;
    private const ROOT_PAGE = 5;
    private const CATALOGUE_PAGE = 12;
    private const CART_PAGE = 7;
    private const ARTICLE = 21;
    private const MEMBER = 42;

    private const HOST = 'https://shop.example';
    private const CATALOGUE_URL = self::HOST.'/katalog/schuhe.html';
    private const CART_URL = self::HOST.'/warenkorb.html';

    private string $afterAddToCart = 'cart';

    /** @var list<int> the groups of the logged-in member (Abo-2) */
    private array $memberGroups = [];
    private int $cartPageId = self::CART_PAGE;
    private int $rootShopId = self::SHOP;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->createCatalogSchema($connection);
        $this->createRootPageTable($connection);

        $this->cartConnection = $connection;
        $this->createCartTables();

        $this->insertShop(['id' => self::SHOP, 'fallbackLanguage' => 'de']);
        $this->insertRootPage(self::ROOT_PAGE, 'de', self::SHOP);
    }

    // ── Why the route is exempt from the request check ──────────────────────

    public function testDieRouteNimmtDieAnfragePruefungAus(): void
    {
        // C11: a page cached as shared can carry the token of another visitor.
        // Instead: SameSite=Lax and the origin check.
        $attributes = (new \ReflectionMethod(AddToCartController::class, '__invoke'))->getAttributes(Route::class);

        self::assertCount(1, $attributes);

        // The properties, not the getters: getPath() & co. are deprecated since
        // symfony/routing 7.4.
        $route = $attributes[0]->newInstance();

        self::assertSame('/_consho/cart/add', $route->path);
        self::assertFalse($route->defaults['_token_check']);
        self::assertSame('frontend', $route->defaults['_scope']);
        self::assertSame(['POST'], $route->methods);
    }

    public function testDieKlasseIstAlsControllerAusgezeichnet(): void
    {
        // Without the attribute the service stays private, the controller
        // resolver does not get it from the container and builds it with `new` —
        // which fails at the constructor. The route would then exist but never work.
        self::assertCount(
            1,
            (new \ReflectionClass(AddToCartController::class))->getAttributes(AsController::class),
        );
    }

    /**
     * @dataProvider fremdeHerkuenfte
     */
    public function testEineFremdeOderFehlendeHerkunftWirdAbgelehnt(string|null $origin): void
    {
        $request = Request::create(self::HOST.'/_consho/cart/add', 'POST');

        if (null !== $origin) {
            $request->headers->set('Origin', $origin);
        }

        self::assertFalse(AddToCartController::originMatches($request));
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function fremdeHerkuenfte(): iterable
    {
        yield 'fehlend' => [null];
        yield 'leer' => [''];
        yield 'fremde Domain' => ['https://boese.example'];
        yield 'gleiche Domain, anderes Schema' => ['http://shop.example'];
        yield 'Unterdomain' => ['https://sub.shop.example'];
        yield 'Domain nur als Praefix' => ['https://shop.example.boese.test'];
        yield 'anderer Port' => ['https://shop.example:8443'];
        yield 'mit Schraegstrich am Ende' => ['https://shop.example/'];
        yield 'null als Wert' => ['null'];
    }

    public function testDieEigeneHerkunftWirdAngenommen(): void
    {
        $request = Request::create(self::HOST.'/_consho/cart/add', 'POST');
        $request->headers->set('Origin', self::HOST);

        self::assertTrue(AddToCartController::originMatches($request));
    }

    public function testDieRuecksprungadresseMussDieselbeHerkunftHaben(): void
    {
        $request = Request::create(self::HOST.'/_consho/cart/add', 'POST');

        self::assertSame(
            self::HOST.'/produkt/schuh.html?farbe=rot',
            AddToCartController::sameOriginOrNull($request, self::HOST.'/produkt/schuh.html?farbe=rot'),
        );

        self::assertNull(AddToCartController::sameOriginOrNull($request, 'https://boese.example/weiter'));
        self::assertNull(AddToCartController::sameOriginOrNull($request, '//boese.example/weiter'));
        self::assertNull(AddToCartController::sameOriginOrNull($request, 'https://shop.example.boese.test/weiter'));
        self::assertNull(AddToCartController::sameOriginOrNull($request, 'http://shop.example/weiter'));
        self::assertNull(AddToCartController::sameOriginOrNull($request, self::HOST));
        self::assertNull(AddToCartController::sameOriginOrNull($request, ''));
    }

    // ── What the route actually does ────────────────────────────────────────

    public function testEineFremdeHerkunftSchreibtNichtsUndAntwortetMitDreiUndVierzig(): void
    {
        $this->publishArticle();

        $response = $this->controller()($this->post(origin: 'https://boese.example'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame(0, $this->countCarts());
        self::assertSame([], $response->headers->getCookies());
    }

    public function testEinSichtbarerArtikelLandetImWarenkorbUndDasCookieKommtMit(): void
    {
        $this->publishArticle();

        $response = $this->controller()($this->post());

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame(self::CART_URL, $response->headers->get('Location'));

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame(CartToken::NAME, $cookies[0]->getName());
        self::assertSame('lax', $cookies[0]->getSameSite());
        self::assertTrue($cookies[0]->isHttpOnly());

        // The hash in the record belongs to the cookie handed out — otherwise
        // the next call would not find the cart again.
        self::assertSame(
            CartToken::hash((string) $cookies[0]->getValue()),
            $this->cartConnection->fetchOne('SELECT tokenHash FROM tl_consho_cart'),
        );

        self::assertSame(CartNotice::Added->value, $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart'));

        // SQLite does not return a NUMERIC column character for character, hence
        // the numeric comparison.
        self::assertSame(3.0, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item'));
    }

    public function testDasGewaehlteModellLandetAnDerZeile(): void
    {
        $this->publishArticle();

        $this->controller(offers: $this->offersOf([0, 5]))($this->post(['subscriptionRule' => '5']));

        self::assertSame(5, (int) $this->cartConnection->fetchOne('SELECT subscriptionRule FROM tl_consho_cart_item'));
    }

    public function testDieAngeboteWerdenMitDenGruppenGefragt(): void
    {
        // Abo-2: without this test a route that forgets the groups would look
        // just as green. The groups alone decide (there is no business-customer
        // lock any more).
        $this->publishArticle();
        $this->cartConnection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => self::SHOP]);
        $this->memberGroups = [3];
        (new DbalCartRepository($this->cartConnection))->create(null, self::MEMBER, self::SHOP, 1_000);

        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->expects(self::once())->method('allows')->with(self::ARTICLE, 5, [3])->willReturn(true);

        $this->controller(self::MEMBER, $offers)($this->post(['subscriptionRule' => '5']));
    }

    public function testOhneModellfeldIstEsDerEinmalkauf(): void
    {
        // Forms without a model choice — every product before Abo-2, every pure
        // one-time purchase product after it — stay valid.
        $this->publishArticle();

        $this->controller(offers: $this->offersOf([0]))($this->post());

        self::assertSame(0, (int) $this->cartConnection->fetchOne('SELECT subscriptionRule FROM tl_consho_cart_item'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadablePlans(): iterable
    {
        // Abo-1, bf4dda4: `(int) "Monatsabo"` was 0 — the one-time purchase. Only
        // digits count, anything else is a model that does not exist.
        yield 'Wort' => ['Monatsabo'];
        yield 'leer' => [''];
        yield 'negativ' => ['-5'];
        yield 'Kommazahl' => ['5.0'];
        yield 'Leerzeichen' => [' 5'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadablePlans')]
    public function testEinUnlesbaresModellWirdAbgelehntStattZumEinmalkauf(string $plan): void
    {
        $this->publishArticle();

        $response = $this->controller(offers: $this->offersOf([0, 5]))($this->post(['subscriptionRule' => $plan]));

        self::assertSame(0, $this->countItems());
        self::assertStringContainsString(CartNotice::PARAM.'='.CartNotice::NotOffered->value, (string) $response->headers->get('Location'));
    }

    public function testEinNichtAngebotenesModellWirdAbgelehnt(): void
    {
        $this->publishArticle();

        $response = $this->controller(offers: $this->offersOf([5]))($this->post(['subscriptionRule' => '7']));

        self::assertSame(0, $this->countCarts());
        self::assertStringContainsString(CartNotice::PARAM.'='.CartNotice::NotOffered->value, (string) $response->headers->get('Location'));
    }

    public function testEineAblehnungLegtKeinenWarenkorbAn(): void
    {
        // A bot posting nonsense must leave neither a row nor a cookie
        // behind.
        $response = $this->controller()($this->post(['article' => '999']));

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame(0, $this->countCarts());
        self::assertSame(0, $this->countItems());
        self::assertSame([], $response->headers->getCookies());
    }

    public function testEineAblehnungLaesstDenBestehendenWarenkorbUnberuehrt(): void
    {
        // Since C-3c a rejection writes nothing to the cart any more — the
        // reason travels in the address. The existing cart keeps its line and
        // gets no notice that nobody reads there any more.
        $this->publishArticle();

        $token = CartToken::generate();
        $repository = new DbalCartRepository($this->cartConnection);
        $cartId = $repository->create(CartToken::hash($token), 0, self::SHOP, 1_000);
        $repository->addItem($cartId, self::ARTICLE, '1.0000', 1_000);

        $response = $this->controller()($this->post(['article' => '999'], $token));

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame(1, $this->countCarts());
        self::assertSame('', (string) $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart'));
        self::assertSame(1, $this->countItems());
        self::assertStringContainsString(
            CartNotice::PARAM.'='.CartNotice::Unavailable->value,
            (string) $response->headers->get('Location'),
            'der Grund steht in der Adresse, wo der Kaufknopf ihn zeigt',
        );
    }

    public function testEinVorhandenesCookieWirdNichtErneutGesetzt(): void
    {
        $this->publishArticle();

        $token = CartToken::generate();
        (new DbalCartRepository($this->cartConnection))->create(CartToken::hash($token), 0, self::SHOP, 1_000);

        $response = $this->controller()($this->post([], $token));

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame([], $response->headers->getCookies());
        self::assertSame(1, $this->countCarts());
        self::assertSame(1, $this->countItems());
    }

    public function testEinMitgebrachtesCookieWirdNichtErneuertAuchWennDerWarenkorbNeuEntsteht(): void
    {
        // The case in which the question "does the response need a cookie?"
        // actually decides something: the cookie is there, the cart behind it
        // no longer — the clean-up cron has taken it. A new one is created,
        // under the hash of the token brought along, and without Set-Cookie.
        // If the question hung on the newly created cart instead of on the
        // request, every such response would carry a cookie with exactly the
        // value the browser already has.
        //
        // Together with the two tests above — no cookie brought along, exactly
        // one back; cookie brought along and cart present, none back — all
        // three situations are thus checked via the headers, and none any more
        // via the return value of a helper method.
        $this->publishArticle();

        $token = CartToken::generate();

        $response = $this->controller()($this->post([], $token));

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame([], $response->headers->getCookies());
        self::assertSame(1, $this->countCarts());
        self::assertSame(
            CartToken::hash($token),
            $this->cartConnection->fetchOne('SELECT tokenHash FROM tl_consho_cart'),
        );
    }

    public function testEineUngueltigeMengeWirdNichtAlsHinzugefuegtGemeldet(): void
    {
        // addItem() returns false if the sum would overflow the column —
        // nothing was written then. Whoever swallows the return value reports
        // "added" and shows the old quantity.
        $this->publishArticle();

        $token = CartToken::generate();
        $repository = new DbalCartRepository($this->cartConnection);
        $cartId = $repository->create(CartToken::hash($token), 0, self::SHOP, 1_000);
        $repository->addItem($cartId, self::ARTICLE, Quantity::MAX, 1_000);

        $response = $this->controller()($this->post([], $token));

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertStringContainsString(
            CartNotice::PARAM.'='.CartNotice::InvalidQuantity->value,
            (string) $response->headers->get('Location'),
        );
        self::assertSame((float) Quantity::MAX, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item'));
    }

    public function testEineUnlesbareMengeLegtKeinenWarenkorbAn(): void
    {
        $this->publishArticle();

        $response = $this->controller()($this->post(['quantity' => 'viele']));

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame(0, $this->countCarts());

        // The reason the check arrived at, and not just any: refuse() only
        // passes it through, but without this assertion a `refuse(… Unavailable)`
        // survives the whole suite (C-3c). The mapping Verdict→Notice is
        // measured in PurchaseCheckTest; passing it through into the address
        // was not.
        self::assertStringContainsString(
            CartNotice::PARAM.'='.CartNotice::InvalidQuantity->value,
            (string) $response->headers->get('Location'),
        );
    }

    public function testEineFehlendeVariantenwahlNenntIhrenEigenenGrund(): void
    {
        // needs_choice was not measured via the route at all. Without one case
        // per reason the suite only checks that *some* reason arrives — and a
        // refuse() that always says "unavailable" survives it.
        //
        // A main product WITH variants is not goods but the choice before them
        // (PurchaseCheck): it is in the list, it cannot be bought.
        $this->publishArticle();
        $this->insertProduct(['id' => 22, 'pid' => self::ARTICLE, 'published' => 1]);

        $response = $this->controller()($this->post());

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertStringContainsString(
            CartNotice::PARAM.'='.CartNotice::NeedsChoice->value,
            (string) $response->headers->get('Location'),
        );
    }

    public function testDerWarenkorbEinesAnderenShopsBleibtUnberuehrt(): void
    {
        // The assertion is unchanged: an article from shop 1 must not land in
        // the cart of shop 2. The way there is a different one since C-3b — the
        // foreign cart is not even found any more, because a token carries one
        // per shop and this shop does not have one yet. Instead of a rejection,
        // a second cart is therefore created.
        $this->publishArticle();

        $token = CartToken::generate();
        $foreign = (new DbalCartRepository($this->cartConnection))->create(CartToken::hash($token), 0, self::OTHER_SHOP, 1_000);

        $response = $this->controller()($this->post([], $token));

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame(2, $this->countCarts(), 'dieser Shop bekommt einen eigenen Korb');
        self::assertSame(
            0,
            (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item WHERE pid = ?', [$foreign]),
            'im fremden Warenkorb darf keine Zeile entstehen',
        );
        self::assertSame(
            '',
            (string) $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart WHERE id = ?', [$foreign]),
            'und auch keine Notiz',
        );
    }

    public function testEinAngemeldetesMitgliedFuegtInDenEigenenWarenkorbEinNichtInDenDesCookies(): void
    {
        // CartResolver::resolve() ignores the cookie as soon as someone is
        // logged in (12) — the article must land in the member's cart, not in
        // the one a foreign cookie brought along points to. In addition, no
        // cookie may be issued for a member: their cart is found via the
        // account.
        $this->publishArticle();

        $foreignToken = CartToken::generate();
        $repository = new DbalCartRepository($this->cartConnection);
        $repository->create(CartToken::hash($foreignToken), 0, self::SHOP, 1_000);
        $memberCartId = $repository->create(null, self::MEMBER, self::SHOP, 1_000);

        $response = $this->controller(self::MEMBER)($this->post([], $foreignToken));

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame([], $response->headers->getCookies());
        self::assertSame(2, $this->countCarts());
        self::assertSame(1, $this->countItems());
        self::assertSame(
            $memberCartId,
            (int) $this->cartConnection->fetchOne('SELECT pid FROM tl_consho_cart_item'),
        );
        self::assertSame(
            CartNotice::Added->value,
            $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart WHERE id = ?', [$memberCartId]),
        );
    }

    public function testEineAblehnungSchreibtKeineNotizInDenFremdenWarenkorb(): void
    {
        // The article is only visible in shop A, the cookie points to a cart of
        // shop B, and the quantity is invalid (0). The rejection must not be
        // written to the cart of shop B — that one never had anything to do
        // with this article.
        //
        // ⚠️ Since C-3b it lands nowhere: this shop has no cart yet to which it
        // could be written, and a rejection does not create one. So the visitor
        // gets no feedback at all — exactly the gap C-3c closes by moving the
        // reason to the purchase button instead of onto a cart line.
        $this->publishArticle();

        $token = CartToken::generate();
        $repository = new DbalCartRepository($this->cartConnection);
        $cartId = $repository->create(CartToken::hash($token), 0, self::OTHER_SHOP, 1_000);

        $response = $this->controller()($this->post(['quantity' => '0'], $token));

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame(
            '',
            (string) $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart WHERE id = ?', [$cartId]),
        );
        self::assertSame(1, $this->countCarts(), 'eine Ablehnung legt keinen Warenkorb an');
        self::assertSame(0, $this->countItems());
    }

    public function testOhneSeiteAntwortetDieRouteMitVierhundert(): void
    {
        $response = $this->controller()($this->post(['page' => '0']));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    public function testOhneShopAntwortetDieRouteMitVierhundert(): void
    {
        // The page belongs to no shop (consho_shop = 0 on the root page) —
        // without a shop there is neither a visibility context nor a cart to
        // which an article could be assigned.
        $this->publishArticle();
        $this->rootShopId = 0;

        $response = $this->controller()($this->post());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame(0, $this->countCarts());
    }

    public function testZurueckZurSeiteFolgtNurEinemVerweisDerselbenHerkunft(): void
    {
        $this->publishArticle();
        $this->afterAddToCart = 'page';

        $eigener = $this->controller()($this->post(referer: self::HOST.'/katalog/schuhe.html?seite=2'));
        self::assertSame(self::HOST.'/katalog/schuhe.html?seite=2', $eigener->headers->get('Location'));

        // Without a usable referrer the cart page, not the product page
        // (specification 7.1, step 4). "Back to the page" is a promise about
        // where the visitor came from — and that is exactly what is missing here.
        $fremder = $this->controller()($this->post(referer: 'https://boese.example/weiter'));
        self::assertSame(self::CART_URL, $fremder->headers->get('Location'));

        $ohne = $this->controller()($this->post());
        self::assertSame(self::CART_URL, $ohne->headers->get('Location'));
    }

    public function testEineAblehnungGehtDorthinZurueckWoGeklicktWurde(): void
    {
        // Reversed with C-3c: the reason is no longer written onto a cart line
        // but shown by the purchase button — so the visitor belongs back on the
        // page they came from, regardless of "Nach dem Hinzufügen" (after
        // adding). The cart page could no longer render the reason at all.
        $this->publishArticle();
        $this->afterAddToCart = 'page';

        $token = CartToken::generate();
        (new DbalCartRepository($this->cartConnection))->create(CartToken::hash($token), 0, self::SHOP, 1_000);

        $response = $this->controller()($this->post(['article' => '999'], $token, referer: self::CATALOGUE_URL));

        self::assertSame(
            self::CATALOGUE_URL.'?'.CartNotice::PARAM.'='.CartNotice::Unavailable->value,
            $response->headers->get('Location'),
        );
        self::assertSame('', (string) $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart'));
    }

    /**
     * Block G (G7): sold out does not go into the cart. More than what is
     * left does, though — the cart says what is left (G5).
     */
    public function testAusverkauftKommtNichtInDenKorb(): void
    {
        $this->publishArticle();
        $this->afterAddToCart = 'page';
        $this->cartConnection->update('tl_consho_product', ['stockTracked' => 1], ['id' => self::ARTICLE]);
        (new StockRows($this->cartConnection))->sync(self::ARTICLE, 1_000);

        $token = CartToken::generate();
        (new DbalCartRepository($this->cartConnection))->create(CartToken::hash($token), 0, self::SHOP, 1_000);

        $response = $this->controller()($this->post(['article' => (string) self::ARTICLE], $token, referer: self::CATALOGUE_URL));

        self::assertSame(
            self::CATALOGUE_URL.'?'.CartNotice::PARAM.'='.CartNotice::OutOfStock->value,
            $response->headers->get('Location'),
        );
        self::assertSame(0, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item'));

        // One piece there, three wanted: goes into the cart.
        $this->cartConnection->update('tl_consho_stock', ['quantity' => '1.0000'], ['article' => self::ARTICLE]);
        $this->controller()($this->post(['article' => (string) self::ARTICLE, 'quantity' => '3'], $token, referer: self::CATALOGUE_URL));

        self::assertSame(1, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item'));
    }

    public function testEineAblehnungGehtAuchImWarenkorbModusZurueckZurSeite(): void
    {
        // "Zum Warenkorb" (to the cart) applies to the success case. A rejection
        // has nothing to do with the cart — otherwise the visitor would stand in
        // front of a cart in which nothing has changed, and without an
        // explanation why.
        $this->publishArticle();
        $this->afterAddToCart = 'cart';

        $token = CartToken::generate();
        (new DbalCartRepository($this->cartConnection))->create(CartToken::hash($token), 0, self::SHOP, 1_000);

        $response = $this->controller()($this->post(['article' => '999'], $token, referer: self::CATALOGUE_URL));

        self::assertSame(
            self::CATALOGUE_URL.'?'.CartNotice::PARAM.'='.CartNotice::Unavailable->value,
            $response->headers->get('Location'),
        );
    }

    public function testAuchEineAbgelehnteMengeGehtZurueckZurSeite(): void
    {
        // The same rule for the rejection that only addItem() detects: capping
        // the column is no different kind of rejection than invisible goods and
        // takes the same path.
        $this->publishArticle();
        $this->afterAddToCart = 'page';

        $token = CartToken::generate();
        $repository = new DbalCartRepository($this->cartConnection);
        $cartId = $repository->create(CartToken::hash($token), 0, self::SHOP, 1_000);
        $repository->addItem($cartId, self::ARTICLE, Quantity::MAX, 1_000);

        $response = $this->controller()($this->post([], $token, referer: self::CATALOGUE_URL));

        self::assertSame(
            self::CATALOGUE_URL.'?'.CartNotice::PARAM.'='.CartNotice::InvalidQuantity->value,
            $response->headers->get('Location'),
        );
        self::assertSame('', (string) $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart'));
    }

    public function testEineAblehnungBrauchtDieWarenkorbseiteNichtMehr(): void
    {
        // Without a configured cart page nothing changes for a rejection since
        // C-3c: it went there before and now goes back to the page anyway. The
        // reason travels along unchanged.
        $this->publishArticle();
        $this->afterAddToCart = 'page';
        $this->cartPageId = 0;

        $token = CartToken::generate();
        (new DbalCartRepository($this->cartConnection))->create(CartToken::hash($token), 0, self::SHOP, 1_000);

        $response = $this->controller()($this->post(['article' => '999'], $token, referer: self::CATALOGUE_URL));

        self::assertSame(
            self::CATALOGUE_URL.'?'.CartNotice::PARAM.'='.CartNotice::Unavailable->value,
            $response->headers->get('Location'),
        );
    }

    public function testEinErfolgNimmtDieAlteAblehnungAusDerAdresse(): void
    {
        // C-3c: the return goes to the referrer — and after a rejection that
        // already carries ?consho_notice=. Whoever corrects the quantity and
        // clicks again would land with the OLD message next to an incremented
        // mini cart. A wrong message is worse than the silence C-3c was meant
        // to fix.
        $this->publishArticle();
        $this->afterAddToCart = 'page';

        $token = CartToken::generate();
        (new DbalCartRepository($this->cartConnection))->create(CartToken::hash($token), 0, self::SHOP, 1_000);

        $nachAblehnung = self::CATALOGUE_URL.'?'.CartNotice::PARAM.'='.CartNotice::InvalidQuantity->value;
        $response = $this->controller()($this->post([], $token, referer: $nachAblehnung));

        self::assertSame(self::CATALOGUE_URL, $response->headers->get('Location'));
        self::assertSame(1, $this->countItems(), 'und der Artikel ist wirklich drin');
    }

    public function testDerGrundStehtGenauEinmalInDerAdresse(): void
    {
        // Three rejections in a row appended the parameter three times. PHP
        // reads the last one, so the display was right — but every length is
        // an entry of its own in the shared cache, and that is exactly what
        // this design was not supposed to cost.
        $this->publishArticle();
        $this->afterAddToCart = 'page';

        $token = CartToken::generate();
        (new DbalCartRepository($this->cartConnection))->create(CartToken::hash($token), 0, self::SHOP, 1_000);

        $adresse = self::CATALOGUE_URL;

        for ($klick = 0; $klick < 3; ++$klick) {
            $adresse = (string) $this->controller()($this->post(['article' => '999'], $token, referer: $adresse))->headers->get('Location');
        }

        self::assertSame(
            self::CATALOGUE_URL.'?'.CartNotice::PARAM.'='.CartNotice::Unavailable->value,
            $adresse,
        );
        self::assertSame(1, substr_count($adresse, CartNotice::PARAM), 'genau einmal, nicht dreimal');
    }

    public function testEinVorhandenerAbfrageteilBleibtErhalten(): void
    {
        // A product page with a chosen variant carries its own parameters.
        // They must not disappear, otherwise the rejection leads to a different
        // variant than the one clicked.
        $this->publishArticle();
        $this->afterAddToCart = 'page';

        $token = CartToken::generate();
        (new DbalCartRepository($this->cartConnection))->create(CartToken::hash($token), 0, self::SHOP, 1_000);

        $response = $this->controller()($this->post(['article' => '999'], $token, referer: self::CATALOGUE_URL.'?variant=5'));

        self::assertSame(
            self::CATALOGUE_URL.'?variant=5&'.CartNotice::PARAM.'='.CartNotice::Unavailable->value,
            $response->headers->get('Location'),
        );
    }

    public function testEinAnkerVerschlucktDenGrundNicht(): void
    {
        // "seite.html#top" plus "?consho_notice=…" would give
        // "seite.html#top?consho_notice=…" — the parameter would sit in the
        // anchor and never reach the server.
        $this->publishArticle();
        $this->afterAddToCart = 'page';

        $token = CartToken::generate();
        (new DbalCartRepository($this->cartConnection))->create(CartToken::hash($token), 0, self::SHOP, 1_000);

        $response = $this->controller()($this->post(['article' => '999'], $token, referer: self::CATALOGUE_URL.'#kaufen'));

        self::assertSame(
            self::CATALOGUE_URL.'?'.CartNotice::PARAM.'='.CartNotice::Unavailable->value.'#kaufen',
            $response->headers->get('Location'),
        );
    }

    public function testOhneBrauchbarenVerweisFuehrtDieAblehnungZurSeiteDesKaufknopfs(): void
    {
        // No referrer of the same origin: then the page the form named stays.
        // For a product page that is the page without a product — still better
        // than the cart, which no longer renders the reason at all.
        $this->publishArticle();
        $this->afterAddToCart = 'page';

        $token = CartToken::generate();
        (new DbalCartRepository($this->cartConnection))->create(CartToken::hash($token), 0, self::SHOP, 1_000);

        $response = $this->controller()($this->post(['article' => '999'], $token, referer: 'https://fremde.example/seite'));

        $location = (string) $response->headers->get('Location');

        self::assertStringStartsWith(self::HOST, $location, 'niemals die fremde Herkunft — das wäre eine offene Weiterleitung');
        self::assertStringContainsString(CartNotice::PARAM.'='.CartNotice::Unavailable->value, $location);
    }

    public function testEinErfolgImModusZurueckZurSeiteSetztKeineNotiz(): void
    {
        // The feedback here is the number in the mini cart that goes up. A
        // notice has no expiry — otherwise it would still be there days later,
        // whenever the visitor opens the cart page.
        $this->publishArticle();
        $this->afterAddToCart = 'page';

        $response = $this->controller()($this->post(referer: self::CATALOGUE_URL));

        self::assertSame(self::CATALOGUE_URL, $response->headers->get('Location'));
        self::assertSame('', $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart'));
        self::assertSame(1, $this->countItems());
    }

    public function testImModusZumWarenkorbBleibtDieErfolgsmeldung(): void
    {
        // There the path leads to the page that shows it anyway, and
        // immediately — the question of expiry does not arise.
        $this->publishArticle();
        $this->afterAddToCart = 'cart';

        $response = $this->controller()($this->post());

        self::assertSame(self::CART_URL, $response->headers->get('Location'));
        self::assertSame(CartNotice::Added->value, $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart'));
    }

    public function testOhneWarenkorbseiteGehtEsZurueckZurSeite(): void
    {
        // A dead address would not help the visitor; C-6 flags the missing
        // cart page in the back end.
        $this->publishArticle();
        $this->cartPageId = 0;

        self::assertSame(self::CATALOGUE_URL, $this->controller()($this->post())->headers->get('Location'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function publishArticle(): void
    {
        $this->insertProduct(['id' => self::ARTICLE, 'published' => 1, 'minPurchase' => '1.0000', 'purchaseSteps' => '1.0000']);
        $this->insertProductShop(['pid' => self::ARTICLE, 'shop' => self::SHOP]);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function post(array $overrides = [], string|null $token = null, string $origin = self::HOST, string|null $referer = null): Request
    {
        $request = Request::create(
            self::HOST.'/_consho/cart/add',
            'POST',
            $overrides + ['page' => (string) self::CATALOGUE_PAGE, 'article' => (string) self::ARTICLE, 'quantity' => '3'],
        );

        $request->headers->set('Origin', $origin);

        if (null !== $referer) {
            $request->headers->set('referer', $referer);
        }

        if (null !== $token) {
            $request->cookies->set(CartToken::NAME, $token);
        }

        return $request;
    }

    private function countCarts(): int
    {
        return (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart');
    }

    private function countItems(): int
    {
        return (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item');
    }

    /**
     * @param list<int> $offered the models offered to everyone
     */
    private function offersOf(array $offered): SubscriptionOffers
    {
        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->method('allows')->willReturnCallback(
            static fn (int $article, int $rule): bool => \in_array($rule, $offered, true),
        );

        return $offers;
    }

    private function controller(int $memberId = 0, SubscriptionOffers|null $offers = null): AddToCartController
    {
        $catalogue = $this->pageModel(self::CATALOGUE_PAGE, []);
        $cartPage = $this->pageModel(self::CART_PAGE, []);
        $root = $this->pageModel(self::ROOT_PAGE, ['consho_shop' => $this->rootShopId, 'consho_cartPage' => $this->cartPageId]);

        $framework = $this->framework(
            [self::CATALOGUE_PAGE => $catalogue, self::ROOT_PAGE => $root, self::CART_PAGE => $cartPage],
            $this->shopModel(),
        );

        $urls = $this->urlGenerator([
            spl_object_id($catalogue) => self::CATALOGUE_URL,
            spl_object_id($cartPage) => self::CART_URL,
        ]);

        $shops = new ShopResolver($framework);

        return new AddToCartController(
            $framework,
            new CartResolver(new DbalCartRepository($this->cartConnection), $this->tokenStorage($memberId)),
            new DbalCartRepository($this->cartConnection),
            new PurchaseCheck($this->cartConnection, new VisibilityCriteria(), $offers),
            $shops,
            new ShopLanguages($this->cartConnection),
            $urls,
            // Only ShopPageUrls reads the field consho_cartPage — the mini cart
            // links to the same page.
            new ShopPageUrls($framework, $shops, $urls),
            new PriceAudienceResolver($this->tokenStorage($memberId), $this->cartConnection),
            new Availability($this->cartConnection),
        );
    }

    private function tokenStorage(int $memberId): TokenStorage
    {
        $storage = new TokenStorage();

        if ($memberId > 0) {
            $storage->setToken(new UsernamePasswordToken($this->frontendUser($memberId), 'contao_frontend', []));
        }

        return $storage;
    }

    /**
     * A real `Contao\FrontendUser` cannot be built with `new` without a
     * running framework — the constructor is protected. Via reflection,
     * `newInstanceWithoutConstructor()` works entirely without a container and
     * without a database (see CartResolverTest).
     */
    private function frontendUser(int $memberId): FrontendUser
    {
        $user = (new \ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
        $user->id = $memberId;
        $user->username = 'member'.$memberId;
        $user->groups = serialize(array_map('strval', $this->memberGroups));

        return $user;
    }

    /**
     * @param array<int, PageModel> $pages
     */
    private function framework(array $pages, ShopModel $shop): ContaoFramework
    {
        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(
            static fn (string $method, array $parameters): PageModel|null => $pages[(int) ($parameters[0] ?? 0)] ?? null,
        );

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

    private function shopModel(): ShopModel
    {
        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnMap([
            ['id', self::SHOP],
            ['afterAddToCart', $this->afterAddToCart],
        ]);

        return $shop;
    }

    /**
     * @param array<int, string> $addresses by spl_object_id of the page
     */
    private function urlGenerator(array $addresses): ContentUrlGenerator
    {
        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturnCallback(
            static function (object $content, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH) use ($addresses): string {
                self::assertSame(UrlGeneratorInterface::ABSOLUTE_URL, $referenceType, 'ein Rücksprung braucht eine absolute Adresse');

                return $addresses[spl_object_id($content)] ?? self::HOST.'/';
            },
        );

        return $urls;
    }

    /**
     * ⚠️ This double only hands out `rootId` **after** loadDetails() has been
     * called — just like the real PageModel. `rootId` is not a column of
     * tl_page but a property PageModel sets while walking up the tree.
     *
     * Before, the double answered with ROOT_PAGE unasked. The suite was thus
     * blind to a caller that forgets loadDetails(): on a real installation
     * every "add to cart" from a subpage ended with a 400, while everything
     * was green here. Since this version the same error shows up in **every**
     * test of this class instead of in none — a dedicated check for it would
     * be the weaker safeguard.
     *
     * @param array<string, int> $row
     */
    private function pageModel(int $id, array $row): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $detailsLoaded = false;

        $page->method('loadDetails')->willReturnCallback(
            static function () use (&$detailsLoaded): null {
                $detailsLoaded = true;

                return null;
            },
        );

        // A real closure with `&$detailsLoaded`, not an arrow function: that one
        // binds only copies, and the flag would stay false forever.
        $page->method('__get')->willReturnCallback(
            static function (string $key) use ($id, &$detailsLoaded): int|string|null {
                return match ($key) {
                    'id' => $id,
                    'rootId' => $detailsLoaded ? self::ROOT_PAGE : null,
                    'language' => 'de',
                    default => null,
                };
            },
        );
        $page->method('row')->willReturn($row + ['id' => $id]);

        return $page;
    }
}

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
 * Die Route, die als erste in Consho etwas aus dem offenen Netz entgegennimmt.
 *
 * Die Prüfungen laufen gegen echte Mitspieler: PurchaseCheck, CartResolver und
 * DbalCartRepository sind `final` und lassen sich ohnehin nicht doubeln — sie
 * arbeiten hier auf einer SQLite-Datenbank im Arbeitsspeicher, die beide
 * Testschemata trägt (Katalog und Warenkorb) und sich eine Verbindung teilt.
 * Gedoubelt ist nur, was ein laufendes Contao bräuchte: das Framework mit
 * seinen Adaptern, die Seiten- und Shop-Datensätze und der Adressgenerator.
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

    /** @var list<int> die Gruppen des angemeldeten Mitglieds (Abo-2) */
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

    // ── Warum die Route die Anfrage-Prüfung ausnimmt ────────────────────────

    public function testDieRouteNimmtDieAnfragePruefungAus(): void
    {
        // C11: eine geteilt gecachte Seite kann das Token eines anderen
        // Besuchers tragen. Stattdessen SameSite=Lax und die Origin-Prüfung.
        $attributes = (new \ReflectionMethod(AddToCartController::class, '__invoke'))->getAttributes(Route::class);

        self::assertCount(1, $attributes);

        // Die Eigenschaften, nicht die Getter: getPath() & Co. sind seit
        // symfony/routing 7.4 veraltet.
        $route = $attributes[0]->newInstance();

        self::assertSame('/_consho/cart/add', $route->path);
        self::assertFalse($route->defaults['_token_check']);
        self::assertSame('frontend', $route->defaults['_scope']);
        self::assertSame(['POST'], $route->methods);
    }

    public function testDieKlasseIstAlsControllerAusgezeichnet(): void
    {
        // Ohne das Attribut bleibt der Dienst privat, der Controller-Resolver
        // bekommt ihn nicht aus dem Container und baut ihn mit `new` — was am
        // Konstruktor scheitert. Die Route gäbe es dann, arbeiten würde sie nie.
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

    // ── Was die Route tatsächlich tut ───────────────────────────────────────

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

        // Der Hash im Datensatz gehört zum ausgelieferten Cookie — sonst fände
        // der nächste Aufruf den Warenkorb nicht wieder.
        self::assertSame(
            CartToken::hash((string) $cookies[0]->getValue()),
            $this->cartConnection->fetchOne('SELECT tokenHash FROM tl_consho_cart'),
        );

        self::assertSame(CartNotice::Added->value, $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart'));

        // SQLite gibt eine NUMERIC-Spalte nicht zeichengetreu zurück, deshalb
        // numerisch vergleichen.
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
        // Fable-Review Abo-2, L3: ohne diesen Test sähe eine Route, die die
        // Gruppen vergisst, genauso grün aus. Seit Variante A entscheiden sie
        // allein (keine Geschäftskunden-Sperre mehr).
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
        // Formulare ohne Modellwahl — jedes Produkt vor Abo-2, jedes reine
        // Einmalkauf-Produkt danach — bleiben gültig.
        $this->publishArticle();

        $this->controller(offers: $this->offersOf([0]))($this->post());

        self::assertSame(0, (int) $this->cartConnection->fetchOne('SELECT subscriptionRule FROM tl_consho_cart_item'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadablePlans(): iterable
    {
        // Abo-1, bf4dda4: `(int) "Monatsabo"` war 0 — der Einmalkauf. Nur
        // Ziffern gelten, alles andere ist ein Modell, das es nicht gibt.
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
        // Ein Bot, der Unsinn postet, darf weder eine Zeile noch ein Cookie
        // hinterlassen.
        $response = $this->controller()($this->post(['article' => '999']));

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame(0, $this->countCarts());
        self::assertSame(0, $this->countItems());
        self::assertSame([], $response->headers->getCookies());
    }

    public function testEineAblehnungLaesstDenBestehendenWarenkorbUnberuehrt(): void
    {
        // Seit C-3c schreibt eine Ablehnung nichts mehr an den Warenkorb —
        // der Grund reist in der Adresse. Der vorhandene Korb behält seine
        // Position und bekommt keine Notiz, die dort niemand mehr liest.
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
        // Der Fall, in dem die Frage „braucht die Antwort ein Cookie?"
        // tatsächlich etwas entscheidet: Das Cookie ist da, der Warenkorb
        // dahinter nicht mehr — die Aufräum-Cron hat ihn geholt. Es entsteht
        // ein neuer, unter dem Hash des mitgebrachten Tokens, und ohne
        // Set-Cookie. Hinge die Frage am neu angelegten Warenkorb statt an der
        // Anfrage, trüge jede solche Antwort ein Cookie mit genau dem Wert,
        // den der Browser ohnehin schon hat.
        //
        // Zusammen mit den beiden Tests darüber — kein Cookie mitgebracht,
        // genau eines zurück; Cookie mitgebracht und Warenkorb vorhanden,
        // keines zurück — sind damit alle drei Lagen über die Kopfzeilen
        // geprüft, und keine mehr über den Rückgabewert einer Hilfsmethode.
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
        // addItem() liefert false, wenn die Summe die Spalte sprengen würde —
        // geschrieben wurde dann nichts. Wer den Rückgabewert verschluckt,
        // meldet „hinzugefügt" und zeigt die alte Menge.
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

        // Der Grund, den die Prüfung gefällt hat, und nicht irgendeiner:
        // refuse() reicht ihn nur durch, aber ohne diese Zusicherung überlebt
        // ein `refuse(… Unavailable)` die ganze Suite (Abschlussreview C-3c).
        // Die Zuordnung Verdict→Notice ist in PurchaseCheckTest gemessen, die
        // Durchreichung bis in die Adresse war es nicht.
        self::assertStringContainsString(
            CartNotice::PARAM.'='.CartNotice::InvalidQuantity->value,
            (string) $response->headers->get('Location'),
        );
    }

    public function testEineFehlendeVariantenwahlNenntIhrenEigenenGrund(): void
    {
        // needs_choice war über die Route überhaupt nicht gemessen. Ohne einen
        // Fall je Grund prüft die Suite nur, dass *irgendein* Grund ankommt —
        // und ein refuse(), das immer „unavailable" sagt, überlebt sie.
        //
        // Ein Hauptprodukt MIT Varianten ist keine Ware, sondern die Wahl
        // davor (PurchaseCheck): Es steht in der Liste, kaufen lässt es sich
        // nicht.
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
        // Die Zusicherung ist unverändert: Ein Artikel aus Shop 1 darf nicht
        // im Warenkorb von Shop 2 landen. Der Weg dorthin ist seit C-3b ein
        // anderer — der fremde Korb wird gar nicht mehr gefunden, weil ein
        // Token je Shop einen trägt und dieser Shop noch keinen hat. Statt
        // einer Ablehnung entsteht deshalb ein zweiter Korb.
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
        // CartResolver::resolve() ignoriert das Cookie, sobald jemand
        // angemeldet ist (12) — der Artikel muss im Warenkorb des Mitglieds
        // landen, nicht in dem, auf den ein mitgebrachtes fremdes Cookie
        // zeigt. Zusätzlich darf für ein Mitglied kein Cookie ausgestellt
        // werden: sein Warenkorb wird über das Konto gefunden.
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
        // Der Artikel ist nur in Shop A sichtbar, das Cookie zeigt auf einen
        // Warenkorb von Shop B, und die Menge ist ungültig (0). Die Ablehnung
        // darf nicht auf den Warenkorb von Shop B geschrieben werden — der
        // hat mit diesem Artikel nie etwas zu tun gehabt.
        //
        // ⚠️ Seit C-3b landet sie nirgends: Dieser Shop hat noch keinen Korb,
        // auf den sie geschrieben werden könnte, und eine Ablehnung legt
        // keinen an. Der Besucher bekommt also gar keine Rückmeldung — genau
        // die Lücke, die C-3c schließt, indem der Grund an den Kaufknopf
        // wandert statt auf eine Warenkorbzeile.
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
        // Die Seite gehört zu keinem Shop (consho_shop = 0 auf der
        // Root-Seite) — ohne Shop gibt es weder einen Sichtbarkeits-Kontext
        // noch einen Warenkorb, dem ein Artikel zugeordnet werden könnte.
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

        // Ohne brauchbaren Verweis die Warenkorbseite, nicht die Produktseite
        // (Spezifikation 7.1 Schritt 4). „Zurück zur Seite" ist eine Zusage
        // darüber, woher der Besucher kam — und genau die fehlt hier.
        $fremder = $this->controller()($this->post(referer: 'https://boese.example/weiter'));
        self::assertSame(self::CART_URL, $fremder->headers->get('Location'));

        $ohne = $this->controller()($this->post());
        self::assertSame(self::CART_URL, $ohne->headers->get('Location'));
    }

    public function testEineAblehnungGehtDorthinZurueckWoGeklicktWurde(): void
    {
        // Umgedreht mit C-3c: Der Grund wird nicht mehr auf eine
        // Warenkorbzeile geschrieben, sondern vom Kaufknopf gezeigt — also
        // gehört der Besucher zurück auf die Seite, von der er kam, und zwar
        // unabhängig von „Nach dem Hinzufügen". Die Warenkorbseite könnte den
        // Grund gar nicht mehr rendern.
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
     * Block G (G7): ausverkauft kommt nicht in den Korb. Mehr als übrig ist
     * dagegen schon — der Korb sagt, was übrig ist (G5).
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

        // Ein Stück da, drei gewünscht: kommt in den Korb.
        $this->cartConnection->update('tl_consho_stock', ['quantity' => '1.0000'], ['article' => self::ARTICLE]);
        $this->controller()($this->post(['article' => (string) self::ARTICLE, 'quantity' => '3'], $token, referer: self::CATALOGUE_URL));

        self::assertSame(1, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item'));
    }

    public function testEineAblehnungGehtAuchImWarenkorbModusZurueckZurSeite(): void
    {
        // „Zum Warenkorb" gilt für den Erfolgsfall. Eine Ablehnung hat mit dem
        // Warenkorb nichts zu tun (Michael, 2026-09-20) — sonst stünde der
        // Besucher vor einem Korb, in dem sich nichts geändert hat, und ohne
        // Erklärung, warum.
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
        // Dieselbe Regel für die Ablehnung, die erst addItem() feststellt:
        // Die Deckelung der Spalte ist keine andere Art von Ablehnung als
        // eine unsichtbare Ware und nimmt denselben Weg.
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
        // Ohne eingestellte Warenkorbseite ändert sich für eine Ablehnung
        // seit C-3c nichts: Sie ging vorher dorthin und geht jetzt ohnehin
        // zurück zur Seite. Der Grund reist unverändert mit.
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
        // Gefunden im Abschlussreview von C-3c. Der Rücksprung geht auf den
        // Referer — und der trägt nach einer Ablehnung bereits
        // ?consho_notice=. Wer die Menge korrigiert und erneut klickt, landete
        // mit der ALTEN Meldung neben einem hochgezählten Mini-Warenkorb.
        // Eine falsche Meldung ist schlimmer als das Schweigen, das C-3c
        // beheben sollte.
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
        // Drei Ablehnungen hintereinander hängten den Parameter dreimal an.
        // PHP liest den letzten, die Anzeige stimmte also — aber jede Länge
        // ist ein eigener Eintrag im geteilten Cache, und genau das sollte
        // dieser Entwurf nicht kosten.
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
        // Eine Produktseite mit gewählter Variante trägt ihre eigenen
        // Parameter. Die dürfen nicht verschwinden, sonst führt die Ablehnung
        // auf eine andere Variante als die angeklickte.
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
        // „seite.html#top" plus „?consho_notice=…" ergäbe
        // „seite.html#top?consho_notice=…" — der Parameter stünde im Anker
        // und käme nie am Server an.
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
        // Kein Referer derselben Herkunft: Dann bleibt die Seite, die das
        // Formular genannt hat. Für eine Produktseite ist das die Seite ohne
        // Produkt — immer noch besser als der Warenkorb, der den Grund gar
        // nicht mehr rendert.
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
        // Befund 6b: Die Rückmeldung ist hier die Zahl im Mini-Warenkorb, die
        // hochgeht. Eine Notiz hat keine Frist — sie stünde sonst noch Tage
        // später da, wenn der Besucher irgendwann die Warenkorbseite öffnet.
        $this->publishArticle();
        $this->afterAddToCart = 'page';

        $response = $this->controller()($this->post(referer: self::CATALOGUE_URL));

        self::assertSame(self::CATALOGUE_URL, $response->headers->get('Location'));
        self::assertSame('', $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart'));
        self::assertSame(1, $this->countItems());
    }

    public function testImModusZumWarenkorbBleibtDieErfolgsmeldung(): void
    {
        // Dort führt der Weg ohnehin auf die Seite, die sie zeigt, und zwar
        // sofort — die Frage nach der Frist stellt sich nicht.
        $this->publishArticle();
        $this->afterAddToCart = 'cart';

        $response = $this->controller()($this->post());

        self::assertSame(self::CART_URL, $response->headers->get('Location'));
        self::assertSame(CartNotice::Added->value, $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart'));
    }

    public function testOhneWarenkorbseiteGehtEsZurueckZurSeite(): void
    {
        // Eine tote Adresse hülfe dem Besucher nicht; C-6 mahnt die fehlende
        // Warenkorbseite im Backend an.
        $this->publishArticle();
        $this->cartPageId = 0;

        self::assertSame(self::CATALOGUE_URL, $this->controller()($this->post())->headers->get('Location'));
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────

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
     * @param list<int> $offered die Modelle, die jedem angeboten werden
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
            // Seit Task 10 liest nur noch ShopPageUrls das Feld consho_cartPage
            // — der Mini-Warenkorb verweist auf dieselbe Seite.
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
     * Ein echter `Contao\FrontendUser` lässt sich ohne laufendes Framework
     * nicht über `new` bauen — der Konstruktor ist protected. Per Reflection
     * `newInstanceWithoutConstructor()` geht es ganz ohne Container und ohne
     * Datenbank (siehe CartResolverTest).
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
     * @param array<int, string> $addresses nach spl_object_id der Seite
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
     * ⚠️ `rootId` gibt dieses Doppel erst heraus, **nachdem** loadDetails()
     * gerufen wurde — genau wie das echte PageModel. `rootId` ist keine Spalte
     * von tl_page, sondern eine Eigenschaft, die PageModel beim Hochlaufen des
     * Baums setzt.
     *
     * Vorher antwortete das Doppel ungefragt mit ROOT_PAGE. Die Suite war
     * damit blind für einen Aufrufer, der loadDetails() vergisst: Auf c5 ging
     * am 2026-09-20 jedes „in den Warenkorb" von einer Unterseite mit 400 aus,
     * während hier alles grün lief. Seit dieser Fassung fällt derselbe Fehler
     * in **jedem** Test dieser Klasse auf, statt in gar keinem — eine eigene
     * Prüfung dafür wäre die schwächere Absicherung.
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

        // Eine echte Closure mit `&$detailsLoaded`, keine Pfeilfunktion: die
        // bindet nur Kopien, und das Flag bliebe für immer false.
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

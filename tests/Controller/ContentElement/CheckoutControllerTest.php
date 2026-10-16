<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Intl\Countries;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webwerkwien\ContaoConshoBundle\Order\TotalDisagreesWithCheckout;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStart;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStarter;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationInterface;
use Webwerkwien\ContaoConshoBundle\Order\PlacedOrder;
use Webwerkwien\ContaoConshoBundle\Order\PurchaseHandlerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Contao\FrontendUser;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Webwerkwien\ContaoConshoBundle\Address\AddressNormalizer;
use Webwerkwien\ContaoConshoBundle\Address\PhoneNormalizer;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Address\VatIdValidatorInterface;
use Webwerkwien\ContaoConshoBundle\Cart\CartAgeLimit;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;
use Webwerkwien\ContaoConshoBundle\Cart\CartResolver;
use Webwerkwien\ContaoConshoBundle\Cart\CartToken;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Checkout\AgeGate;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutCalculator;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutData;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutState;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness;
use Webwerkwien\ContaoConshoBundle\Checkout\ShippingTaxSplitter;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\CheckoutController;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\FlatRateShipping;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethod;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypes;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;
use Webwerkwien\ContaoConshoBundle\Cart\CartTitles;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityLabelImages;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutFingerprint;
use Webwerkwien\ContaoConshoBundle\Checkout\MemberCheckoutDefaults;
use Webwerkwien\ContaoConshoBundle\Checkout\DbalLegalTextSource;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCountryCoverage;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLineTerms;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRenewal;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Cart\CartSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Checkout\MemberSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Die Verdrahtung des Checkout-Elements.
 *
 * ⚠️ Warum es diesen Test gibt: Der Rechenschritt ist einzeln geprüft, aber
 * ein Test, der den Rechner direkt füttert, prüft nicht, ob der Controller ihn
 * überhaupt fragt — und ob ein Formular dort ankommt, wo es hin soll. Genau
 * diese Lücke war am 2026-09-21 der Befund zu `basePriceNet` (Fall 24 der
 * Vault-Notiz „Prüfungen, die nicht prüfen was man glaubt").
 *
 * Der Warenkorb läuft hier echt über SQLite; Preise, Versand- und
 * Zahlungsarten sind Doubles, weil sie an dieser Frage nichts ändern.
 */
class CheckoutControllerTest extends TestCase
{
    use CartSchema;
    use MemberSchema;

    private const SHOP = 1;
    private const PAGE = 123;
    private const ROOT = 1;
    private const ARTICLE = 7;

    /**
     * Die Bestellseite am Startpunkt. Ohne sie beantwortet der Kaufklick
     * `noOrderPage` — und das ist kein Testdetail, sondern die Absicht:
     * Eine Bestellung, deren Bestätigung niemand sehen kann, entsteht nicht.
     */
    private const ORDER_PAGE = 456;

    /** Auf 0 gesetzt heißt: Der Startpunkt nennt keine Bestellseite. */
    private int $orderPage = self::ORDER_PAGE;

    /**
     * Genau 43 base64url-Zeichen. CartToken::fromRequest() filtert auf die
     * Form, die generate() erzeugt — ein kürzeres Testtoken fällt durch, und
     * der Controller fände dann nie einen Warenkorb.
     */
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private DbalCartRepository $repository;
    private int $cartId;

    private VatIdSpy $vatIdSpy;

    /** @var list<ConfigurationIssueSource> */
    private array $blockers = [];

    private bool $guestCheckout = true;

    private bool $termsRequired = false;

    /** F-3: der Artikel des Tests ist digital. */
    private bool $digital = false;

    // DL1: die Art des digitalen Artikels — digital oder Dienstleistung.
    private string $digitalKind = 'digital';

    // Abo-2: die Gruppen des Mitglieds.

    /** @var list<int> */
    private array $memberGroups = [];

    private int $memberId = 0;

    /** @var list<string> */
    private array $deliveryCountries = [];

    // DL4: Deutschland mit eigenem Satz (19 %); sonst kennt nur AT einen.
    private bool $germanRate = false;

    private ShippingCountryCoverage|null $coverage = null;

    // Block GARAN: null — kein Label, wie bisher.
    private DurabilityLabelImages|null $durability = null;

    // Block H: die Warenkorbregeln, wo ein Test sie braucht.
    private \Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts|null $discounts = null;

    /**
     * Gemockt, nicht echt: Was der Kaufklick AUSLÖST, prüft OrderPlacerTest
     * gegen eine Verbindung, die Transaktionen mitschreibt. Hier geht es um
     * die vier Prüfungen davor und darum, wohin die Antwort führt.
     */
    private PurchaseHandlerInterface&MockObject $purchaseHandler;

    private ConfirmationSpy $confirmations;

    private PaymentStarter&MockObject $payments;

    private LoggerInterface&MockObject $logger;

    /** @var list<Cookie> the cookies the last post() answered with */
    private array $responseCookies = [];

    protected function setUp(): void
    {
        $this->confirmations = new ConfirmationSpy();
        $this->payments = $this->createMock(PaymentStarter::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->purchaseHandler = $this->createMock(PurchaseHandlerInterface::class);
        $this->purchaseHandler->method('place')->willReturn(new PlacedOrder(77, str_repeat('a', 64), false, str_repeat('c', 64)));

        $this->cartConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCartTables();
        $this->createMemberTable($this->cartConnection);
        DcaSchema::create($this->cartConnection, 'tl_consho_shop');
        $this->cartConnection->insert('tl_consho_shop', ['id' => self::SHOP, 'fallbackLanguage' => 'de']);
        DcaSchema::create($this->cartConnection, 'tl_consho_product');
        $this->cartConnection->insert('tl_consho_product', ['id' => self::ARTICLE, 'pid' => 0, 'minimumAge' => 0]);

        // E2: CartTitles reads the variant's values.
        foreach (['tl_consho_product_option', 'tl_consho_property_option', 'tl_consho_property_group'] as $table) {
            DcaSchema::create($this->cartConnection, $table);
        }

        $this->vatIdSpy = new VatIdSpy();
        $this->repository = new DbalCartRepository($this->cartConnection);
        $this->cartId = $this->repository->create(CartToken::hash(self::TOKEN), 0, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000);
    }

    public function testDerSchrittKommtAusDerAdresseUndWirdEingefangen(): void
    {
        $request = Request::create('https://example.org/kasse.html?consho_step=2');

        self::assertSame(2, CheckoutController::stepOf($request, 3));

        // Außerhalb des Bereichs wird eingefangen, nicht zurückgewiesen: Ein
        // Checkout, der wegen einer 9 in der Adresse gar nicht erscheint, ist
        // die schlechtere Antwort.
        self::assertSame(3, CheckoutController::stepOf(Request::create('https://example.org/?consho_step=9'), 3));
        self::assertSame(1, CheckoutController::stepOf(Request::create('https://example.org/?consho_step=-4'), 3));
        self::assertSame(1, CheckoutController::stepOf(Request::create('https://example.org/'), 3));
    }

    public function testDerErsteSchrittZeigtDenAdressbaustein(): void
    {
        $data = $this->render();

        self::assertSame(['address'], $data['blocks']);
        self::assertSame(1, $data['step']);
        self::assertSame(3, $data['step_count']);
        self::assertFalse($data['is_ready']);
    }

    /**
     * Abnahme C-10: Die Lieferadresse fragte Anrede und E-Mail ab, und beides
     * ging nirgends hin. Jedes Feld, das sie anbietet, braucht seine Spalte an
     * der Bestellung — gelesen aus der echten DCA, nicht abgeschrieben.
     */
    public function testDieLieferadresseFragtNurWasDieBestellungBehaelt(): void
    {
        $fields = $this->render()['shipping_fields'];

        $GLOBALS['TL_DCA'] = [];
        require __DIR__.'/../../../contao/dca/tl_consho_order.php';
        $columns = array_keys($GLOBALS['TL_DCA']['tl_consho_order']['fields']);
        unset($GLOBALS['TL_DCA']);

        self::assertNotContains('email', $fields);
        self::assertNotContains('salutation', $fields);

        foreach ($fields as $field) {
            self::assertContains('shipping'.ucfirst($field), $columns, $field.' wird abgefragt, aber an der Bestellung nicht gespeichert.');
        }
    }

    public function testEinSchrittZeigtAlleVierBausteine(): void
    {
        self::assertSame(
            ['address', 'shipping', 'payment', 'review'],
            $this->render(steps: 1)['blocks'],
        );
    }

    /**
     * 4.3: Enthält der Warenkorb nichts Versendbares, entfällt der Baustein
     * „Versand" — hier über den leeren Warenkorb, den einzigen Fall, den C
     * schon kennt.
     */
    public function testOhneVersandbaresFaelltDerVersandbausteinWeg(): void
    {
        $this->repository->removeItem($this->cartId, $this->itemId(), 2_000);

        self::assertSame(['address', 'payment', 'review'], $this->render(steps: 1)['blocks']);
    }

    public function testDieAntwortIstPrivatUndWirdNichtGespeichert(): void
    {
        // Diese Seite zeigt die Adresse eines Besuchers. Ein geteilter Cache
        // darf sie nicht halten, auch nicht für eine Sekunde.
        $response = $this->respond();

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testEineAbgeschickteAdresseLandetImWarenkorb(): void
    {
        $this->post([
            'consho_checkout_block' => 'address',
            'billing_lastname' => 'Muster',
            'billing_street' => 'Hauptstr. 1',
            'billing_postal' => '1100',
            'billing_city' => 'Wien',
            'billing_country' => 'at',
            'billing_email' => 'Eva@Example.ORG',
        ]);

        $data = $this->repository->checkoutDataOf($this->cartId);

        self::assertSame('Muster', $data->billing->lastname);
        self::assertSame('AT', $data->billing->country, 'Das Land wird in Großbuchstaben normalisiert.');
        self::assertSame('eva@example.org', $data->billing->email, 'AddressNormalizer war beteiligt.');
    }

    /**
     * 🔴 Der einzige Nebeneffekt im ganzen Checkout — und er darf nur beim
     * Abschicken passieren, nie beim Anzeigen. Liefe er im Rechenschritt,
     * kostete jeder Seitenaufruf eine Anfrage nach Brüssel.
     */
    public function testEineUidWirdBeimAbschickenGeprueftUndNichtBeimAnzeigen(): void
    {
        $this->render();

        self::assertSame([], $this->vatIdSpy->seen, 'Das Anzeigen fragt VIES nicht.');

        $this->post([
            'consho_checkout_block' => 'address',
            'billing_lastname' => 'Muster',
            'billing_street' => 'Hauptstr. 1',
            'billing_postal' => '1100',
            'billing_city' => 'Wien',
            'billing_country' => 'AT',
            'billing_email' => 'eva@example.org',
            'consho_vat_id' => 'ATU 123 456 78',
        ]);

        self::assertSame(['ATU12345678'], $this->vatIdSpy->seen, 'Leerzeichen vom Briefkopf fallen weg.');
        self::assertSame('ATU12345678', $this->repository->checkoutDataOf($this->cartId)->vatId);
    }

    public function testEineUnveraenderteUidWirdNichtNochEinmalGeprueft(): void
    {
        // Wer die Adresse zweimal abschickt, soll VIES nicht zweimal
        // beschäftigen — das Rate-Limit dort ist echt.
        $fields = [
            'consho_checkout_block' => 'address',
            'billing_lastname' => 'Muster',
            'billing_street' => 'Hauptstr. 1',
            'billing_postal' => '1100',
            'billing_city' => 'Wien',
            'billing_country' => 'AT',
            'billing_email' => 'eva@example.org',
            'consho_vat_id' => 'ATU12345678',
        ];

        $this->post($fields);
        $this->post($fields);

        self::assertSame(['ATU12345678'], $this->vatIdSpy->seen);
    }

    public function testErneutPruefenFragtNochEinmal(): void
    {
        // C15: der Knopf für ein nicht erreichbares VIES.
        $fields = [
            'consho_checkout_block' => 'address',
            'billing_lastname' => 'Muster',
            'billing_street' => 'Hauptstr. 1',
            'billing_postal' => '1100',
            'billing_city' => 'Wien',
            'billing_country' => 'AT',
            'billing_email' => 'eva@example.org',
            'consho_vat_id' => 'ATU12345678',
        ];

        $this->post($fields);
        $this->post([...$fields, 'consho_recheck_vat' => '1']);

        self::assertSame(['ATU12345678', 'ATU12345678'], $this->vatIdSpy->seen);
    }

    public function testEineUnvollstaendigeAdresseFuehrtZumAdressschrittZurueck(): void
    {
        // C2 und C13: nie still weiter, immer zurück zum Baustein.
        $target = $this->post(['consho_checkout_block' => 'address', 'billing_lastname' => 'Muster'], step: 1);

        self::assertStringEndsWith('consho_step=1', $target);
    }

    public function testEineVollstaendigeAdresseFuehrtZumNaechstenSchritt(): void
    {
        $target = $this->post([
            'consho_checkout_block' => 'address',
            'billing_lastname' => 'Muster',
            'billing_street' => 'Hauptstr. 1',
            'billing_postal' => '1100',
            'billing_city' => 'Wien',
            'billing_country' => 'AT',
            'billing_email' => 'eva@example.org',
        ], step: 1);

        self::assertStringEndsWith('consho_step=2', $target);
    }

    public function testEineGewaehlteVersandartLandetImWarenkorb(): void
    {
        $this->post(['consho_checkout_block' => 'shipping', 'consho_shipping_method' => '4']);

        self::assertSame(4, $this->repository->checkoutDataOf($this->cartId)->shippingMethod);
    }

    public function testEineGewaehlteZahlungsartLandetImWarenkorb(): void
    {
        $this->post(['consho_checkout_block' => 'payment', 'consho_payment_method' => '9']);

        self::assertSame(9, $this->repository->checkoutDataOf($this->cartId)->paymentMethod);
    }

    /**
     * Ein POST ohne den Namen dieses Elements ist ein anderes Formular auf
     * derselben Seite. Es darf hier weder etwas speichern noch eine
     * Weiterleitung auslösen — dieselbe Regel wie im Warenkorb-Element.
     */
    public function testEinFremdesFormularWirdNichtBeantwortet(): void
    {
        $this->repository->saveCheckoutData($this->cartId, (new CheckoutData())->withPaymentMethod(5), 1_500);

        $request = Request::create('https://example.org/kasse.html', 'POST', ['name' => 'Kontaktformular'], [CartToken::NAME => self::TOKEN]);
        $request->attributes->set('pageModel', $this->page());

        $this->controller()->respond($this->template(), $this->model(3), $request);

        self::assertSame(5, $this->repository->checkoutDataOf($this->cartId)->paymentMethod);
    }

    /**
     * 11.2: Eine Warnung sperrt den Checkout dieses Shops — und zwar BEVOR der
     * Besucher eine Adresse tippt, nicht erst beim Kaufklick.
     */
    public function testEineSperrendeWarnungHaeltDenCheckoutAn(): void
    {
        $this->blockers = [new StaticIssueSource([new ConfigurationIssue(ConfigurationFinding::PaymentMethodMissing)])];

        $data = $this->render();

        self::assertSame(['paymentMethodMissing'], $data['blockers']);
        self::assertArrayNotHasKey('blocks', $data, 'Es wird gar nichts gerechnet.');
    }

    public function testEinHinweisSperrtNicht(): void
    {
        // Ein Shop, den eine Notiz über eine doppelte GTIN anhält, erzieht
        // seinen Betreiber dazu, Notizen zu übersehen — und dann auch die
        // Warnungen.
        $this->blockers = [new StaticIssueSource([new ConfigurationIssue(ConfigurationFinding::DuplicateGtin)])];

        self::assertSame(['address'], $this->render()['blocks']);
    }

    /**
     * Nicht jede Warnung sperrt: Eine abgedriftete Projektion ist ernst und
     * hat mit dem Bestellen nichts zu tun — `consho:price:rebuild` richtet
     * sie, während der Shop weiterverkauft.
     */
    public function testEineWarnungAusserhalbDerListeSperrtNicht(): void
    {
        $this->blockers = [new StaticIssueSource([new ConfigurationIssue(ConfigurationFinding::ListPriceDeviation)])];

        self::assertSame(['address'], $this->render()['blocks']);
    }

    /**
     * 🔴 C14, und im Review vom 2026-09-22 als blosses Template-Flag
     * aufgefallen: Der Hinweis stand da, das Adressformular nahm daneben
     * weiter Eingaben an. „Ist die Gastbestellung aus, bleibt nur das
     * Anmelden" ist eine Sperre.
     */
    public function testOhneGastbestellungWirdNichtsAngeboten(): void
    {
        $this->guestCheckout = false;

        $data = $this->render();

        self::assertTrue($data['login_required']);
        self::assertArrayNotHasKey('blocks', $data, 'Es wird gar nichts gerechnet.');
    }

    public function testOhneGastbestellungSpeichertAuchKeinPostEtwas(): void
    {
        $this->guestCheckout = false;

        $this->post([
            'consho_checkout_block' => 'payment',
            'consho_payment_method' => '9',
        ]);

        self::assertSame(0, $this->repository->checkoutDataOf($this->cartId)->paymentMethod);
    }

    public function testDieLaenderKommenAlsAuswahlUndNichtAlsFreitext(): void
    {
        // „Österreich" statt „AT" hätte keinen Steuersatz und damit keinen
        // Preis — der Besucher läse eine Meldung über seinen Warenkorb.
        $countries = $this->render()['countries'];

        self::assertArrayHasKey('AT', $countries);
        self::assertArrayHasKey('DE', $countries);
    }

    /**
     * E-5, V3 (Spec A 707): Von den Lieferländern nur die, in die eine
     * Versandart liefert. Die Schweiz ist angehakt, aber keine Versandart
     * fährt hin — sie fehlt, und die Startseite sagt es
     * (ShippingCountryCoverageTest).
     */
    public function testDieAuswahlZeigtNurLaenderMitVersandart(): void
    {
        $this->deliveryCountries = ['AT', 'DE', 'CH'];

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_shop');
        DcaSchema::create($connection, 'tl_consho_shipping_method');
        $connection->insert('tl_consho_shop', ['id' => self::SHOP, 'shippingMethods' => serialize(['1'])]);
        $connection->insert('tl_consho_shipping_method', ['id' => 1, 'published' => 1, 'type' => 'flat_rate', 'taxClass' => 1, 'countries' => serialize(['AT', 'DE'])]);
        $this->coverage = new ShippingCountryCoverage($connection);

        $view = $this->render();

        self::assertSame(['AT', 'DE'], array_keys($view['countries']));

        // Fable-Review E, S2: Die Rechnungsadresse bleibt bei den Lieferländern
        // des Shops — geprüft wird das LIEFERland, und eine Schweizer Rechnung
        // an ein österreichisches Paket ist ein gewöhnlicher Fall.
        self::assertSame(['AT', 'DE', 'CH'], array_keys($view['billing_countries']));
    }

    /**
     * Michael, 2026-09-28: Beträge an den Optionen, „wenn es einen gibt".
     * Das Paket kostet 5,00 netto, also 6,00 brutto; die Vorkasse nichts —
     * sie bekommt keinen Betrag, kein „0,00 €".
     */
    public function testDieOptionenTragenIhrenBetragWennEsEinenGibt(): void
    {
        $amounts = $this->render()['option_amounts'];

        self::assertSame([4], array_keys($amounts['shipping']));
        self::assertStringContainsString('6,00', $amounts['shipping'][4]);
        self::assertSame([], $amounts['payment']);
    }

    public function testDieAuswahlWirdAufDieLieferlaenderDesShopsGeschnitten(): void
    {
        // Ein Land anzubieten, in das der Shop nicht liefert, führt den
        // Besucher durch das halbe Formular bis zu einer Ablehnung.
        $this->deliveryCountries = ['AT'];

        $countries = $this->render()['countries'];

        self::assertSame(['AT'], array_keys($countries));
    }

    /**
     * Abo-2, P12 (A10, F9): Ein Abo braucht ein Kundenkonto — auch in einem
     * Shop, der Gastbestellungen erlaubt. Dieselbe Sperre wie C14, nicht nur
     * ein Hinweis.
     */
    public function testEinAboVerlangtDieAnmeldungAuchMitGastbestellung(): void
    {
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000, 5);

        $data = $this->render();

        self::assertTrue($data['login_required']);
        self::assertArrayNotHasKey('blocks', $data);
        // Abnahme auf c5: „Dieser Shop verlangt ein Konto" stimmte nicht — der
        // Shop erlaubt Gäste, das Abo verlangt es.
        self::assertSame('subscription', $data['login_reason']);
    }

    public function testOhneGastbestellungIstDerGrundDerShop(): void
    {
        $this->guestCheckout = false;

        self::assertSame('shop', $this->render()['login_reason']);
    }


    public function testEinMitgliedKauftDasAbo(): void
    {
        $this->memberId = 5;
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000, 5);

        self::assertArrayNotHasKey('login_required', $this->render());
    }

    public function testEineVerlaengerungGehtDurchDieKasse(): void
    {
        // Abo-7b (P70): verlängerbar — die Kasse läuft, die Zeile nennt das Abo.
        $this->memberId = 5;
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addRenewal($this->cartId, self::ARTICLE, '1.0000', 5, 31, 1_000);

        $data = $this->render();

        self::assertArrayNotHasKey('renewal_refused', $data);
        self::assertSame(['address'], $data['blocks']);
    }

    public function testEineNichtMehrMoeglicheVerlaengerungHaeltDieKasseAuf(): void
    {
        // Inzwischen anderswo verlängert oder überfällig: gesagt, bevor eine
        // Adresse getippt wird; die gewöhnliche Zeile davor ändert nichts.
        $this->memberId = 5;
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000);
        $this->repository->addRenewal($this->cartId, self::ARTICLE, '1.0000', 5, 32, 1_000);

        $data = $this->render();

        self::assertSame('WEB-00086-1', $data['renewal_refused'] ?? null);
        self::assertArrayNotHasKey('blocks', $data);
    }

    public function testEinAngemeldetesMitgliedDarfAuchOhneGastbestellung(): void
    {
        // Die Gegenrichtung zur Sperre: `guestCheckout` verbietet Gästen den
        // Checkout, nicht Mitgliedern.
        $this->guestCheckout = false;
        $this->memberId = 5;
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000);

        $data = $this->render();

        self::assertArrayNotHasKey('login_required', $data);
        self::assertSame(['address'], $data['blocks']);
    }

    // ── Aufbau ───────────────────────────────────────────────────────────

    private function itemId(): int
    {
        return (int) $this->cartConnection->fetchOne('SELECT id FROM tl_consho_cart_item WHERE pid = ?', [$this->cartId]);
    }

    // ── Der Kaufklick (C-6, 7.3 Schritt 1) ──────────────────────────────────

    /**
     * Der Regelfall: Alles stimmt, die Prüfsumme passt — und seit C-7 führt
     * der Klick auf die Bestellseite, mit dem Ansichts-Token in der Adresse.
     *
     * Das Token steht im Klartext nur hier und in der Weiterleitung; in der
     * Datenbank liegt sein Hash, und aus dem ist es nicht zurückzurechnen.
     */
    public function testEinVollstaendigerKaufklickBestelltUndFuehrtZurBestellseite(): void
    {
        $this->kaufbereit();

        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
        ], step: 3);

        self::assertSame('https://example.org/bestellung.html?consho_order='.str_repeat('a', 64), $target);

        // C-9: die Bestätigung an den Startpunkt der Seite — seit C-10 mit dem
        // Token der MAIL, nicht dem der Umleitung: Nur ein Klick über den Link
        // aus der Mail belegt die Adresse (Abnahme 2026-09-25).
        self::assertSame([[77, self::ROOT, 'https://example.org/bestellung.html?consho_order='.str_repeat('c', 64)]], $this->confirmations->calls);
    }

    /**
     * C-11: Nach Commit und Mail startet die Zahlung; ein Anbieter mit
     * Weiterleitung bekommt den Käufer, ein Fehlschlag landet mit Hinweis auf
     * der Bestellseite.
     */
    public function testNachDemKaufFuehrtEineOnlineZahlungZumAnbieter(): void
    {
        $this->payments->expects(self::once())->method('start')->with(77)->willReturn(PaymentStart::redirect('https://anbieter.test/pay'));
        $this->kaufbereit();

        $target = $this->post(['consho_checkout_block' => 'review', 'consho_fingerprint' => $this->fingerprint()], step: 3);

        self::assertSame('https://anbieter.test/pay', $target);
        self::assertCount(1, $this->confirmations->calls, 'die Mail geht vorher hinaus');
    }

    /**
     * C-12 (Michael, 2026-09-26: „ja, gleich umsetzen"): Nach dem Kauf geht
     * der Warenkorb-Cookie — mit ihm bekam der Käufer 30 Tage lang keine
     * Seite aus dem Cache (Contao sendet `Vary: Cookie`).
     */
    public function testNachDemKaufWirdDerWarenkorbCookieGeloescht(): void
    {
        $this->purchaseHandler = $this->createMock(PurchaseHandlerInterface::class);
        $this->purchaseHandler->method('place')->willReturnCallback(function (): PlacedOrder {
            // Was die Kauftransaktion tut: der Warenkorb verliert seinen Hash.
            $this->cartConnection->update('tl_consho_cart', ['tokenHash' => null, 'state' => 'converted'], ['id' => $this->cartId]);

            return new PlacedOrder(77, str_repeat('a', 64), false, str_repeat('c', 64));
        });
        $this->kaufbereit();

        $this->post(['consho_checkout_block' => 'review', 'consho_fingerprint' => $this->fingerprint()], step: 3);

        self::assertCount(1, $this->responseCookies);
        self::assertSame(CartToken::NAME, $this->responseCookies[0]->getName());
        self::assertTrue($this->responseCookies[0]->isCleared());
    }

    public function testEinAbgelehnterKaufLaesstDenCookieStehen(): void
    {
        $this->purchaseHandler = $this->createMock(PurchaseHandlerInterface::class);
        $this->purchaseHandler->method('place')->willThrowException(TotalDisagreesWithCheckout::between('1', '2'));
        $this->kaufbereit();

        $this->post(['consho_checkout_block' => 'review', 'consho_fingerprint' => $this->fingerprint()], step: 3);

        self::assertSame([], $this->responseCookies, 'der Warenkorb ist noch da');
    }

    /**
     * Seit C-3b trägt ein Cookie einen Warenkorb je Shop. Ein Kauf im einen
     * darf den offenen im anderen nicht verwaisen lassen.
     */
    public function testEinOffenerWarenkorbInEinemAnderenShopHaeltDenCookie(): void
    {
        $this->repository->create(CartToken::hash(self::TOKEN), 0, self::SHOP + 1, 1_000);
        $this->purchaseHandler = $this->createMock(PurchaseHandlerInterface::class);
        $this->purchaseHandler->method('place')->willReturnCallback(function (): PlacedOrder {
            $this->cartConnection->update('tl_consho_cart', ['tokenHash' => null, 'state' => 'converted'], ['id' => $this->cartId]);

            return new PlacedOrder(77, str_repeat('a', 64), false, str_repeat('c', 64));
        });
        $this->kaufbereit();

        $this->post(['consho_checkout_block' => 'review', 'consho_fingerprint' => $this->fingerprint()], step: 3);

        self::assertSame([], $this->responseCookies);
    }

    /**
     * C-12: Ein abgelehnter Kauf nennt dem Käufer nichts Genaues — der Grund
     * gehört ins Log. Bis 2026-09-26 stand das nur im Kommentar.
     */
    public function testEinAbgelehnterKaufLandetImLog(): void
    {
        $this->purchaseHandler = $this->createMock(PurchaseHandlerInterface::class);
        $this->purchaseHandler->method('place')->willThrowException(TotalDisagreesWithCheckout::between('13.69', '12.17'));
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('TotalDisagreesWithCheckout'));
        $this->payments->expects(self::never())->method('start');
        $this->kaufbereit();

        $target = $this->post(['consho_checkout_block' => 'review', 'consho_fingerprint' => $this->fingerprint()], step: 3);

        self::assertStringEndsWith('consho_purchase=couldNotPlace', $target);
        self::assertSame([], $this->confirmations->calls);
    }

    public function testScheitertDerStartZeigtDieBestellseiteDenHinweis(): void
    {
        $this->payments->method('start')->willReturn(PaymentStart::failed('down'));
        $this->kaufbereit();

        $target = $this->post(['consho_checkout_block' => 'review', 'consho_fingerprint' => $this->fingerprint()], step: 3);

        self::assertSame('https://example.org/bestellung.html?consho_order='.str_repeat('a', 64).'&consho_notice=paymentFailed', $target);
    }

    /**
     * Der frühe Doppelklick (7.3 Schritt 3) findet die Bestellung schon
     * angelegt. Sein Käufer hat die Mail der ersten Anfrage — eine zweite
     * Bestätigung derselben Bestellung wäre falsch. Und eine zweite Zahlung
     * erst recht (C-11).
     */
    public function testDerDoppelklickSendetKeineZweiteBestaetigung(): void
    {
        $this->payments->expects(self::never())->method('start');
        $this->purchaseHandler = $this->createMock(PurchaseHandlerInterface::class);
        $this->purchaseHandler->method('place')->willReturn(new PlacedOrder(77, str_repeat('b', 64), true));
        $this->kaufbereit();

        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
        ], step: 3);

        self::assertSame('https://example.org/bestellung.html?consho_order='.str_repeat('b', 64), $target);
        self::assertSame([], $this->confirmations->calls);
    }

    /**
     * 🔴 F2 des Reviews vom 2026-09-23: **der späte Doppelklick.**
     *
     * Die zweite Anfrage, die noch vor dem Commit an der Zeilensperre hängt,
     * fängt `OrderPlacer` ab — sie findet `converted` und bekommt dieselbe
     * Bestellung. Wer erst danach ankommt (zurück im Browser, noch einmal
     * abgeschickt), findet gar keinen Warenkorb mehr: `markConverted()` setzt
     * `tokenHash` auf NULL, `CartResolver` sucht nur nach `open`.
     *
     * Vorher fiel diese Anfrage durch alle Zweige und die Kasse rendete
     * „Ihr Warenkorb ist leer" — die falsche Auskunft für jemanden, der gerade
     * gekauft hat.
     */
    public function testEinKaufklickOhneWarenkorbSagtDassBereitsBestelltWurde(): void
    {
        $this->purchaseHandler->expects(self::never())->method('place');

        // Ohne Korb-Cookie findet CartResolver nichts — genau die Lage nach
        // markConverted(), nur ohne den Umweg über eine zweite Anfrage.
        $request = Request::create(
            'https://example.org/kasse.html?consho_step=3',
            'POST',
            ['consho_checkout_block' => 'review', 'consho_fingerprint' => $this->fingerprint()],
        );
        $request->attributes->set('pageModel', $this->page());

        try {
            $this->controller()->respond($this->template(), $this->model(3), $request);
            self::fail('Der Kaufklick ohne Warenkorb muss weiterleiten.');
        } catch (RedirectResponseException $exception) {
            self::assertSame(
                'https://example.org/kasse.html?consho_ordered=1',
                $exception->getResponse()->headers->get('Location'),
            );
        }
    }

    /**
     * 🔴 **Die Zusicherung, auf der die Adressvorbelegung steht.**
     *
     * Vorbelegt wird in `stateOf()`, und das bedient sowohl das Rendern als
     * auch den Kaufklick. Also rechnet der Klick genau das nach, was im
     * Formular stand — die Prüfsumme geht auf, **ohne dass auf ein GET
     * geschrieben wird**. C-6 hat den Rechenschritt eigens nebenwirkungsfrei
     * gebaut; würde hier gespeichert, wäre das dahin.
     *
     * Der Kunde tippt in diesem Test **nichts** in die Adresse: Er wählt
     * Versand und Zahlung und kauft. Ginge die Prüfsumme nicht auf, käme
     * `consho_purchase=checksumMismatch` statt der Bestellseite.
     */
    public function testMitVorbelegterAdresseGehtDiePruefsummeAuf(): void
    {
        $this->memberId = 5;
        $this->insertMember($this->cartConnection, 5, [
            'firstname' => 'Eva',
            'lastname' => 'Muster',
            'street' => 'Hauptstr. 1',
            'postal' => '1100',
            'city' => 'Wien',
            'country' => 'AT',
            'email' => 'eva@example.org',
        ]);

        // Der Warenkorb hängt am Mitglied, nicht am Cookie — CartResolver
        // fragt für ein angemeldetes Mitglied `findForMember()`.
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000);

        // Nur Versand und Zahlung — die Adresse kommt aus dem Konto.
        $this->post(['consho_checkout_block' => 'shipping', 'consho_shipping_method' => '4'], step: 2);
        $this->post(['consho_checkout_block' => 'payment', 'consho_payment_method' => '9'], step: 2);

        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
        ], step: 3);

        self::assertSame('https://example.org/bestellung.html?consho_order='.str_repeat('a', 64), $target);
    }

    /**
     * 🔴 **Die Prüfsumme geht auf und die Adresse ist trotzdem weg** — der
     * Blocker aus dem Fremdmodell-Review vom 2026-09-23.
     *
     * Mit AGB-Pflicht las `purchase()` die Checkout-Daten noch einmal **aus
     * der Warenkorbzeile**. Bis zur Vorbelegung war das dasselbe wie
     * `$state->data`; seither nicht mehr, denn die Vorbelegung geschieht in
     * `stateOf()` **im Speicher** und auf einem GET wird nichts geschrieben.
     * Die Bestellung bekam die leere Adresse, die der Kunde nie gesehen hat.
     *
     * ⚠️ **Warum drei grüne Tests daran vorbeigingen** — beides Regel 24:
     * Alle drei Vorbelegungs-Tests liefen mit `termsRequired = false`, also
     * genau an dem Zweig vorbei, der den Zustand ersetzt. Und der Mock des
     * Kaufabwicklers nahm jedes Argument an, ohne es anzusehen: Der Test
     * prüfte die Weiterleitung, nicht das Bestellte. Deshalb sieht dieser
     * hier in `place()` hinein.
     *
     * Die Abnahme auf c5 konnte es auch nicht sehen: Dort steht
     * `termsRequired` bei beiden Testshops auf 0.
     */
    public function testMitAgbPflichtKommtDieVorbelegteAdresseAuchAmKaufAn(): void
    {
        $this->termsRequired = true;
        $this->memberId = 5;
        $this->insertMember($this->cartConnection, 5, [
            'firstname' => 'Eva',
            'lastname' => 'Muster',
            'street' => 'Hauptstr. 1',
            'postal' => '1100',
            'city' => 'Wien',
            'country' => 'AT',
            'email' => 'eva@example.org',
        ]);

        $übergeben = null;
        $this->purchaseHandler = $this->createMock(PurchaseHandlerInterface::class);
        $this->purchaseHandler
            ->method('place')
            ->willReturnCallback(
                static function (...$args) use (&$übergeben): PlacedOrder {
                    $übergeben = $args[8];

                    return new PlacedOrder(77, str_repeat('a', 64));
                },
            )
        ;

        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000);

        $this->post(['consho_checkout_block' => 'shipping', 'consho_shipping_method' => '4'], step: 2);
        $this->post(['consho_checkout_block' => 'payment', 'consho_payment_method' => '9'], step: 2);

        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
            'consho_terms' => '1',
        ], step: 3);

        self::assertSame('https://example.org/bestellung.html?consho_order='.str_repeat('a', 64), $target);

        self::assertInstanceOf(CheckoutState::class, $übergeben);
        self::assertSame('Muster', $übergeben->data->billing->lastname, 'Die Bestellung bekam eine leere Rechnungsadresse.');
        self::assertSame('Hauptstr. 1', $übergeben->data->billing->street);
        self::assertSame('AT', $übergeben->data->billing->country);

        // Und der Haken ist trotzdem gesetzt — es geht nicht darum, den Zweig
        // auszubauen, sondern darum, aus welchem Zustand er weiterrechnet.
        self::assertGreaterThan(0, $übergeben->data->termsAcceptedAt);

        // Mit dem Kauf wird die Vorbelegung zur Adresse des Kunden: Ab hier
        // steht sie auch in der Zeile, nicht nur im Speicher.
        self::assertSame('Muster', $this->repository->checkoutDataOf($this->cartId)->billing->lastname);
    }

    public function testDieVorbelegteAdresseStehtImFormular(): void
    {
        $this->memberId = 5;
        $this->insertMember($this->cartConnection, 5, [
            'lastname' => 'Muster',
            'street' => 'Hauptstr. 1',
            'postal' => '1100',
            'city' => 'Wien',
            'country' => 'AT',
            'email' => 'eva@example.org',
        ]);

        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000);

        $checkout = $this->render()['checkout'];

        self::assertInstanceOf(CheckoutData::class, $checkout);
        self::assertSame('Muster', $checkout->billing->lastname);
        self::assertSame('AT', $checkout->billing->country);
    }

    /**
     * Ein Gast bekommt nichts — auch dann nicht, wenn zufällig ein Mitglied
     * mit derselben Nummer existiert (Michael, 2026-09-23: ein Cookie belegt
     * nicht, wer davorsitzt).
     */
    public function testEinGastBekommtKeineFremdeAdresse(): void
    {
        $this->insertMember($this->cartConnection, 5, ['lastname' => 'Muster', 'city' => 'Wien']);

        $checkout = $this->render()['checkout'];

        self::assertInstanceOf(CheckoutData::class, $checkout);
        self::assertTrue($checkout->billing->isEmpty());
    }

    /**
     * Und die Weiterleitung von oben kommt als Fahne im Template an — an der
     * Stelle, an der sonst „Ihr Warenkorb ist leer" steht.
     */
    public function testDerHinweisAufDieBestellungErreichtDasTemplate(): void
    {
        $template = $this->template();
        $request = Request::create('https://example.org/kasse.html?consho_ordered=1');
        $request->attributes->set('pageModel', $this->page());

        $this->controller()->respond($template, $this->model(3), $request);

        self::assertTrue($template->getData()['already_ordered']);
    }

    /**
     * 🔴 Ohne Bestellseite am Startpunkt entsteht KEINE Bestellung.
     *
     * CheckoutReadiness sperrt so einen Shop schon vor dem Rendern, das hier
     * ist der Gurt zu diesem Hosenträger. Der Fall, den es zu vermeiden gilt,
     * ist der andere: bestellen und dann nichts vorzuweisen haben.
     */
    public function testOhneBestellseiteWirdNichtBestellt(): void
    {
        $this->orderPage = 0;
        $this->kaufbereit();

        $this->purchaseHandler->expects(self::never())->method('place');

        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
        ], step: 3);

        self::assertStringEndsWith('consho_purchase=noOrderPage', $target);
    }

    /**
     * 🔴 Der Kern von 7.3 Schritt 1: Hat sich zwischen Anzeige und Klick etwas
     * geändert, wird NICHT bestellt — und auch nicht still korrigiert. Wer
     * €13,90 gelesen hat und €15,40 abgebucht bekäme, ist nicht gefragt worden
     * (§ 8 FAGG).
     */
    public function testEineAbweichendePruefsummeBestelltNicht(): void
    {
        $this->kaufbereit();

        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => 'von gestern',
        ], step: 3);

        self::assertStringEndsWith('consho_purchase=checksumMismatch', $target);
    }

    public function testEineFehlendePruefsummeBestelltNicht(): void
    {
        // Ein altes Template, ein Bot, ein abgeschnittener POST: ohne das Feld
        // ist nichts bestätigt worden.
        $this->kaufbereit();

        $target = $this->post(['consho_checkout_block' => 'review'], step: 3);

        self::assertStringEndsWith('consho_purchase=checksumMismatch', $target);
    }

    public function testOhneAgbHaeckchenWirdNichtBestellt(): void
    {
        $this->termsRequired = true;
        $this->kaufbereit();

        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
        ], step: 3);

        self::assertStringEndsWith('consho_purchase=termsNotAccepted', $target);
    }

    public function testMitAgbHaeckchenWirdDerZeitpunktFestgehalten(): void
    {
        $this->termsRequired = true;
        $this->kaufbereit();

        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
            'consho_terms' => '1',
        ], step: 3);

        self::assertStringStartsWith('https://example.org/bestellung.html?consho_order=', $target);
        self::assertGreaterThan(0, $this->repository->checkoutDataOf($this->cartId)->termsAcceptedAt);
    }

    /**
     * F-3, F15/F16: Bei digitalen Positionen fragt der Checkout einen
     * Verbraucher nach der Zustimmung zum vorzeitigen Beginn — freiwillig.
     * Mit Häkchen wird der Zeitpunkt des Klicks festgehalten, ohne wird
     * trotzdem bestellt. Der Kauf erfährt, dass kein Geschäftskunde kauft.
     */
    public function testZustimmungZumVorzeitigenBeginn(): void
    {
        $this->digital = true;
        $übergeben = [];
        $this->purchaseHandler = $this->createMock(PurchaseHandlerInterface::class);
        $this->purchaseHandler->method('place')->willReturnCallback(
            static function (...$args) use (&$übergeben): PlacedOrder {
                $übergeben = $args;

                return new PlacedOrder(77, str_repeat('a', 64));
            },
        );

        $this->kaufbereitDigital();

        self::assertTrue($this->render(step: 3)['digital_consent'], 'die Frage steht auf der Prüfseite');

        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
            'consho_digital_consent' => '1',
        ], step: 3);

        self::assertStringStartsWith('https://example.org/bestellung.html?consho_order=', $target);
        self::assertGreaterThan(0, $übergeben[8]->data->digitalConsentAt);
        self::assertFalse($übergeben[11], 'kein Geschäftskunde');
    }

    public function testOhneZustimmungWirdTrotzdemBestellt(): void
    {
        $this->digital = true;
        $this->kaufbereitDigital();

        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
        ], step: 3);

        self::assertStringStartsWith('https://example.org/bestellung.html?consho_order=', $target, 'freiwillig (F16)');
        self::assertSame(0, $this->repository->checkoutDataOf($this->cartId)->digitalConsentAt);
    }

    /**
     * Körperliche Ware: keine Frage, und ein eingeschleustes Häkchen wird
     * nicht festgehalten — ein Beleg über einen Verzicht, nach dem nie
     * gefragt wurde, wäre keiner.
     */
    public function testOhneDigitalePositionKeineZustimmung(): void
    {
        $this->kaufbereit();

        self::assertFalse($this->render(step: 3)['digital_consent']);

        $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
            'consho_digital_consent' => '1',
        ], step: 3);

        self::assertSame(0, $this->repository->checkoutDataOf($this->cartId)->digitalConsentAt);
    }

    /**
     * Verlangt der Shop kein Häkchen, ist auch nichts festzuhalten: Ein
     * Zeitpunkt ohne Zustimmung wäre ein Beleg über etwas, das nie geschah.
     */
    public function testOhneAgbPflichtWirdKeinZeitpunktGeschrieben(): void
    {
        $this->kaufbereit();

        $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
        ], step: 3);

        self::assertSame(0, $this->repository->checkoutDataOf($this->cartId)->termsAcceptedAt);
    }

    /**
     * Ein Zustand, aus dem gar nicht gekauft werden kann, geht zu dem
     * Baustein zurück, der ihn verursacht hat — nicht in eine Kaufmeldung.
     * Dieselbe Regel wie überall im Checkout (C2, C13).
     */
    public function testEinUnfertigerZustandFuehrtZumBausteinZurueck(): void
    {
        // Keine Adresse, keine Arten: der Kaufklick kommt gar nicht bis zur
        // Prüfsumme.
        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => 'egal',
        ], step: 3);

        self::assertStringEndsWith('consho_step=1', $target);
    }

    /**
     * Review H, P3: die Rabattzeile der Prüfseite aus dem Controller, nicht
     * aus einem handgebauten Array. 50,00 netto in AT = 60,00; 10 %.
     */
    public function testDerRabattStehtAufDerPruefseite(): void
    {
        $this->cartRule([]);

        $line = $this->render(steps: 3, step: 3)['review_discount'];

        self::assertIsArray($line);
        self::assertSame(['Herbstrabatt', 'Herbst', 'applied', ''], [$line['title'], $line['code'], $line['status'], $line['missing']]);
        self::assertStringContainsString('6,00', $line['amount']);
    }

    /**
     * Review H, S2: Ab 61 € — die 60,00 reichen nicht. Die Prüfseite sagt,
     * was fehlt, statt den Rabatt still verschwinden zu lassen.
     */
    public function testDerVerfehlteMindestbestellwertStehtAufDerPruefseite(): void
    {
        $this->cartRule(['minimumOrder' => '61', 'currency' => 'EUR']);

        $line = $this->render(steps: 3, step: 3)['review_discount'];

        self::assertIsArray($line);
        self::assertSame(['', 'minimumOrder'], [$line['title'], $line['status']]);
        self::assertStringContainsString('1,00', $line['missing']);
    }

    /**
     * @param array<string, string> $values
     */
    private function cartRule(array $values): void
    {
        foreach (['tl_consho_cart_rule', 'tl_consho_cart_rule_redemption'] as $table) {
            DcaSchema::create($this->cartConnection, $table);
        }

        $this->cartConnection->insert('tl_consho_cart_rule', ['tstamp' => 1, 'title' => 'Herbstrabatt', 'code' => 'Herbst', 'published' => 1, 'effect' => 'percent', 'percent' => '10', ...$values]);
        $this->discounts = new \Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts($this->cartConnection, new \Webwerkwien\ContaoConshoBundle\Pricing\ProductConditions($this->cartConnection));
        $this->repository->setDiscountCode($this->cartId, 'Herbst', 1_000);
        $this->kaufbereit();
    }

    public function testDieVorlageBekommtPruefsummeUndBeschriftung(): void
    {
        $this->kaufbereit();

        $data = $this->render(steps: 3, step: 3);

        self::assertNotSame('', $data['fingerprint']);
        self::assertSame('CONSHO.checkout.buy.paymentRequired', $data['buy_label']);
        self::assertFalse($data['terms_required']);
    }

    /**
     * Die wesentlichen Merkmale über dem Knopf (§ 8 Abs. 2 FAGG): was, wie
     * viel, wie teuer. Brutto für Gäste und gewöhnliche Mitglieder; für die
     * Nettogruppe netto seit der Abo-Nachlese (P75, Test darunter).
     */
    public function testDieVorlageBekommtDieWesentlichenMerkmale(): void
    {
        $this->kaufbereit();

        $lines = $this->render(steps: 3, step: 3)['review_lines'];

        self::assertNotSame([], $lines);
        self::assertArrayHasKey('title', $lines[0]);
        self::assertArrayHasKey('quantity', $lines[0]);
        self::assertArrayHasKey('amount', $lines[0]);
        self::assertNull($lines[0]['terms'], 'ein Einmalkauf hat keine Bedingungen');
    }

    /**
     * Block GARAN: § 8 Abs. 1 FAGG nennt Z 12a unter dem, was unmittelbar vor
     * dem Knopf steht — das Label an der Position.
     */
    public function testDiePruefseiteZeigtDasGaranLabelAnDerPosition(): void
    {
        $this->kaufbereit();
        $this->durability = $this->createMock(DurabilityLabelImages::class);
        $this->durability->method('of')->willReturnCallback(
            static fn (array $ids): array => \in_array(self::ARTICLE, $ids, true) ? [self::ARTICLE => ['years' => 5, 'src' => 'files/garan.png', 'width' => null, 'height' => null]] : [],
        );

        $lines = $this->render(steps: 3, step: 3)['review_lines'];

        self::assertSame(['years' => 5, 'src' => 'files/garan.png', 'width' => null, 'height' => null], $lines[0]['garan']);
    }

    /**
     * Abo-2 (A10): Die Bedingungen über dem Knopf — dort, wo § 8 FAGG die
     * Laufzeit verlangt. Mit dem Bruttobetrag, der über dem Knopf steht.
     */
    public function testDiePruefseiteNenntDieBedingungenDesAbos(): void
    {
        $this->memberId = 5;
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000, 5);
        $this->kaufbereit();

        $lines = $this->render(steps: 3, step: 3)['review_lines'];

        // 50,00 netto + 20 % = 60,00.
        self::assertSame('Bedingungen 5 zu 60.0000', $lines[0]['terms']);
    }

    /**
     * DL4, Review DL (S1, zweites Review L4): Ein Dienstleistungs-Abo für
     * einen deutschen Kunden — die Prüfseite rechnet den Bedingungstext mit
     * dem Land der Zeile (AT, Shop), nicht mit dem Lieferland (DE), so wie
     * OrderFactory ihn einfriert.
     */
    public function testDieBedingungenEinerDienstleistungRechnenImLandDesShops(): void
    {
        $this->digital = true;
        $this->digitalKind = 'service';
        $this->germanRate = true;
        $this->deliveryCountries = ['AT', 'DE'];
        $this->memberId = 5;
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000, 5);
        $this->kaufbereitDigital('DE');

        $lines = $this->render(steps: 3, step: 3)['review_lines'];

        self::assertSame('Bedingungen 5 zu 60.0000', $lines[0]['terms'], '50,00 netto + 20 % AT, nicht 19 % DE');
    }

    /**
     * Abo-Nachlese, P75 (Review Abo-2, H1): Die Nettogruppe liest auf der
     * Prüfseite netto wie auf Produktseite und im Warenkorb.
     */
    public function testDieNettogruppeLiestDiePruefseiteNetto(): void
    {
        // Derselbe Aufbau wie in testGleicheLaufzeitGiltFuerDieNettogruppe():
        // was PriceAudienceResolver liest, und Gruppe 3 als Nettogruppe.
        $this->cartConnection->executeStatement("CREATE TABLE tl_member_group (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT '')");
        DcaSchema::create($this->cartConnection, 'tl_consho_price');
        DcaSchema::create($this->cartConnection, 'tl_consho_product_shop');
        $this->cartConnection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => self::SHOP]);
        $this->memberId = 5;
        $this->memberGroups = [3];
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000, 5);
        $this->kaufbereit();

        $lines = $this->render(steps: 3, step: 3)['review_lines'];

        self::assertSame('Bedingungen 5 zu 50.0000', $lines[0]['terms']);
        self::assertStringContainsString('50', $lines[0]['amount']);
        self::assertStringNotContainsString('60', $lines[0]['amount']);
        // Review L1: „zzgl. USt." an den Zeilen.
        self::assertTrue($this->render(steps: 3, step: 3)['review_net']);

        // Review H, L2: der Versand auch netto — 5,00, nicht 6,00 —, an der Option wie auf der Prüfseite.
        $data = $this->render(steps: 3, step: 3);
        self::assertStringContainsString('5,00', $data['review_shipping']['amount']);
        self::assertStringContainsString('5,00', $data['option_amounts']['shipping'][4]);
    }

    /**
     * Review H, L2: der Rabatt der Nettogruppe netto wie im Warenkorb — 10 %
     * von 50,00 netto sind −5,00, nicht −6,00.
     */
    public function testDieNettogruppeLiestDenRabattNetto(): void
    {
        $this->cartConnection->executeStatement("CREATE TABLE tl_member_group (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT '')");
        DcaSchema::create($this->cartConnection, 'tl_consho_price');
        DcaSchema::create($this->cartConnection, 'tl_consho_product_shop');
        $this->cartConnection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => self::SHOP]);
        $this->memberId = 5;
        $this->memberGroups = [3];
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        // Einmalkauf: ein Abo zählt für Warenkorbregeln nie (W3).
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000);
        $this->cartRule([]);

        $line = $this->render(steps: 3, step: 3)['review_discount'];

        self::assertIsArray($line);
        self::assertSame('applied', $line['status'], json_encode($line, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('5,00', $line['amount']);
    }

    public function testOhneNettogruppeKeinNettohinweis(): void
    {
        $this->kaufbereit();

        self::assertFalse($this->render(steps: 3, step: 3)['review_net']);
        // Gegenprobe zu L2: brutto.
        self::assertStringContainsString('6,00', $this->render(steps: 3, step: 3)['review_shipping']['amount']);
    }

    /**
     * Abo-2, P14 (A12): Der Kasten nennt die Widerrufsart der digitalen
     * Zeilen — hier eine Dienstleistung.
     */
    public function testDerZustimmungskastenKenntDieWiderrufsart(): void
    {
        $this->digital = true;
        $this->digitalKind = 'service';
        $this->kaufbereitDigital();

        $data = $this->render(step: 3);

        self::assertTrue($data['digital_consent']);
        self::assertSame(['service'], $data['digital_consent_kinds']);
    }

    /**
     * Abo-7b, Review S5 (Michael, 2026-10-01: „Hinweis in der Kasse sollte da
     * sein"): ohne Zustimmung geht die Lizenz erst nach der Frist über — beim
     * Kasten gesagt, nur wenn eine Verlängerung im Korb liegt.
     */
    public function testEineVerlaengerungSagtWasOhneZustimmungGeschieht(): void
    {
        $this->digital = true;
        $this->memberId = 5;
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addRenewal($this->cartId, self::ARTICLE, '1.0000', 5, 31, 1_000);
        $this->kaufbereitDigital();

        $data = $this->render(step: 3);

        self::assertTrue($data['digital_consent']);
        self::assertTrue($data['renewal_consent_hint']);
    }

    public function testOhneVerlaengerungKeinVerlaengerungshinweis(): void
    {
        $this->digital = true;
        $this->kaufbereitDigital();

        $data = $this->render(step: 3);

        self::assertTrue($data['digital_consent']);
        self::assertFalse($data['renewal_consent_hint']);
    }

    public function testOhneZustimmungsfrageKeineWiderrufsarten(): void
    {
        $this->kaufbereit();

        self::assertSame([], $this->render(step: 3)['digital_consent_kinds']);
    }

    /**
     * Die Prüfsumme, die die Vorlage im laufenden Zustand ausgibt — genau die,
     * die ein Besucher abschicken würde.
     */
    private function fingerprint(): string
    {
        return (string) $this->render(steps: 3, step: 3)['fingerprint'];
    }

    /**
     * Adresse, Versandart und Zahlungsart gesetzt: der Zustand, aus dem heraus
     * gekauft werden darf.
     */
    /**
     * Nur digitale Positionen: Der Zustellschritt bietet die elektronische
     * Zustellung (id 0) an, nicht die Versandart 4 (F-2).
     */
    private function kaufbereitDigital(string $country = 'AT'): void
    {
        $this->post([
            'consho_checkout_block' => 'address',
            'billing_lastname' => 'Muster',
            'billing_street' => 'Hauptstr. 1',
            'billing_postal' => '1100',
            'billing_city' => 'Wien',
            'billing_country' => $country,
            'billing_email' => 'eva@example.org',
        ], step: 1);

        $this->post(['consho_checkout_block' => 'shipping', 'consho_shipping_method' => '0'], step: 2);
        $this->post(['consho_checkout_block' => 'payment', 'consho_payment_method' => '9'], step: 2);
    }

    private function kaufbereit(): void
    {
        $this->post([
            'consho_checkout_block' => 'address',
            'billing_lastname' => 'Muster',
            'billing_street' => 'Hauptstr. 1',
            'billing_postal' => '1100',
            'billing_city' => 'Wien',
            'billing_country' => 'AT',
            'billing_email' => 'eva@example.org',
        ], step: 1);

        $this->post(['consho_checkout_block' => 'shipping', 'consho_shipping_method' => '4'], step: 2);
        $this->post(['consho_checkout_block' => 'payment', 'consho_payment_method' => '9'], step: 2);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return string die Adresse, auf die weitergeleitet wird
     */
    private function post(array $fields, int $step = 1): string
    {
        $request = Request::create(
            \sprintf('https://example.org/kasse.html?consho_step=%d', $step),
            'POST',
            $fields,
            [CartToken::NAME => self::TOKEN],
        );
        $request->attributes->set('pageModel', $this->page());

        $this->responseCookies = [];

        try {
            $this->controller()->respond($this->template(), $this->model(3), $request);
        } catch (RedirectResponseException $exception) {
            $this->responseCookies = $exception->getResponse()->headers->getCookies();

            return $exception->getResponse()->headers->get('Location') ?? '';
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function render(int $steps = 3, int $step = 1): array
    {
        $template = $this->template();
        $request = Request::create(
            \sprintf('https://example.org/kasse.html?consho_step=%d', $step),
            'GET',
            [],
            [CartToken::NAME => self::TOKEN],
        );
        $request->attributes->set('pageModel', $this->page());

        $this->controller()->respond($template, $this->model($steps), $request);

        return $template->getData();
    }

    private function respond(): Response
    {
        $request = Request::create('https://example.org/kasse.html', 'GET', [], [CartToken::NAME => self::TOKEN]);
        $request->attributes->set('pageModel', $this->page());

        return $this->controller()->respond($this->template(), $this->model(3), $request);
    }

    private function template(): FragmentTemplate
    {
        return new FragmentTemplate('content_element/consho_checkout', static fn (): Response => new Response());
    }

    private function model(int $steps): ContentModel
    {
        $row = ['id' => 42, 'conshoSteps' => $steps];

        $model = $this->createMock(ContentModel::class);
        $model->method('row')->willReturn($row);
        $model->method('__get')->willReturnCallback(static fn (string $key): mixed => $row[$key] ?? null);

        /*
         * ⚠️ __isset, nicht nur __get — dasselbe wie beim PageModel in
         * ProductListAudienceTest. Der Controller liest `$model->conshoSteps ?? 3`,
         * und `??` fragt bei magischen Eigenschaften ZUERST __isset(); ein
         * Doppel, das dort false sagt, liefert immer die 3. Ohne diese Zeile
         * war der Test für „ein Schritt zeigt alle vier Bausteine" grün gegen
         * drei Schritte.
         */
        $model->method('__isset')->willReturnCallback(static fn (string $key): bool => isset($row[$key]));

        return $model;
    }

    private function urlGenerator(): ContentUrlGenerator
    {
        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturn('https://example.org/bestellung.html');

        return $urls;
    }

    private function page(): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnCallback(
            static fn (string $key): mixed => ['id' => self::PAGE, 'type' => 'regular', 'rootId' => self::ROOT, 'language' => 'de'][$key] ?? null,
        );

        // __isset, nicht nur __get — siehe ProductListAudienceTest.
        $page->method('__isset')->willReturn(true);

        return $page;
    }

    private function controller(): TestableCheckoutController
    {
        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturnCallback(
            static fn (string $key): mixed => ['id' => self::ROOT, 'type' => 'root', 'rootId' => self::ROOT, 'language' => 'de'][$key] ?? null,
        );
        $root->method('__isset')->willReturn(true);
        $root->method('row')->willReturn(['consho_shop' => self::SHOP, 'consho_currency' => 'EUR', 'consho_orderPage' => $this->orderPage]);

        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnCallback(
            fn (string $key): mixed => [
                'id' => self::SHOP,
                'currency' => 'EUR',
                'country' => 'AT',
                'vatId' => 'ATU99999999',
                'guestCheckout' => $this->guestCheckout,
                'deliveryCountries' => [] === $this->deliveryCountries ? null : serialize($this->deliveryCountries),
                'ageVerifiedGroups' => null,
                'termsRequired' => $this->termsRequired,
                'buyButtonLabel' => 'paymentRequired',
            ][$key] ?? null,
        );

        /*
         * ⚠️ __isset, zum dritten Mal an diesem Tag. Der Controller liest
         * `$shop->deliveryCountries ?? null`, und `??` fragt bei magischen
         * Eigenschaften ZUERST __isset(); ein Doppel, das dort false sagt,
         * liefert null — die Länderauswahl blieb ungeschnitten, und der Test
         * dazu war grün gegen das falsche Verhalten.
         */
        $shop->method('__isset')->willReturn(true);

        $pages = [self::PAGE => $this->page(), self::ROOT => $root, self::ORDER_PAGE => $this->page()];

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(
            static fn (string $name, array $args): PageModel|null => \in_array($name, ['findById', 'findByPk'], true) ? ($pages[$args[0]] ?? null) : null,
        );

        $shopAdapter = $this->createMock(Adapter::class);
        $shopAdapter->method('__call')->willReturnCallback(
            static fn (string $name, array $args): ShopModel|null => 'findByPk' === $name && self::SHOP === $args[0] ? $shop : null,
        );

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => PageModel::class === $class ? $pageAdapter : $shopAdapter,
        );

        $controller = new TestableCheckoutController(
            new CartResolver($this->repository, $this->tokenStorage()),
            $this->repository,
            $this->calculator(),
            new ShopResolver($framework),
            new ShopLanguages($this->cartConnection),
            new PriceAudienceResolver($this->tokenStorage(), $this->cartConnection),
            new CartAgeLimit($this->cartConnection),
            new MoneyFormatter(),
            new AddressNormalizer($this->countries(), new PhoneNormalizer()),
            $this->countries(),
            $this->vatIds(),
            new CheckoutReadiness($this->blockers),
            $this->createMock(DbalLegalTextSource::class),
            new CheckoutFingerprint(),
            new ShopPageUrls($framework, new ShopResolver($framework), $this->urlGenerator()),
            new CartTitles($this->cartConnection),
            $this->purchaseHandler,

            // Die echte Klasse gegen dasselbe SQLite, kein Doppel: Was sie tut,
            // hängt an einer Abfrage auf `tl_member`, und genau die soll der
            // Test mitfahren (Punkt 1 der Hausregeln).
            new MemberCheckoutDefaults(
                $this->cartConnection,
                // Derselbe Normalisierer wie für die getippte Adresse oben:
                // Die Vorbelegung war sonst der eine Weg auf die Rechnung,
                // der an ihm vorbeiführt (Review 2026-09-23).
                new AddressNormalizer($this->countries(), new PhoneNormalizer()),
            ),
            $this->confirmations,
            $this->payments,
            $this->logger,
            $this->coverage,
            lineTerms: $this->lineTerms(),
            renewals: $this->renewals(),
            durability: $this->durability,
        );

        $container = new Container();
        $container->set('contao.cache.tag_manager', $this->createMock(CacheTagManager::class));
        $controller->setContainer($container);

        return $controller;
    }

    /**
     * Ein angemeldetes Mitglied, wenn `$this->memberId` gesetzt ist — echter
     * FrontendUser ohne Framework, wie in CartResolverTest.
     */
    private function tokenStorage(): TokenStorageInterface
    {
        $storage = new TokenStorage();

        if (0 === $this->memberId) {
            return $storage;
        }

        $user = (new \ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
        $user->id = $this->memberId;
        $user->username = 'member'.$this->memberId;
        $user->groups = serialize(array_map('strval', $this->memberGroups));

        $storage->setToken(new UsernamePasswordToken($user, 'frontend'));

        return $storage;
    }

    private function calculator(): CheckoutCalculator
    {
        $article = self::ARTICLE;

        $prices = new class($article) implements CartLinePriceSource {
            public function __construct(private readonly int $article)
            {
            }

            public function forCartLine(int $articleId, int $mainProductId, string $currency, string $quantity, int $now, array $customerGroups = [], int $subscriptionRule = 0): CartLinePrice|null
            {
                return $this->article === $articleId ? new CartLinePrice('50.0000', 1) : null;
            }
        };

        $tree = $this->createMock(ArticleTreeInterface::class);
        $tree->method('articlesOf')->willReturnCallback(
            static fn (array $ids): array => array_combine($ids, $ids),
        );

        $shipping = new class implements ShippingMethodRepositoryInterface {
            public function forShop(int $shopId, string $currency, string $language = '', string $fallback = ''): array
            {
                return [new ShippingMethod(4, 'flat_rate', 'Paket', ShippingTaxMode::Fixed, 1, ['net' => '5.0000'])];
            }
        };

        $payment = new class implements PaymentMethodRepositoryInterface {
            public function forShop(int $shopId, string $language = '', string $fallback = ''): array
            {
                return [new PaymentMethod(9, 'prepayment', 'Vorkasse', [])];
            }
        };

        return new CheckoutCalculator(
            new CartPricer($tree, $prices, $this->grossCalculator(), null, $this->articleData()),
            $shipping,
            new ShippingMethodTypes(new ServiceLocator(['flat_rate' => static fn (): FlatRateShipping => new FlatRateShipping()])),
            $payment,
            new PaymentHandlers(new ServiceLocator(['prepayment' => static fn (): PaymentHandlerInterface => new PrepaymentHandler()])),
            new ShippingTaxSplitter(),
            $this->grossCalculator(),
            new AgeGate(),
            $this->articleData(),
            null,
            discounts: $this->discounts,
        );
    }

    /**
     * Der Satz selbst ist in SubscriptionTermsTest geprüft; hier zählt, welche
     * Regel und welcher Betrag hineingehen.
     */
    /**
     * Abo-7b: die Prüfung selbst steht in SubscriptionRenewalTest; hier zählt,
     * wer gefragt wird. Abo 31 ist verlängerbar, Abo 32 nicht mehr.
     */
    private function renewals(): SubscriptionRenewal
    {
        $renewals = $this->createStub(SubscriptionRenewal::class);
        $renewals->method('allowsPurchase')->willReturnCallback(
            fn (int $subscriptionId, int $memberId, int $shopId, int $articleId): bool => 31 === $subscriptionId && $memberId === $this->memberId && self::SHOP === $shopId && self::ARTICLE === $articleId,
        );
        $renewals->method('numbersOf')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key([31 => 'WEB-00084-1', 32 => 'WEB-00086-1'], array_flip($ids)),
        );

        return $renewals;
    }

    private function lineTerms(): SubscriptionLineTerms
    {
        $lineTerms = $this->createMock(SubscriptionLineTerms::class);
        $lineTerms->method('of')->willReturnCallback(
            static fn (int $rule, string $amount): string|null => $rule > 0 ? 'Bedingungen '.$rule.' zu '.$amount : null,
        );

        return $lineTerms;
    }

    private function articleData(): ArticleShippingDataInterface
    {
        $kind = $this->digital ? $this->digitalKind : 'physical';

        return new class($kind) implements ArticleShippingDataInterface {
            public function __construct(private readonly string $kind)
            {
            }

            public function of(array $articleIds): array
            {
                $data = [];

                foreach ($articleIds as $id) {
                    $data[$id] = ['weight' => '0.0000', 'length' => '0.0000', 'width' => '0.0000', 'height' => '0.0000', 'kind' => $this->kind];
                }

                return $data;
            }
        };
    }

    private function grossCalculator(): GrossCalculator
    {
        $rates = new class($this->germanRate) implements TaxRateRepositoryInterface {
            public function __construct(private readonly bool $germanRate)
            {
            }

            public function findRate(int $taxClassId, string $countryCode, int $timestamp): string|null
            {
                return match ($countryCode) {
                    'AT' => '20.0000',
                    'DE' => $this->germanRate ? '19.0000' : null,
                    default => null,
                };
            }

            public function findConfiguredCountries(int $taxClassId, int $timestamp): array
            {
                return ['AT'];
            }

            public function findTaxClassIdsUsedByShop(int $shopId): array
            {
                return [1];
            }

            public function findClassesWithBoundaryIn(int $after, int $until): array
            {
                return [];
            }
        };

        return new GrossCalculator(new TaxResolver($rates, $this->countries()));
    }

    private function countries(): CountryProvider
    {
        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE', 'CH']);
        $countries->method('getCountries')->willReturn(['AT' => 'Österreich', 'DE' => 'Deutschland', 'CH' => 'Schweiz']);

        return new CountryProvider($countries);
    }

    private function vatIds(): VatIdValidatorInterface
    {
        return $this->vatIdSpy;
    }
}

/**
 * Eine Befundquelle mit vorgegebenem Inhalt.
 */
final class StaticIssueSource implements ConfigurationIssueSource
{
    /**
     * @param list<ConfigurationIssue> $issues
     */
    public function __construct(private readonly array $issues)
    {
    }

    public function issues(): array
    {
        return $this->issues;
    }
}

/**
 * Macht getResponse() aufrufbar. Als benannte Klasse, nicht anonym: PHPStan
 * kennt sonst nur den Typ der Elternklasse und hält respond() für undefiniert.
 */
final class TestableCheckoutController extends CheckoutController
{
    public function respond(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        return $this->getResponse($template, $model, $request);
    }
}

/**
 * Merkt sich, welche UIDs geprüft wurden. Eine eigene Klasse, weil eine
 * Referenz in einem beförderten Konstruktor-Argument nicht geht — und weil
 * „wurde VIES gefragt" die Frage dieses Tests ist, nicht ein Nebenaspekt.
 */
final class VatIdSpy implements VatIdValidatorInterface
{
    /** @var list<string> */
    public array $seen = [];

    public function check(string $vatId, string|null $requesterVatId = null): VatIdCheckResult
    {
        $this->seen[] = $vatId;

        return new VatIdCheckResult(VatIdStatus::Valid, 1_000, null, 'WAPI1');
    }
}

final class ConfirmationSpy implements OrderConfirmationInterface
{
    /** @var list<array{int, int, string}> */
    public array $calls = [];

    public function send(int $orderId, int $rootId, string $viewUrl, int $now, bool $copyOperator = true): bool
    {
        $this->calls[] = [$orderId, $rootId, $viewUrl];

        return true;
    }
}

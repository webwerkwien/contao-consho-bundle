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
 * The wiring of the checkout element.
 *
 * ⚠️ Why this test exists: the calculation step is checked on its own, but
 * a test that feeds the calculator directly does not check whether the
 * controller asks it at all — and whether a form arrives where it is meant
 * to go. Exactly this gap was the issue with `basePriceNet`: the error sat
 * in how the parts are put together, and only the finished parts were
 * checked.
 *
 * The cart runs for real over SQLite here; prices, shipping and payment
 * methods are doubles because they change nothing about this question.
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
     * The order page at the start point. Without it the purchase click answers
     * `noOrderPage` — and that is not a test detail but the intent: an order
     * whose confirmation nobody can see does not come into being.
     */
    private const ORDER_PAGE = 456;

    /** Set to 0 means: the start point names no order page. */
    private int $orderPage = self::ORDER_PAGE;

    /**
     * Exactly 43 base64url characters. CartToken::fromRequest() filters for
     * the form generate() produces — a shorter test token fails, and the
     * controller would then never find a cart.
     */
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private DbalCartRepository $repository;
    private int $cartId;

    private VatIdSpy $vatIdSpy;

    /** @var list<ConfigurationIssueSource> */
    private array $blockers = [];

    private bool $guestCheckout = true;

    private bool $termsRequired = false;

    /** F-3: the test's article is digital. */
    private bool $digital = false;

    // DL1: the kind of the digital article — digital or service.
    private string $digitalKind = 'digital';

    // Abo-2: the member's groups.

    /** @var list<int> */
    private array $memberGroups = [];

    private int $memberId = 0;

    /** @var list<string> */
    private array $deliveryCountries = [];

    // DL4: Germany with its own rate (19 %); otherwise only AT knows one.
    private bool $germanRate = false;

    private ShippingCountryCoverage|null $coverage = null;

    // Block GARAN: null — no label, as before.
    private DurabilityLabelImages|null $durability = null;

    // Block H: the cart rules, where a test needs them.
    private \Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts|null $discounts = null;

    /**
     * Mocked, not real: what the purchase click TRIGGERS is checked by
     * OrderPlacerTest against a connection that records transactions. This is
     * about the four checks before it and where the response leads.
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

        // Out of range is caught, not rejected: a checkout that does not appear
        // at all because of a 9 in the address is the worse answer.
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
     * C-10: the delivery address asked for salutation and e-mail, and neither
     * went anywhere. Every field it offers needs its column on the order —
     * read from the real DCA, not copied.
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
     * 4.3: if the cart contains nothing shippable, the "Versand" (shipping)
     * section is dropped — here via the empty cart, the only case that C
     * already knows.
     */
    public function testOhneVersandbaresFaelltDerVersandbausteinWeg(): void
    {
        $this->repository->removeItem($this->cartId, $this->itemId(), 2_000);

        self::assertSame(['address', 'payment', 'review'], $this->render(steps: 1)['blocks']);
    }

    public function testDieAntwortIstPrivatUndWirdNichtGespeichert(): void
    {
        // This page shows a visitor's address. A shared cache must not hold it,
        // not even for a second.
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
     * 🔴 The only side effect in the whole checkout — and it may only happen
     * on submission, never on display. If it ran in the calculation step,
     * every page view would cost a request to Brussels.
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
        // Whoever submits the address twice should not keep VIES busy twice —
        // the rate limit there is real.
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
        // C15: the button for an unreachable VIES.
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
        // C2 and C13: never silently on, always back to the section.
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
     * A POST without this element's name is another form on the same page.
     * It must neither store anything here nor trigger a redirect — the same
     * rule as in the cart element.
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
     * 11.2: a warning blocks this shop's checkout — and BEFORE the visitor
     * types an address, not only at the purchase click.
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
        // A shop that a notice about a duplicate GTIN stops trains its operator
        // to overlook notices — and then the warnings as well.
        $this->blockers = [new StaticIssueSource([new ConfigurationIssue(ConfigurationFinding::DuplicateGtin)])];

        self::assertSame(['address'], $this->render()['blocks']);
    }

    /**
     * Not every warning blocks: a drifted projection is serious and has
     * nothing to do with ordering — `consho:price:rebuild` fixes it while
     * the shop keeps selling.
     */
    public function testEineWarnungAusserhalbDerListeSperrtNicht(): void
    {
        $this->blockers = [new StaticIssueSource([new ConfigurationIssue(ConfigurationFinding::ListPriceDeviation)])];

        self::assertSame(['address'], $this->render()['blocks']);
    }

    /**
     * 🔴 C14, and more than a template flag: the notice was there, while the
     * address form next to it kept accepting input. "If guest ordering is off,
     * only logging in remains" is a lock.
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
        // "Österreich" instead of "AT" would have no tax rate and therefore no
        // price — the visitor would read a message about their cart.
        $countries = $this->render()['countries'];

        self::assertArrayHasKey('AT', $countries);
        self::assertArrayHasKey('DE', $countries);
    }

    /**
     * E-5, V3: of the delivery countries only those a shipping
     * method delivers to. Switzerland is ticked, but no shipping method goes
     * there — it is missing, and the start page says so
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

        // E: the billing address stays with the shop's delivery countries —
        // what is checked is the DELIVERY country, and a Swiss invoice for an
        // Austrian parcel is an ordinary case.
        self::assertSame(['AT', 'DE', 'CH'], array_keys($view['billing_countries']));
    }

    /**
     * Amounts on the options, "where there is one". The parcel costs 5.00
     * net, so 6.00 gross; prepayment nothing — it gets no amount, no
     * "0,00 €".
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
        // Offering a country the shop does not deliver to leads the visitor
        // through half the form to a rejection.
        $this->deliveryCountries = ['AT'];

        $countries = $this->render()['countries'];

        self::assertSame(['AT'], array_keys($countries));
    }

    /**
     * Abo-2, P12 (A10, F9): a subscription needs a customer account — even in
     * a shop that allows guest orders. The same lock as C14, not just a
     * notice.
     */
    public function testEinAboVerlangtDieAnmeldungAuchMitGastbestellung(): void
    {
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000, 5);

        $data = $this->render();

        self::assertTrue($data['login_required']);
        self::assertArrayNotHasKey('blocks', $data);
        // "Dieser Shop verlangt ein Konto" (this shop requires an account) would
        // be wrong — the shop allows guests, the subscription requires it.
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
        // Abo-7b (P70): renewable — the checkout runs, the line names the subscription.
        $this->memberId = 5;
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addRenewal($this->cartId, self::ARTICLE, '1.0000', 5, 31, 1_000);

        $data = $this->render();

        self::assertArrayNotHasKey('renewal_refused', $data);
        self::assertSame(['address'], $data['blocks']);
    }

    public function testEineNichtMehrMoeglicheVerlaengerungHaeltDieKasseAuf(): void
    {
        // Renewed elsewhere in the meantime or overdue: said before an address
        // is typed; the ordinary line before it changes nothing.
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
        // The opposite direction of the lock: `guestCheckout` forbids guests
        // the checkout, not members.
        $this->guestCheckout = false;
        $this->memberId = 5;
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000);

        $data = $this->render();

        self::assertArrayNotHasKey('login_required', $data);
        self::assertSame(['address'], $data['blocks']);
    }

    // ── Setup ────────────────────────────────────────────────────────────

    private function itemId(): int
    {
        return (int) $this->cartConnection->fetchOne('SELECT id FROM tl_consho_cart_item WHERE pid = ?', [$this->cartId]);
    }

    // ── The purchase click (C-6, 7.3 step 1) ────────────────────────────────

    /**
     * The regular case: everything is right, the checksum matches — and since
     * C-7 the click leads to the order page, with the view token in the
     * address.
     *
     * The token is in plain text only here and in the redirect; the database
     * holds its hash, and it cannot be recomputed from that.
     */
    public function testEinVollstaendigerKaufklickBestelltUndFuehrtZurBestellseite(): void
    {
        $this->kaufbereit();

        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
        ], step: 3);

        self::assertSame('https://example.org/bestellung.html?consho_order='.str_repeat('a', 64), $target);

        // C-9: the confirmation to the page's start point — since C-10 with the
        // token of the MAIL, not that of the redirect: only a click via the link
        // from the mail proves the address.
        self::assertSame([[77, self::ROOT, 'https://example.org/bestellung.html?consho_order='.str_repeat('c', 64)]], $this->confirmations->calls);
    }

    /**
     * C-11: after commit and mail the payment starts; a provider with a
     * redirect gets the buyer, a failure lands on the order page with a
     * notice.
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
     * C-12: after the purchase the cart cookie goes — with it the buyer got
     * no page from the cache for 30 days (Contao sends `Vary: Cookie`).
     */
    public function testNachDemKaufWirdDerWarenkorbCookieGeloescht(): void
    {
        $this->purchaseHandler = $this->createMock(PurchaseHandlerInterface::class);
        $this->purchaseHandler->method('place')->willReturnCallback(function (): PlacedOrder {
            // What the purchase transaction does: the cart loses its hash.
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
     * Since C-3b a cookie carries one cart per shop. A purchase in one must
     * not orphan the open one in the other.
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
     * C-12: a rejected purchase tells the buyer nothing specific — the reason
     * belongs in the log.
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
     * The early double click (7.3 step 3) finds the order already created. Its
     * buyer has the mail of the first request — a second confirmation of the
     * same order would be wrong. And a second payment all the more (C-11).
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
     * 🔴 **The late double click.**
     *
     * The second request, which still hangs on the row lock before the
     * commit, is caught by `OrderPlacer` — it finds `converted` and gets the
     * same order. Whoever only arrives afterwards (back in the browser,
     * submitted again) finds no cart at all any more: `markConverted()` sets
     * `tokenHash` to NULL, `CartResolver` only looks for `open`.
     *
     * Before, this request fell through all branches and the checkout rendered
     * "Ihr Warenkorb ist leer" (your cart is empty) — the wrong answer for
     * someone who has just bought.
     */
    public function testEinKaufklickOhneWarenkorbSagtDassBereitsBestelltWurde(): void
    {
        $this->purchaseHandler->expects(self::never())->method('place');

        // Without a cart cookie CartResolver finds nothing — exactly the
        // situation after markConverted(), only without the detour via a second
        // request.
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
     * 🔴 **The assertion the address prefill stands on.**
     *
     * Prefilling happens in `stateOf()`, and that serves both rendering and
     * the purchase click. So the click recalculates exactly what was in the
     * form — the checksum matches **without anything being written on a
     * GET**. C-6 built the calculation step free of side effects on purpose;
     * storing here would undo that.
     *
     * The customer types **nothing** into the address in this test: they
     * choose shipping and payment and buy. If the checksum did not match,
     * `consho_purchase=checksumMismatch` would come instead of the order page.
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

        // The cart hangs off the member, not off the cookie — CartResolver asks
        // `findForMember()` for a logged-in member.
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000);

        // Only shipping and payment — the address comes from the account.
        $this->post(['consho_checkout_block' => 'shipping', 'consho_shipping_method' => '4'], step: 2);
        $this->post(['consho_checkout_block' => 'payment', 'consho_payment_method' => '9'], step: 2);

        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => $this->fingerprint(),
        ], step: 3);

        self::assertSame('https://example.org/bestellung.html?consho_order='.str_repeat('a', 64), $target);
    }

    /**
     * 🔴 **The checksum matches and the address is gone anyway.**
     *
     * With terms required, `purchase()` read the checkout data once more
     * **from the cart row**. Until prefilling existed that was the same as
     * `$state->data`; since then it is not, because prefilling happens in
     * `stateOf()` **in memory** and nothing is written on a GET. The order got
     * the empty address the customer never saw.
     *
     * ⚠️ **Why three green tests missed it** — both times the test copied
     * instead of going through the real place: all three prefill tests ran
     * with `termsRequired = false`, so exactly past the branch that replaces
     * the state. And the mock of the order placer accepted every argument
     * without looking at it: the test checked the redirect, not what was
     * ordered. That is why this one looks into `place()`.
     *
     * A check by hand would not have seen it either where `termsRequired` is
     * 0 in every shop.
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

        // And the checkbox is ticked anyway — the point is not to remove the
        // branch, but from which state it continues calculating.
        self::assertGreaterThan(0, $übergeben->data->termsAcceptedAt);

        // With the purchase the prefill becomes the customer's address: from
        // here on it is in the row too, not only in memory.
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
     * A guest gets nothing — not even if a member with the same number
     * happens to exist (a cookie does not prove who sits in front of it).
     */
    public function testEinGastBekommtKeineFremdeAdresse(): void
    {
        $this->insertMember($this->cartConnection, 5, ['lastname' => 'Muster', 'city' => 'Wien']);

        $checkout = $this->render()['checkout'];

        self::assertInstanceOf(CheckoutData::class, $checkout);
        self::assertTrue($checkout->billing->isEmpty());
    }

    /**
     * And the redirect from above arrives as a flag in the template — at the
     * place where "Ihr Warenkorb ist leer" (your cart is empty) otherwise stands.
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
     * 🔴 Without an order page at the start point NO order is created.
     *
     * CheckoutReadiness already blocks such a shop before rendering; this is
     * the belt to those braces. The case to avoid is the other one: ordering
     * and then having nothing to show for it.
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
     * 🔴 The core of 7.3 step 1: if something changed between display and
     * click, NO order is placed — and nothing is silently corrected either.
     * Whoever read €13.90 and would be charged €15.40 has not been asked
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
        // An old template, a bot, a truncated POST: without the field nothing
        // has been confirmed.
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
     * F-3, F15/F16: with digital lines the checkout asks a consumer for
     * consent to early performance — voluntarily. With the checkbox the time
     * of the click is recorded, without it the order is placed anyway. The
     * purchase learns that no business customer is buying.
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
     * Physical goods: no question, and a smuggled-in checkbox is not
     * recorded — a record of a waiver that was never asked for would be none.
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
     * If the shop requires no checkbox, there is nothing to record either: a
     * point in time without consent would be a record of something that never
     * happened.
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
     * A state from which no purchase is possible at all goes back to the
     * section that caused it — not into a purchase message. The same rule as
     * everywhere in the checkout (C2, C13).
     */
    public function testEinUnfertigerZustandFuehrtZumBausteinZurueck(): void
    {
        // No address, no methods: the purchase click does not even get as far
        // as the checksum.
        $target = $this->post([
            'consho_checkout_block' => 'review',
            'consho_fingerprint' => 'egal',
        ], step: 3);

        self::assertStringEndsWith('consho_step=1', $target);
    }

    /**
     * H: the review page's discount line from the controller, not from a
     * hand-built array. 50.00 net in AT = 60.00; 10 %.
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
     * H: from € 61 — the 60.00 are not enough. The review page says what is
     * missing instead of letting the discount silently disappear.
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
     * The main characteristics above the button (§ 8 (2) FAGG): what, how
     * many, how expensive. Gross for guests and ordinary members; net for the
     * net group since the Abo-Nachlese (P75, test below).
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
     * Block GARAN: § 8 (1) FAGG names Z 12a among what stands directly before
     * the button — the label on the line.
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
     * Abo-2 (A10): the terms above the button — where § 8 FAGG requires the
     * duration. With the gross amount that stands above the button.
     */
    public function testDiePruefseiteNenntDieBedingungenDesAbos(): void
    {
        $this->memberId = 5;
        $this->cartId = $this->repository->create(null, 5, self::SHOP, 1_000);
        $this->repository->addItem($this->cartId, self::ARTICLE, '1.0000', 1_000, 5);
        $this->kaufbereit();

        $lines = $this->render(steps: 3, step: 3)['review_lines'];

        // 50.00 net + 20 % = 60.00.
        self::assertSame('Bedingungen 5 zu 60.0000', $lines[0]['terms']);
    }

    /**
     * DL4: a service subscription for a German customer — the review page
     * calculates the terms text with the country of the line (AT, shop), not
     * with the delivery country (DE), the way OrderFactory freezes it.
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
     * Abo-Nachlese, P75 (Abo-2): the net group reads net on the review page,
     * as on the product page and in the cart.
     */
    public function testDieNettogruppeLiestDiePruefseiteNetto(): void
    {
        // The same setup as in testGleicheLaufzeitGiltFuerDieNettogruppe():
        // what PriceAudienceResolver reads, and group 3 as the net group.
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
        // "zzgl. USt." (plus VAT) on the lines.
        self::assertTrue($this->render(steps: 3, step: 3)['review_net']);

        // H: shipping net as well — 5.00, not 6.00 — on the option as on the review page.
        $data = $this->render(steps: 3, step: 3);
        self::assertStringContainsString('5,00', $data['review_shipping']['amount']);
        self::assertStringContainsString('5,00', $data['option_amounts']['shipping'][4]);
    }

    /**
     * H: the net group's discount net as in the cart — 10 % of 50.00 net is
     * −5.00, not −6.00.
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
        // One-time purchase: a subscription never counts for cart rules (W3).
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
        // Counter-check: gross.
        self::assertStringContainsString('6,00', $this->render(steps: 3, step: 3)['review_shipping']['amount']);
    }

    /**
     * Abo-2, P14 (A12): the box names the withdrawal type of the digital
     * lines — here a service.
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
     * Abo-7b: without consent the licence only passes after the period — said
     * at the box only when a renewal is in the cart.
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
     * The checksum the template outputs in the current state — exactly the
     * one a visitor would submit.
     */
    private function fingerprint(): string
    {
        return (string) $this->render(steps: 3, step: 3)['fingerprint'];
    }

    /**
     * Address, shipping method and payment method set: the state from which
     * purchasing is allowed.
     */
    /**
     * Digital lines only: the delivery step offers electronic delivery
     * (id 0), not shipping method 4 (F-2).
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
     * @return string the address it redirects to
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
         * ⚠️ __isset, not just __get — the same as with the PageModel in
         * ProductListAudienceTest. The controller reads `$model->conshoSteps ?? 3`,
         * and for magic properties `??` asks __isset() FIRST; a double that
         * says false there always returns 3. Without this line the test for
         * "one step shows all four sections" was green against three steps.
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

        // __isset, not just __get — see ProductListAudienceTest.
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
         * ⚠️ __isset, for the third time in this file. The controller reads
         * `$shop->deliveryCountries ?? null`, and for magic properties `??`
         * asks __isset() FIRST; a double that says false there returns null —
         * the country selection stayed uncut, and the test for it was green
         * against the wrong behaviour.
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

            // The real class against the same SQLite, no double: what it does
            // depends on a query on `tl_member`, and that query is exactly what
            // the test should run through.
            new MemberCheckoutDefaults(
                $this->cartConnection,
                // The same normaliser as for the typed address above: otherwise the
                // prefill would be the one path onto the invoice that bypasses it.
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
     * A logged-in member if `$this->memberId` is set — a real FrontendUser
     * without the framework, as in CartResolverTest.
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
     * The sentence itself is checked in SubscriptionTermsTest; what counts
     * here is which rule and which amount go into it.
     */
    /**
     * Abo-7b: the check itself is in SubscriptionRenewalTest; what counts here
     * is who is asked. Subscription 31 is renewable, subscription 32 no longer.
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
 * An issue source with given content.
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
 * Makes getResponse() callable. As a named class, not anonymous: PHPStan
 * otherwise only knows the parent class's type and considers respond()
 * undefined.
 */
final class TestableCheckoutController extends CheckoutController
{
    public function respond(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        return $this->getResponse($template, $model, $request);
    }
}

/**
 * Remembers which VAT IDs were checked. A class of its own, because a
 * reference in a promoted constructor argument does not work — and
 * because "was VIES asked" is the question of this test, not a side
 * issue.
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

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
 * Das Warenkorb-Element gegen echte Mitspieler auf einer SQLite-Datenbank —
 * nach dem Vorbild von AddToCartControllerTest. CartResolver,
 * DbalCartRepository, CartPricer, CartTitles und PurchaseCheck sind `final`
 * und arbeiten hier auf einer Verbindung, die beide Testschemata trägt
 * (Katalog und Warenkorb). Gedoubelt ist nur, was ein laufendes Contao
 * bräuchte: das Framework mit seinen Adaptern, Seiten- und Shop-Datensatz und
 * der Adressgenerator.
 *
 * Grundbestand: Shop 1 (EUR, AT, Vorgabesprache de) unter Startpunkt 5, die
 * Warenkorbseite 7. Hauptprodukt 1 „Wanderstock" mit den Varianten 2
 * (WS-120) und 3 (WS-140) — beide heißen im Warenkorb „Wanderstock", das ist
 * der Befund, um den es geht. Dazu Artikel 21 „Rucksack" ohne Varianten und
 * **ohne Artikelnummer**, für den Fall, dass keine gepflegt ist.
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

    // Abnahme DL, Fund 2: die Art jedes Artikels; null = kein Art-Leser.
    private string|null $kind = null;
    private const LONG = 3;
    private const PLAIN = 21;

    private const CART_URL = 'https://shop.example/warenkorb.html';

    /** E4: was die Bereitschaft der Kasse meldet. */
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

        // Block H (H-4): die Warenkorbregeln aus der DCA.
        foreach (['tl_consho_cart_rule', 'tl_consho_cart_rule_redemption', 'tl_consho_voucher', 'tl_consho_tax_class'] as $table) {
            DcaSchema::create($connection, $table);
        }

        $this->insertShop(['id' => self::SHOP, 'currency' => 'EUR', 'country' => 'AT', 'fallbackLanguage' => 'de']);
        $this->insertRootPage(self::ROOT_PAGE, 'de', self::SHOP);

        // Hauptprodukt mit zwei Varianten. Der Titel steht nur am Hauptprodukt,
        // die Artikelnummer an jeder Variante — genau die Aufteilung, die
        // CartTitles abbildet.
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock', 'sku' => 'WS', 'published' => 1]);
        $this->insertProductShop(['pid' => self::MAIN, 'shop' => self::SHOP]);

        $this->insertProduct(['id' => self::SHORT, 'pid' => self::MAIN, 'title' => 'rot / 120 cm', 'sku' => 'WS-120', 'published' => 1]);
        $this->insertPrice(['pid' => self::SHORT, 'net' => '10.0000']);

        $this->insertProduct(['id' => self::LONG, 'pid' => self::MAIN, 'title' => 'rot / 140 cm', 'sku' => 'WS-140', 'published' => 1]);
        $this->insertPrice(['pid' => self::LONG, 'net' => '10.0000']);

        // Ohne Artikelnummer: das Feld ist in tl_consho_product nicht
        // verpflichtend, und ein kleiner Shop pflegt es oft nicht.
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
        // `->` statt `?->`: Das `??` fängt den Nullfall schon ab, und PHPStan
        // (Level 6) weist die doppelte Absicherung als überflüssig zurück.
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
        // Wer 0 eintippt, will die Position weghaben. Eine Fehlermeldung
        // „ungültige Menge" wäre Rechthaberei.
        self::assertTrue(CartController::removesLine('0'));
        self::assertTrue(CartController::removesLine('0.0000'));
        self::assertFalse(CartController::removesLine('1'));
        self::assertFalse(CartController::removesLine('keine Zahl'));
    }

    // ── Was das Element beim Anzeigen aufbaut ───────────────────────────────

    public function testZweiVariantenEinesProduktsSindImWarenkorbAuseinanderzuhalten(): void
    {
        // Der Befund: Beide Zeilen heißen „Wanderstock", weil CartTitles
        // absichtlich den Titel des Hauptprodukts liefert. Die Artikelnummer
        // steht je Variante daneben und macht Beschriftung und Knöpfe
        // unterscheidbar.
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
        // Abo-2 (A10, P15): die Bedingungen an der Zeile, gebaut aus dem
        // Betrag, den die Zeile zeigt — 1,00 netto × 2 = 2,40 brutto.
        $cartId = $this->fill([]);
        $this->cartConnection->insert('tl_consho_price', ['pid' => self::SHORT, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '1.0000', 'subscriptionRule' => 5]);
        (new DbalCartRepository($this->cartConnection))->addItem($cartId, self::SHORT, '2.0000', 1_000, 5);
        (new DbalCartRepository($this->cartConnection))->addItem($cartId, self::SHORT, '1.0000', 1_000);

        $lines = $this->rowsOf($this->render()['data'], 'lines');

        self::assertSame('Bedingungen 5 zu 2.4000', $lines[0]['terms']);
        self::assertNull($lines[1]['terms']);
    }

    /**
     * Abnahme DL, Fund 2: „zzgl. Versandkosten" nur, wenn etwas versandt wird.
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
        // Abo-7b (P67): „verlängert WEB-00084-1", und eine Mengenänderung —
        // auch ein selbst gebautes Formular — ändert nichts.
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

        // Entfernen geht.
        $this->submit(['consho_cart_action' => 'remove', 'item' => (string) $renewal]);
        self::assertSame(1, $this->countItems());
    }

    public function testEinGastMitAboErfaehrtVorDerKasseDassEsEinKontoBraucht(): void
    {
        // P12: Die Kasse verlangt die Anmeldung — der Warenkorb sagt es vorher.
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
        // 4.2: „weiter zum Checkout". Ohne diesen Link wäre die Seite nur
        // erreichbar, indem jemand ihre Adresse eintippt.
        $this->fill([self::SHORT => '1.0000']);

        self::assertSame(self::CART_URL, $this->render()['data']['checkout_url']);
    }

    public function testEineGesperrteKasseWirdNichtVerlinkt(): void
    {
        // E4 (C-12): statt eines Links auf eine leere Seite der Satz der Kasse.
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
        // Ein „Zur Kasse" über einem leeren Korb führt zu einer Seite, die
        // sofort sagt, dass nichts da ist.
        self::assertNull($this->render()['data']['checkout_url']);
    }

    public function testEineZeileOhneArtikelnummerTraegtIhrePosition(): void
    {
        // Ist keine gepflegt, bleibt `sku` leer und die Vorlage greift auf die
        // Position zurück. Die zählt ab 1 und läuft in der Reihenfolge, in der
        // die Tabelle gerendert wird.
        $this->fill([self::SHORT => '1.0000', self::PLAIN => '1.0000']);

        $lines = $this->rowsOf($this->render()['data'], 'lines');

        self::assertSame('WS-120', $lines[0]['sku']);
        self::assertSame(1, $lines[0]['position']);

        self::assertSame('', $lines[1]['sku']);
        self::assertSame(2, $lines[1]['position']);
    }

    public function testEineZeileOhnePreisStehtSeparatUndZaehltDiePositionWeiter(): void
    {
        // Die unbepreisbare Zeile ist eine zweite Liste, steht in der Tabelle
        // aber unter den bepreisten. Finge ihre Position wieder bei 1 an,
        // trügen zwei Zeilen dasselbe Merkmal.
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

        // isEmpty() ist falsch, solange eine unbepreisbare Zeile dasteht.
        self::assertFalse($data['is_empty']);
    }

    public function testDieZeilensummeKommtFertigFormatiertAusDemFormatierer(): void
    {
        $this->fill([self::SHORT => '3.0000']);

        $lines = $this->rowsOf($this->render()['data'], 'lines');

        // 10,00 netto × 3 = 30,00 netto → 36,00 brutto bei 20 %.
        self::assertSame((new MoneyFormatter())->format('36.0000', 'EUR', 'de'), $lines[0]['lineGross']);

        // Die Menge ohne ihre Nullen, weil sie in ein Eingabefeld geht.
        self::assertSame('3', $lines[0]['quantity']);
    }

    public function testOhneWaehrungAmStartpunktBleibtDasElementStumm(): void
    {
        /*
         * Das Fenster zwischen Deploy und `contao:migrate`: Der Startpunkt
         * trägt noch keine Währung. Gemessen, was ohne diese Sperre
         * herauskäme — `MoneyFormatter::format('0.00', '', 'de')` ergibt
         * „0,00 XXX", weil `Currencies::getFractionDigits('')` auf DEFAULT
         * zurückfällt statt zu werfen und ICU einen unbekannten Code als
         * Platzhalter setzt. Und zwar auch bei leerem Warenkorb, also auf
         * jeder Ansicht der Warenkorbseite.
         */
        $this->root = $this->pageModel(self::ROOT_PAGE, ['consho_shop' => self::SHOP, 'consho_currency' => '']);
        $this->fill([self::SHORT => '1.0000']);

        $result = $this->render();

        /*
         * Geprüft wird, was das Element der Vorlage übergibt, NICHT der Inhalt
         * der Antwort: Die FragmentTemplate dieses Tests schließt über eine
         * leere Response, `getContent()` ist also in beiden Fällen ''. Gemessen
         * — mit einer Zusicherung auf den Inhalt überlebt das Entfernen der
         * Sperre die ganze Suite.
         */
        self::assertSame([], $result['data']);
        self::assertSame('', $result['response']->getContent());
    }

    public function testGepreistWirdInDerWaehrungDesStartpunktsNichtInDerDesShops(): void
    {
        /*
         * Spec 4.0: Die Währung gehört dem Publikum und hängt am Startpunkt,
         * das Heimatland dem Rechnungssteller und hängt am Shop. Der Shop
         * dieses Tests steht auf EUR und auf Österreich — gepreist wird
         * trotzdem in Pfund, weil der Startpunkt es so sagt, und die 20 %
         * kommen weiterhin aus AT.
         *
         * Der Euro-Preis zu 10,00 bleibt daneben stehen: Nähme das Element
         * wieder die Währung des Shops, fände es ihn und rechnete 24,00 statt
         * 12,00 — der Unterschied ist damit nicht nur das Währungszeichen.
         */
        $this->root = $this->pageModel(self::ROOT_PAGE, ['consho_shop' => self::SHOP, 'consho_currency' => 'GBP']);
        $this->insertPrice(['pid' => self::SHORT, 'net' => '5.0000', 'currency' => 'GBP']);

        $this->fill([self::SHORT => '2.0000']);

        $data = $this->render()['data'];
        $lines = $this->rowsOf($data, 'lines');

        // 5,00 netto × 2 = 10,00 netto → 12,00 brutto bei 20 %.
        $inPfund = (new MoneyFormatter())->format('12.0000', 'GBP', 'de');

        self::assertCount(1, $lines);
        self::assertSame($inPfund, $lines[0]['lineGross']);
        self::assertSame($inPfund, $data['total']);
        self::assertNotSame((new MoneyFormatter())->format('12.0000', 'EUR', 'de'), $data['total']);
    }

    // ── Für wen der Korb rechnet (C-4) ──────────────────────────────────────

    public function testEinGastSiehtDenKorbBrutto(): void
    {
        $this->netPriceGroups([3]);
        $this->fill([self::SHORT => '2.0000']);

        $data = $this->render()['data'];

        self::assertFalse($data['showsNet']);
        // 10,00 netto × 2 = 20,00 netto → 24,00 brutto bei 20 %.
        self::assertSame((new MoneyFormatter())->format('24.0000', 'EUR', 'de'), $data['total']);
        self::assertSame((new MoneyFormatter())->format('24.0000', 'EUR', 'de'), $this->rowsOf($data, 'lines')[0]['lineGross']);
    }

    public function testEinMitgliedEinerNettogruppeSiehtDenKorbNetto(): void
    {
        // ⚠️ Zeile UND Summe, beide: Eine Summe netto über Zeilen brutto wäre
        // eine Rechnung, die sichtbar nicht aufgeht — und genau die Art
        // Fehler, die ein Test nur auf die Summe durchließe.
        $this->netPriceGroups([3]);
        $this->fill([self::SHORT => '2.0000']);

        $data = $this->render(memberGroups: [3])['data'];

        self::assertTrue($data['showsNet']);
        self::assertSame((new MoneyFormatter())->format('20.0000', 'EUR', 'de'), $data['total']);
        self::assertSame((new MoneyFormatter())->format('20.0000', 'EUR', 'de'), $this->rowsOf($data, 'lines')[0]['lineGross']);
    }

    public function testEinGruppenpreisGiltAuchImKorb(): void
    {
        // Der Korb muss die Produktseite bestätigen, von der aus gelegt wurde.
        $this->netPriceGroups([]);
        $this->insertPrice(['pid' => self::SHORT, 'net' => '8.0000', 'customerGroup' => 3]);
        $this->fill([self::SHORT => '1.0000']);

        // 8,00 netto → 9,60 brutto; ohne die Gruppe wären es 12,00.
        self::assertSame((new MoneyFormatter())->format('9.6000', 'EUR', 'de'), $this->render(memberGroups: [3])['data']['total']);
        self::assertSame((new MoneyFormatter())->format('12.0000', 'EUR', 'de'), $this->render()['data']['total']);
    }

    // ── Altersfreigabe (C19) ────────────────────────────────────────────────

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
     * Block G (G5): Der Korb hält nichts zurück und sagt, was übrig ist —
     * je Artikel summiert, die Zahl nur, wo die Zeile mehr will.
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
        // takeNotice() liest und leert in einem Zug (7.1). Bliebe sie stehen,
        // läse der Besucher „Der Artikel wurde in den Warenkorb gelegt" auf
        // jeder weiteren Ansicht des Warenkorbs wieder.
        $cartId = $this->fill([self::SHORT => '1.0000']);
        (new DbalCartRepository($this->cartConnection))->setNotice($cartId, CartNotice::Added);

        self::assertSame(CartNotice::Added, $this->render()['data']['notice']);
        self::assertNull($this->render()['data']['notice']);
        self::assertSame('', $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testDieAntwortIstPrivatUndWirdNichtGespeichert(): void
    {
        // Diese Seite zeigt den Warenkorb EINES Besuchers. `private` allein
        // genügt nicht: Ein geteilter Cache darf sie gar nicht erst ablegen.
        $this->fill([self::SHORT => '1.0000']);

        $response = $this->render()['response'];

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function testOhneShopBleibtDasElementStumm(): void
    {
        // Keine Seite, kein Shop — dann hat das Element nichts zu zeigen, und
        // vor allem darf es keinen Warenkorb anlegen.
        $this->root = $this->pageModel(self::ROOT_PAGE, ['consho_shop' => 0]);

        $response = $this->render()['response'];

        self::assertSame('', $response->getContent());
    }

    // ── Was ein Absenden bewirkt ────────────────────────────────────────────

    public function testEinAbsendenAntwortetMitEinerUmleitungAufDieWarenkorbseite(): void
    {
        // Geworfen, nicht zurückgegeben: Ein Inhaltselement rendert als
        // Unteranfrage, deren Response als Zeichenkette in die Seite wandert.
        // Die Adresse kommt aus dem ContentUrlGenerator, nicht aus der
        // Anfrage.
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
        // Ein Warenkorb steht tagelang offen. Ändert der Händler in der
        // Zwischenzeit die Mindestmenge, muss die Prüfung beim Ändern erneut
        // greifen — sonst käme über den Warenkorb eine Menge zustande, die die
        // Kaufschaltfläche längst ablehnt.
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
        // Die Positions-Id steht als kleine Zahl im Formular. Jede Methode des
        // Repositorys hat das Paar (Warenkorb, Position) im WHERE, und
        // articleOf() ist genau dafür da: Eine geratene Zahl liest sich als
        // „nicht deine", nicht als die Zeile eines anderen.
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

        // Und keine Notiz im eigenen Warenkorb: articleOf() steigt vor der
        // Kaufprüfung aus. Ohne diese Absicherung liefe die Prüfung mit der
        // Artikel-Id 0 weiter und schriebe dem Besucher ein „Dieser Artikel ist
        // derzeit nicht erhältlich" über einen Artikel, den er nie angefasst hat.
        self::assertSame('', $this->cartConnection->fetchOne('SELECT notice FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testEinFremdesFormularAufDerselbenSeiteWirdNichtBeantwortet(): void
    {
        // Ein Kontaktformular in einem zweiten Element postet auf dieselbe
        // Adresse. Ohne eigene Handlung darf von hier keine Umleitung kommen —
        // sonst verschluckt der Warenkorb die fremde Absendung.
        $this->fill([self::SHORT => '1.0000']);

        $data = $this->render($this->request('POST', ['betreff' => 'Hallo']))['data'];

        self::assertCount(1, $this->rowsOf($data, 'lines'));
        self::assertSame(1, $this->countItems());
    }

    // ── Block H (H-4): Rabattcode im Warenkorb (W1, W6) ───────────────────

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

        // Rucksack 20 netto = 24 brutto; 10 % = 2,40 €.
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

    // ── Wertgutscheine (WG-5, E3) ─────────────────────────────────────────

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
        // Angerechnet wird erst in der Kasse: die Summe bleibt.
        self::assertSame('24,00 €', str_replace("\u{a0}", ' ', (string) $data['total']));

        $this->submit(['consho_cart_action' => 'removeVoucher', 'voucher' => 'AAAA-BBBB-CCCC-DDDD']);

        self::assertSame('', (string) $this->cartConnection->fetchOne('SELECT vouchers FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    /**
     * Abnahme WG-7: liegt nur Nicht-Steuerbares im Korb, sagt die Summe nichts
     * von der Steuer; ein steuerbarer Artikel daneben bringt den Satz zurück.
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

    // ── Hilfen ──────────────────────────────────────────────────────────────

    /**
     * @param array<int, string> $lines Artikel-Id => Menge, in der Reihenfolge,
     *                                  in der sie in den Warenkorb kommen
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
     * Ein Absenden, und die Adresse, auf die es umleitet.
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
     * Die Zeilen einer der beiden Listen, mit einem Typ, mit dem sich prüfen
     * lässt.
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
     * Ein angemeldetes Mitglied mit diesen Gruppen, oder niemand (C-4).
     *
     * Echter FrontendUser statt Mock, wie in CartResolverTest: Der Resolver
     * entscheidet an `instanceof FrontendUser`.
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
     * @param bool $asMember der Warenkorb wird als Mitglied 1 gesucht statt über
     *                       das Cookie — nur für die Frage, ob jemand angemeldet ist
     */
    private function controller(array|null $memberGroups = null, bool $asMember = false): CartController&TestableCartElement
    {
        $prices = new DbalPriceRepository($this->cartConnection);

        // Der Satz selbst ist in SubscriptionTermsTest geprüft; hier zählt,
        // welche Regel und welcher Zeilenbetrag hineingehen.
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
     * Die Zeile trägt `consho_currency` nur, wo der Aufrufer sie mitgibt — also
     * am Startpunkt. Die Warenkorbseite selbst bekommt sie nicht: Die Spalte
     * wird nur am Startpunkt gepflegt, und ein Doppel, das sie überall
     * herausgäbe, wäre fähiger als das Original.
     *
     * @param array<string, int|string> $row
     */
    private function pageModel(int $id, array $row): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', $id],
            // Aus der Kennung abgeleitet und nicht übergeben: Woran
            // ShopResolver den Startpunkt erkennt, ist `type` — und zwei
            // Angaben, die dasselbe sagen sollen, laufen irgendwann
            // auseinander.
            ['type', self::ROOT_PAGE === $id ? 'root' : 'regular'],
            ['rootId', self::ROOT_PAGE],
            ['language', 'de'],
        ]);
        $page->method('row')->willReturn($row + ['id' => $id]);

        return $page;
    }
}

/**
 * `getResponse()` ist protected. Die anonyme Unterklasse oben macht sie über
 * `respond()` erreichbar; diese Schnittstelle gibt dem Rückgabewert von
 * `controller()` einen Namen, den die statische Analyse kennt.
 */
interface TestableCartElement
{
    public function respond(FragmentTemplate $template, ContentModel $model, Request $request): Response;
}

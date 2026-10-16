<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\EventListener\SubrequestCacheSubscriber;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Intl\Countries;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\FrontendUser;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;
use Webwerkwien\ContaoConshoBundle\Cart\CartResolver;
use Webwerkwien\ContaoConshoBundle\Cart\CartToken;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\MiniCartController;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Der Mini-Warenkorb (C10).
 *
 * Der Kern dieser Datei ist **eine** Behauptung: Die Antwort des Fragments
 * trägt den Header `Contao-Merge-Cache-Control` **nicht**. Trüge sie ihn,
 * mischte `SubrequestCacheSubscriber` ihr `private, no-store` in das
 * Cache-Control der Seite — und jede Seite mit einem Mini-Warenkorb fiele aus
 * dem geteilten Cache, sobald ESI nicht greift.
 *
 * Deshalb wird `getResponse()` hier wirklich aufgerufen und nicht nur
 * `freshResponse()` betrachtet: Ein Test, der allein das Antwortobjekt prüfte,
 * bliebe grün, wenn der Controller es nie weiterreichte. Die `FragmentTemplate`
 * bekommt dafür eine Schließung, die genau das tut, was
 * `AbstractFragmentController::render()` tut, wenn ihm **keine** Antwort
 * übergeben wird (Z. 232–241 zusammen mit `markResponseForInternalCaching()`):
 * Sie setzt den Misch-Header. Ein Controller, der `$template->getResponse()`
 * ohne Argument aufriefe, bekäme ihn also und diese Tests würden rot.
 *
 * Was diese Tests NICHT zeigen:
 *
 * - dass ein Reverse Proxy das Fragment tatsächlich per ESI nachlädt — das ist
 *   die Messung in C-12 (13.2);
 * - dass die Twig-Datei fehlerfrei rendert; sie wird wie in CartTemplateTest
 *   als Text geprüft, nicht gerendert.
 */
class MiniCartControllerTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../../contao/templates/content_element/consho_mini_cart.html.twig';

    private const SHOP = 1;

    private const PAGE = 5;

    private const CART_PAGE = 8;

    private Connection $connection;

    /**
     * Die Kundengruppen, die der Controller zuletzt an den Rechner gab.
     *
     * @var list<int>
     */
    private array $groupsSeen = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        DcaSchema::create($this->connection, 'tl_consho_cart');
        DcaSchema::create($this->connection, 'tl_consho_cart_item');

        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->insert('tl_consho_shop', ['id' => self::SHOP, 'fallbackLanguage' => 'de']);

        // Für PriceAudienceResolver: Der Hinweis fragt tl_member_group und
        // tl_consho_price ab, auch wenn der Mini-Warenkorb ihn nicht zeigt.
        $this->connection->executeStatement('CREATE TABLE tl_member_group (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT "", disable CHAR(1) NOT NULL DEFAULT "")');
        DcaSchema::create($this->connection, 'tl_consho_price');
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');
    }

    public function testDasElementWirdAlsEsiGerendert(): void
    {
        // C10: Das Fragment wird frisch geholt, während die Seite im geteilten
        // Cache bleibt. Ohne `renderer` gälte `forward` — dann rendert Contao
        // das Element inline, und im Seiten-Cache läge der Warenkorb eines
        // einzelnen Besuchers.
        $attributes = (new \ReflectionClass(MiniCartController::class))->getAttributes(AsContentElement::class);

        self::assertCount(1, $attributes);
        self::assertSame('esi', $attributes[0]->getArguments()['renderer']);
    }

    public function testDieFrischeAntwortIstPrivatUndOhneMischHeader(): void
    {
        $response = MiniCartController::freshResponse();

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->has(SubrequestCacheSubscriber::MERGE_CACHE_HEADER));
    }

    /**
     * Die eigentliche Aussage des Tasks — siehe den Klassenkommentar: Die
     * Schließung der Vorlage setzt den Misch-Header genau dann, wenn ihr keine
     * vorgebaute Antwort übergeben wird.
     */
    public function testDieGerenderteAntwortTraegtDenMischHeaderNicht(): void
    {
        $this->fillCart();

        $response = $this->render($this->requestWithCart(), $this->template(), $this->page());

        self::assertFalse(
            $response->headers->has(SubrequestCacheSubscriber::MERGE_CACHE_HEADER),
            'Mit dem Misch-Header zöge das private Cache-Control des Fragments jede Seite mit Mini-Warenkorb aus dem geteilten Cache.',
        );
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    /**
     * Auch der stumme Fall darf den Header nicht tragen — er ist der Zweig,
     * der ohne Vorlage auskommt und deshalb leicht vergessen wird.
     */
    public function testAuchOhneShopBleibtDerMischHeaderWeg(): void
    {
        $response = $this->render(Request::create('https://example.com/'), $this->template(), null);

        self::assertFalse($response->headers->has(SubrequestCacheSubscriber::MERGE_CACHE_HEADER));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('', $response->getContent());
    }

    /**
     * Das Abzeichen zählt **Positionen**, nicht Stückzahlen.
     *
     * `CartView::quantity` ist die Summe der Mengen — hier 3 + 2 = 5 — und
     * damit eine Dezimalzahl: Ein Shop, der in Kilogramm verkauft, schriebe
     * „2,5" ins Abzeichen. `count()` ist von Bauart her eine ganze Zahl.
     */
    public function testDasAbzeichenZaehltPositionenUndNichtStueckzahlen(): void
    {
        $this->fillCart();

        $data = $this->context($this->requestWithCart(), $this->page());

        self::assertSame(2, $data['line_count']);
    }

    /**
     * Und `count()` zählt auch die Zeilen mit, die nicht bepreisbar sind —
     * `CartView::quantity` tut das nicht, `CartPricer` zählt die Menge erst
     * hinter der Preisprüfung. Ein Abzeichen auf der Stückzahl zeigte für einen
     * Warenkorb, der nur unbepreisbare Artikel hält, eine 0 — während der
     * Besucher genau diese Artikel entfernen muss, um weiterzukommen.
     */
    public function testEineUnbepreisbareZeileZaehltMit(): void
    {
        $this->fillCart();
        $this->connection->insert('tl_consho_cart_item', ['pid' => 1, 'product' => 999, 'quantity' => '1.0000', 'addedAt' => 30]);

        $data = $this->context($this->requestWithCart(), $this->page());

        self::assertSame(3, $data['line_count']);
    }

    /**
     * Der Betrag kommt fertig formatiert aus MoneyFormatter, nicht als rohe
     * Dezimalzahl: 3 × 10,00 netto plus 2 × 10,00 netto ergibt bei 20 % genau
     * 60,00 brutto.
     */
    public function testDerBetragKommtFertigFormatiert(): void
    {
        $this->fillCart();

        $data = $this->context($this->requestWithCart(), $this->page());

        self::assertIsString($data['total']);
        self::assertStringContainsString('60,00', $data['total']);
        self::assertStringNotContainsString('60.0000', $data['total']);
    }

    public function testDerBetragTraegtDieWaehrungDesStartpunktsNichtDieDesShops(): void
    {
        // Spec 4.0: Der Shop dieses Tests steht auf EUR (siehe framework()),
        // der Startpunkt auf GBP. Die Preise sind gedoppelt und währungsblind,
        // der Betrag bleibt also 60,00 — was sich ändert, ist einzig die
        // Währung, in der er formatiert wird. Genau darum geht es hier.
        $this->fillCart();

        $data = $this->context($this->requestWithCart(), $this->page(currency: 'GBP'));

        self::assertIsString($data['total']);
        self::assertSame((new MoneyFormatter())->format('60.0000', 'GBP', 'de'), $data['total']);
        self::assertNotSame((new MoneyFormatter())->format('60.0000', 'EUR', 'de'), $data['total']);
    }

    public function testOhneWaehrungAmStartpunktBleibtDasElementStumm(): void
    {
        /*
         * Das Fenster zwischen Deploy und `contao:migrate`. Der Mini-Warenkorb
         * steht auf jeder Seite und rendert auch leer — ohne diese Sperre
         * stünde dort „0,00 XXX" (gemessen: ICU setzt für einen unbekannten
         * Code seinen Platzhalter, und `Currencies::getFractionDigits('')`
         * fällt auf DEFAULT zurück statt zu werfen).
         *
         * Geprüft wird die Antwort selbst, nicht nur ein fehlender Betrag: Sie
         * muss die frische sein — privat, ohne Speicherung, ohne Misch-Header.
         */
        $this->fillCart();

        $request = $this->requestWithCart();
        $template = $this->template();
        $response = $this->render($request, $template, $this->page(currency: ''));

        self::assertSame([], $template->getData());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->has(SubrequestCacheSubscriber::MERGE_CACHE_HEADER));
    }

    public function testOhneWarenkorbStehtEineNullDa(): void
    {
        // Es gibt keinen Warenkorb, und es wird auch keiner angelegt (12). Das
        // Element steht trotzdem da — sonst springt das Layout, sobald der
        // erste Artikel hineinkommt.
        $data = $this->context(Request::create('https://example.com/'), $this->page());

        self::assertSame(0, $data['line_count']);
        self::assertIsString($data['total']);
    }

    public function testDerVerweisZeigtAufDieWarenkorbseite(): void
    {
        $data = $this->context(Request::create('https://example.com/'), $this->page());

        self::assertSame('https://example.com/warenkorb.html', $data['cart_page']);
    }

    public function testOhneWarenkorbseiteGibtEsKeinenVerweis(): void
    {
        // Keine Warenkorbseite gesetzt: Der Befund aus Task 13 mahnt das im
        // Backend an; das Element zeigt derweil Zahl und Summe ohne Verweis —
        // ein toter Link hülfe dem Besucher nicht.
        $data = $this->context(Request::create('https://example.com/'), $this->page(cartPageId: 0));

        self::assertNull($data['cart_page']);
    }

    public function testDieVorlageHaengtKeineWaehrungSelbstAn(): void
    {
        // Wie beim Warenkorb-Element: Der Betrag ist schon formatiert, und im
        // Englischen steht das Währungszeichen davor.
        self::assertStringNotContainsString('{{ currency }}', $this->templateSource());
    }

    public function testJederUebersetzungsschluesselStehtInBeidenSprachdateien(): void
    {
        preg_match_all('/"(CONSHO\.[A-Za-z.]+)"\|trans/', $this->markup(), $matches);

        $keys = array_values(array_unique($matches[1]));

        self::assertNotSame([], $keys, 'Die Vorlage übersetzt gar nichts — dann prüft dieser Test nichts.');

        foreach (['de', 'en'] as $locale) {
            $labels = $this->labels($locale);

            foreach ([...$keys, 'CTE.consho_mini_cart'] as $key) {
                self::assertNotNull(
                    $this->label($labels, $key),
                    \sprintf('%s fehlt in contao/languages/%s/default.php.', $key, $locale),
                );
            }
        }
    }

    /**
     * Ohne Palette ist der Typ im Backend nicht auswählbar — das Element
     * existierte dann nur im Code.
     */
    public function testDasElementHatEinePalette(): void
    {
        $dca = $GLOBALS['TL_DCA'] ?? null;
        $lang = $GLOBALS['TL_LANG'] ?? null;

        $GLOBALS['TL_DCA'] = [];
        $GLOBALS['TL_LANG'] = [];

        try {
            include __DIR__.'/../../../contao/dca/tl_content.php';

            /** @var array<string, mixed> $palettes */
            $palettes = $GLOBALS['TL_DCA']['tl_content']['palettes'];
        } finally {
            $this->restore('TL_DCA', $dca);
            $this->restore('TL_LANG', $lang);
        }

        self::assertArrayHasKey('consho_mini_cart', $palettes);
    }

    // ── Aufbau ──────────────────────────────────────────────────────────────

    /**
     * Zwei Positionen: 3 Stück und 2 Stück, beide zu 10,00 netto.
     */
    private function fillCart(): void
    {
        $this->connection->insert('tl_consho_cart', [
            'tokenHash' => CartToken::hash($this->token()),
            'shop' => self::SHOP,
            'state' => 'open',
        ]);

        $this->connection->insert('tl_consho_cart_item', ['pid' => 1, 'product' => 100, 'quantity' => '3.0000', 'addedAt' => 10]);
        $this->connection->insert('tl_consho_cart_item', ['pid' => 1, 'product' => 200, 'quantity' => '2.0000', 'addedAt' => 20]);
    }

    /**
     * 43 Zeichen aus dem erlaubten Vorrat — CartToken::fromRequest weist alles
     * andere ab, bevor es die Datenbank sieht.
     */
    private function token(): string
    {
        return str_repeat('a', 43);
    }

    private function requestWithCart(): Request
    {
        return Request::create('https://example.com/', cookies: [CartToken::NAME => $this->token()]);
    }

    // ── Für wen der Mini-Warenkorb rechnet (C-4) ────────────────────────────

    public function testEinGastSiehtDieSummeBrutto(): void
    {
        $this->fillCart();

        $data = $this->context($this->requestWithCart(), $this->page());

        self::assertFalse($data['showsNet']);
        self::assertSame([], $this->groupsSeen);
    }

    public function testDieGruppenDesMitgliedsErreichenDenRechner(): void
    {
        // ⚠️ Regel 24, im Abschlussreview C-4 als Lücke gemeldet: Ob der
        // Controller die Gruppen überhaupt weitergibt, verrät kein Betrag —
        // der Rechner-Doppel liefert hier immer dieselben 10,00. Also wird
        // aufgezeichnet, was ankam.
        $this->connection->insert('tl_member_group', ['id' => 3, 'name' => 'Händler']);
        $this->fillCart();

        $this->context($this->requestWithCart(), $this->page(), [3]);

        self::assertSame([3], $this->groupsSeen);
    }

    public function testEinMitgliedEinerNettogruppeSiehtDieSummeNetto(): void
    {
        $this->connection->insert('tl_member_group', ['id' => 3, 'name' => 'Händler']);
        $this->connection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => self::SHOP]);
        $this->fillCart();

        $brutto = $this->context($this->requestWithCart(), $this->page());
        $netto = $this->context($this->requestWithCart(), $this->page(), [3]);

        self::assertFalse($brutto['showsNet']);
        self::assertTrue($netto['showsNet']);

        // Die Beträge ausdrücklich, nicht nur „verschieden": 3 + 2 Stück zu
        // 10,00 netto sind 50,00 netto und 60,00 brutto bei 20 %. Ein
        // assertNotSame wäre auch dann grün, wenn die Summe aus einer
        // beliebigen anderen Quelle käme.
        self::assertSame((new MoneyFormatter())->format('60.0000', 'EUR', 'de'), $brutto['total']);
        self::assertSame((new MoneyFormatter())->format('50.0000', 'EUR', 'de'), $netto['total']);
    }

    /**
     * Was das Element der Vorlage übergibt.
     *
     * Über getData(), nicht get(): FragmentTemplate::get() wirft für einen
     * Schlüssel, dessen Wert null ist — und genau das ist `cart_page` ohne
     * eingestellte Warenkorbseite.
     *
     * @param list<int>|null $memberGroups null = niemand angemeldet (C-4)
     *
     * @return array<string, mixed>
     */
    private function context(Request $request, PageModel|null $page, array|null $memberGroups = null): array
    {
        $this->render($request, $template = $this->template(), $page, $memberGroups);

        return $template->getData();
    }

    private function render(Request $request, FragmentTemplate $template, PageModel|null $page, array|null $memberGroups = null): Response
    {
        if (null !== $page) {
            $request->attributes->set('pageModel', $page);
        }

        $repository = new DbalCartRepository($this->connection);
        $framework = $this->framework();
        $shops = new ShopResolver($framework);

        /*
         * getResponse() ist protected — wie in ProductPageControllerTest macht
         * eine anonyme Unterklasse sie für den Test erreichbar. Sie überschreibt
         * nichts, damit geprüft wird, was das Element wirklich tut.
         */
        $controller = new class(
            new CartResolver($repository, new TokenStorage()),
            $repository,
            $this->pricer(),
            $shops,
            new ShopLanguages($this->connection),
            new MoneyFormatter(),
            new ShopPageUrls($framework, $shops, $this->urls()),
            new PriceAudienceResolver($this->tokenStorage($memberGroups), $this->connection),
        ) extends MiniCartController {
            public function call(FragmentTemplate $template, ContentModel $model, Request $request): Response
            {
                return $this->getResponse($template, $model, $request);
            }
        };

        return $controller->call($template, $this->createMock(ContentModel::class), $request);
    }

    /**
     * Eine FragmentTemplate, deren Schließung sich verhält wie
     * AbstractFragmentController::render(): Ohne vorgebaute Antwort setzt sie
     * den Misch-Header und räumt das Cache-Control weg.
     */
    private function template(): FragmentTemplate
    {
        return new FragmentTemplate(
            'content_element/consho_mini_cart',
            static function (FragmentTemplate $template, Response|null $preBuilt): Response {
                if (null === $preBuilt) {
                    $preBuilt = new Response();
                    $preBuilt->headers->set(SubrequestCacheSubscriber::MERGE_CACHE_HEADER, '1');
                    $preBuilt->headers->remove('Cache-Control');
                }

                return $preBuilt;
            },
        );
    }

    /**
     * Der Startpunkt selbst — `rootId` zeigt auf ihn. Seit C-1c trägt seine
     * Zeile auch die Währung, denn die hängt am Startpunkt und nicht am Shop.
     */
    private function page(int $cartPageId = self::CART_PAGE, string $currency = 'EUR'): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', self::PAGE],
            // `type`, nicht nur `rootId`: Woran ShopResolver einen Startpunkt
            // erkennt, ist die echte Spalte — `rootId` allein zu setzen hieße,
            // das Doppel über seine Herkunft entscheiden zu lassen.
            ['type', 'root'],
            ['rootId', self::PAGE],
            ['language', 'de'],
        ]);
        $page->method('row')->willReturn([
            'id' => self::PAGE,
            'consho_shop' => self::SHOP,
            'consho_currency' => $currency,
            'consho_cartPage' => $cartPageId,
        ]);

        return $page;
    }

    private function framework(): ContaoFramework
    {
        $cartPage = $this->createMock(PageModel::class);

        $pages = $this->createMock(Adapter::class);
        $pages->method('__call')->willReturnCallback(
            /**
             * @param list<mixed> $arguments
             */
            static fn (string $method, array $arguments): PageModel|null => 'findById' === $method && self::CART_PAGE === $arguments[0] ? $cartPage : null,
        );

        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnMap([
            ['id', self::SHOP],
            ['currency', 'EUR'],
            ['country', 'AT'],
        ]);

        $shops = $this->createMock(Adapter::class);
        $shops->method('__call')->willReturn($shop);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnMap([
            [PageModel::class, $pages],
            [ShopModel::class, $shops],
        ]);

        return $framework;
    }

    private function urls(): ContentUrlGenerator
    {
        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturn('https://example.com/warenkorb.html');

        return $urls;
    }

    /**
     * Artikel 100 und 200 kosten 10,00 netto, alles andere ist unbepreisbar.
     * 20 % in Österreich.
     */
    /**
     * Ein angemeldetes Mitglied mit diesen Gruppen, oder niemand (C-4).
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

    private function pricer(): CartPricer
    {
        $prices = $this->createMock(CartLinePriceSource::class);
        $prices->method('forCartLine')->willReturnCallback(
            function (int $articleId, int $mainProductId, string $currency, string $quantity, int $now, array $groups): CartLinePrice|null {
                // Aufgezeichnet, weil die Frage ist, ob der Controller die
                // Gruppen überhaupt weitergibt (Regel 24) — ein Rückgabewert
                // allein verriete das nicht.
                $this->groupsSeen = $groups;

                return \in_array($articleId, [100, 200], true) ? new CartLinePrice('10.0000', 1) : null;
            },
        );

        $tree = $this->createMock(ArticleTreeInterface::class);
        $tree->method('articlesOf')->willReturnCallback(
            /**
             * @param list<int> $ids
             *
             * @return array<int, int>
             */
            static fn (array $ids): array => array_combine($ids, $ids),
        );

        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->method('findRate')->willReturnCallback(
            static fn (int $taxClassId, string $country): string|null => 'AT' === $country ? '20' : null,
        );

        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT']);

        return new CartPricer($tree, $prices, new GrossCalculator(new TaxResolver($rates, new CountryProvider($countries))));
    }

    // ── Vorlage und Sprachdateien ───────────────────────────────────────────

    /**
     * Die Vorlage **ohne** ihre Twig-Kommentare — wie in CartTemplateTest: Ein
     * Erklärkommentar, der Markup ausschreibt, verfälscht sonst jede Suche.
     */
    private function markup(): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $this->templateSource());
    }

    private function templateSource(): string
    {
        $contents = file_get_contents(self::TEMPLATE);

        self::assertIsString($contents);

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function labels(string $locale): array
    {
        $backup = $GLOBALS['TL_LANG'] ?? null;
        $GLOBALS['TL_LANG'] = [];

        try {
            include __DIR__.'/../../../contao/languages/'.$locale.'/default.php';

            /** @var array<string, mixed> $loaded */
            $loaded = $GLOBALS['TL_LANG'];
        } finally {
            $this->restore('TL_LANG', $backup);
        }

        return $loaded;
    }

    /**
     * @param array<string, mixed> $labels
     */
    private function label(array $labels, string $key): string|null
    {
        $item = $labels;

        foreach (explode('.', $key) as $part) {
            if (!\is_array($item) || !isset($item[$part])) {
                return null;
            }

            $item = $item[$part];
        }

        // Eine CTE-Beschriftung ist ein Paar [Name, Beschreibung]; der erste
        // Eintrag ist der Name.
        if (\is_array($item)) {
            return isset($item[0]) && \is_string($item[0]) ? $item[0] : null;
        }

        return (string) $item;
    }

    private function restore(string $key, mixed $backup): void
    {
        if (null === $backup) {
            unset($GLOBALS[$key]);

            return;
        }

        $GLOBALS[$key] = $backup;
    }
}

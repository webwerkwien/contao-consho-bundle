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
 * The mini cart (C10).
 *
 * The core of this file is **one** claim: the fragment's response does
 * **not** carry the header `Contao-Merge-Cache-Control`. If it did,
 * `SubrequestCacheSubscriber` would merge its `private, no-store` into the
 * page's Cache-Control — and every page with a mini cart would drop out of
 * the shared cache as soon as ESI does not apply.
 *
 * That is why `getResponse()` is really called here and not just
 * `freshResponse()` looked at: a test that checked only the response object
 * would stay green if the controller never passed it on. For this the
 * `FragmentTemplate` gets a closure that does exactly what
 * `AbstractFragmentController::render()` does when it is passed **no**
 * response (l. 232–241 together with `markResponseForInternalCaching()`):
 * it sets the merge header. A controller that called
 * `$template->getResponse()` without an argument would therefore get it, and
 * these tests would turn red.
 *
 * What these tests do NOT show:
 *
 * - that a reverse proxy actually loads the fragment via ESI — that is the
 *   measurement in C-12 (13.2);
 * - that the Twig file renders without errors; as in CartTemplateTest it is
 *   checked as text, not rendered.
 */
class MiniCartControllerTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../../contao/templates/content_element/consho_mini_cart.html.twig';

    private const SHOP = 1;

    private const PAGE = 5;

    private const CART_PAGE = 8;

    private Connection $connection;

    /**
     * The customer groups the controller last passed to the calculator.
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

        // For PriceAudienceResolver: the notice queries tl_member_group and
        // tl_consho_price, even though the mini cart does not show it.
        $this->connection->executeStatement('CREATE TABLE tl_member_group (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT "", disable CHAR(1) NOT NULL DEFAULT "")');
        DcaSchema::create($this->connection, 'tl_consho_price');
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');
    }

    public function testDasElementWirdAlsEsiGerendert(): void
    {
        // C10: the fragment is fetched fresh while the page stays in the shared
        // cache. Without `renderer`, `forward` would apply — then Contao renders
        // the element inline, and the page cache would hold the cart of a
        // single visitor.
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
     * The actual claim of this file — see the class comment: the template's
     * closure sets the merge header exactly when it is passed no prebuilt
     * response.
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
     * The silent case must not carry the header either — it is the branch
     * that gets by without a template and is therefore easily forgotten.
     */
    public function testAuchOhneShopBleibtDerMischHeaderWeg(): void
    {
        $response = $this->render(Request::create('https://example.com/'), $this->template(), null);

        self::assertFalse($response->headers->has(SubrequestCacheSubscriber::MERGE_CACHE_HEADER));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('', $response->getContent());
    }

    /**
     * The badge counts **lines**, not pieces.
     *
     * `CartView::quantity` is the sum of the quantities — here 3 + 2 = 5 — and
     * therefore a decimal: a shop selling by the kilogram would write "2,5"
     * into the badge. `count()` is an integer by construction.
     */
    public function testDasAbzeichenZaehltPositionenUndNichtStueckzahlen(): void
    {
        $this->fillCart();

        $data = $this->context($this->requestWithCart(), $this->page());

        self::assertSame(2, $data['line_count']);
    }

    /**
     * And `count()` also counts the lines that cannot be priced —
     * `CartView::quantity` does not, `CartPricer` only counts the quantity
     * after the price check. A badge on the piece count would show a 0 for a
     * cart holding only unpriceable articles — while the visitor has to
     * remove exactly these articles to get any further.
     */
    public function testEineUnbepreisbareZeileZaehltMit(): void
    {
        $this->fillCart();
        $this->connection->insert('tl_consho_cart_item', ['pid' => 1, 'product' => 999, 'quantity' => '1.0000', 'addedAt' => 30]);

        $data = $this->context($this->requestWithCart(), $this->page());

        self::assertSame(3, $data['line_count']);
    }

    /**
     * The amount comes fully formatted from MoneyFormatter, not as a raw
     * decimal: 3 × 10.00 net plus 2 × 10.00 net makes exactly 60.00 gross at
     * 20 %.
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
        // Spec 4.0: the shop of this test is set to EUR (see framework()), the
        // start point to GBP. The prices are doubled and currency-blind, so the
        // amount stays 60.00 — the only thing that changes is the currency in
        // which it is formatted. That is exactly the point here.
        $this->fillCart();

        $data = $this->context($this->requestWithCart(), $this->page(currency: 'GBP'));

        self::assertIsString($data['total']);
        self::assertSame((new MoneyFormatter())->format('60.0000', 'GBP', 'de'), $data['total']);
        self::assertNotSame((new MoneyFormatter())->format('60.0000', 'EUR', 'de'), $data['total']);
    }

    public function testOhneWaehrungAmStartpunktBleibtDasElementStumm(): void
    {
        /*
         * The window between deploy and `contao:migrate`. The mini cart stands
         * on every page and renders even when empty — without this lock it
         * would show "0,00 XXX" (measured: ICU puts its placeholder for an
         * unknown code, and `Currencies::getFractionDigits('')` falls back to
         * DEFAULT instead of throwing).
         *
         * What is checked is the response itself, not just a missing amount: it
         * has to be the fresh one — private, no storage, no merge header.
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
        // There is no cart, and none is created either (12). The element still
        // stands there — otherwise the layout jumps as soon as the first article
        // goes in.
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
        // No cart page set: a setup issue reminds of that in the back end;
        // meanwhile the element shows count and total without a link — a dead
        // link would not help the visitor.
        $data = $this->context(Request::create('https://example.com/'), $this->page(cartPageId: 0));

        self::assertNull($data['cart_page']);
    }

    public function testDieVorlageHaengtKeineWaehrungSelbstAn(): void
    {
        // As with the cart element: the amount is already formatted, and in
        // English the currency symbol stands in front.
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
     * Without a palette the type cannot be selected in the back end — the
     * element would then exist only in the code.
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

    // ── Setup ───────────────────────────────────────────────────────────────

    /**
     * Two lines: 3 pieces and 2 pieces, both at 10.00 net.
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
     * 43 characters from the allowed set — CartToken::fromRequest rejects
     * everything else before it sees the database.
     */
    private function token(): string
    {
        return str_repeat('a', 43);
    }

    private function requestWithCart(): Request
    {
        return Request::create('https://example.com/', cookies: [CartToken::NAME => $this->token()]);
    }

    // ── Whom the mini cart calculates for (C-4) ─────────────────────────────

    public function testEinGastSiehtDieSummeBrutto(): void
    {
        $this->fillCart();

        $data = $this->context($this->requestWithCart(), $this->page());

        self::assertFalse($data['showsNet']);
        self::assertSame([], $this->groupsSeen);
    }

    public function testDieGruppenDesMitgliedsErreichenDenRechner(): void
    {
        // ⚠️ The test must go through the real place (C-4): whether the
        // controller passes the groups on at all is revealed by no amount —
        // the calculator double always returns the same 10.00 here. So what
        // arrived is recorded.
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

        // The amounts explicitly, not just "different": 3 + 2 pieces at
        // 10.00 net are 50.00 net and 60.00 gross at 20 %. An assertNotSame
        // would also be green if the total came from any other source.
        self::assertSame((new MoneyFormatter())->format('60.0000', 'EUR', 'de'), $brutto['total']);
        self::assertSame((new MoneyFormatter())->format('50.0000', 'EUR', 'de'), $netto['total']);
    }

    /**
     * What the element passes to the template.
     *
     * Via getData(), not get(): FragmentTemplate::get() throws for a key whose
     * value is null — and that is exactly `cart_page` without a configured
     * cart page.
     *
     * @param list<int>|null $memberGroups null = nobody logged in (C-4)
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
         * getResponse() is protected — as in ProductPageControllerTest an
         * anonymous subclass makes it reachable for the test. It overrides
         * nothing, so that what is checked is what the element really does.
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
     * A FragmentTemplate whose closure behaves like
     * AbstractFragmentController::render(): without a prebuilt response it
     * sets the merge header and clears the Cache-Control.
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
     * The start point itself — `rootId` points to it. Since C-1c its row also
     * carries the currency, because that hangs off the start point and not
     * off the shop.
     */
    private function page(int $cartPageId = self::CART_PAGE, string $currency = 'EUR'): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', self::PAGE],
            // `type`, not just `rootId`: what ShopResolver recognises a start point
            // by is the real column — setting only `rootId` would mean letting the
            // double decide about its own origin.
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
     * Articles 100 and 200 cost 10.00 net, everything else cannot be priced.
     * 20 % in Austria.
     */
    /**
     * A logged-in member with these groups, or nobody (C-4).
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
                // Recorded, because the question is whether the controller passes
                // the groups on at all — a return value alone would not reveal
                // that.
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

    // ── Template and language files ─────────────────────────────────────────

    /**
     * The template **without** its Twig comments — as in CartTemplateTest: an
     * explanatory comment that spells out markup otherwise distorts every
     * search.
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

        // A CTE label is a pair [name, description]; the first entry is the
        // name.
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Slug\Slug;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\AliasRestoreListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ProductAddressListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\TranslationFieldsListener;
use Webwerkwien\ContaoConshoBundle\Seo\DbalProductPageRepository;
use Webwerkwien\ContaoConshoBundle\Seo\DbalRedirectResolver;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Redirects when a product itself changes manufacturer, product type or SKU
 * — and when one save changes several parts of the address at
 * once.
 *
 * Setup:
 * - Shop 1 (default language de): start point 1 (de) and 2 (en), both with
 *   {manufacturer}/{alias}.
 * - Shop 2 (de): start point 3 with {type}/{sku}/{alias} ({alias} is required).
 * - Manufacturer 7 "kraeuterhaus", 8 "bienenhof", 9 without an alias.
 *   Type 3 "tinktur", 4 "salbe".
 * - Product 10 "schwedenbitter", SKU sb-100, manufacturer 7, type 3, English
 *   "herbal-bitters". Variant 13 below it, SKU sb-250.
 *
 * The mask is replayed the way DC_Table::submit() processes it:
 * onbeforesubmit, the one UPDATE, onsubmit with priority 1, writing the
 * translations (priority 0), onsubmit with priority −1.
 */
class ProductAddressRedirectTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                alias TEXT NOT NULL DEFAULT '',
                type TEXT NOT NULL DEFAULT 'regular',
                published INTEGER NOT NULL DEFAULT 0,
                dns TEXT NOT NULL DEFAULT '',
                urlPrefix TEXT NOT NULL DEFAULT '',
                urlSuffix TEXT NOT NULL DEFAULT '',
                language TEXT NOT NULL DEFAULT '',
                consho_shop INTEGER NOT NULL DEFAULT 0,
                conshoPathTemplate TEXT NOT NULL DEFAULT ''
            )",
        );
        DcaSchema::create($this->connection, 'tl_consho_url_redirect');

        $this->insertShop(['id' => 1, 'fallbackLanguage' => 'de']);
        $this->page(1, type: 'root', language: 'de', shop: 1);
        $this->page(123, pid: 1, alias: 'produkt', type: 'consho_product', template: '{manufacturer}/{alias}');
        $this->page(2, type: 'root', language: 'en', shop: 1, prefix: 'en');
        $this->page(124, pid: 2, alias: 'product', type: 'consho_product', template: '{manufacturer}/{alias}');

        $this->insertShop(['id' => 2, 'fallbackLanguage' => 'de']);
        $this->page(3, type: 'root', language: 'de', shop: 2);
        $this->page(125, pid: 3, alias: 'artikel', type: 'consho_product', template: '{type}/{sku}/{alias}');

        $this->insertManufacturer(['id' => 7, 'title' => 'Kräuterhaus', 'alias' => 'kraeuterhaus']);
        $this->insertManufacturer(['id' => 8, 'title' => 'Bienenhof', 'alias' => 'bienenhof']);
        $this->insertManufacturer(['id' => 9, 'title' => 'Ohne Alias', 'alias' => '']);
        $this->insertProductType(['id' => 3, 'title' => 'Tinktur', 'alias' => 'tinktur']);
        $this->insertProductType(['id' => 4, 'title' => 'Salbe', 'alias' => 'salbe']);

        $this->product(10, 'schwedenbitter', 'sb-100');
        $this->product(13, 'schwedenbitter-250', 'sb-250', pid: 10);

        $this->insertTranslation(['pid' => 10, 'ptable' => 'tl_consho_product', 'language' => 'en', 'title' => 'Herbal Bitters', 'alias' => 'herbal-bitters']);
    }

    // ── Manufacturer ────────────────────────────────────────────────────

    public function testEinHerstellerwechselZeichnetDieAlteAdresseNurAufStartpunktenMitHerstellerAuf(): void
    {
        // Start point 3 has {type}/{sku}/{alias} — nothing moves there.
        $this->submit(10, ['manufacturer' => 8]);

        self::assertSame(
            [
                [1, '/produkt/kraeuterhaus/schwedenbitter.html', 10],
                [2, '/en/product/kraeuterhaus/herbal-bitters.html', 10],
            ],
            $this->recorded(),
        );
    }

    public function testEinStartpunktOhneHerstellerInDerVorlageWirdGarNichtAbgefragt(): void
    {
        $calls = [];
        $repository = $this->countingRepository(static function (int $shopId, string $language) use (&$calls): void {
            $calls[] = [$shopId, $language];
        });
        $recorder = new RedirectRecorder($this->connection, $repository, new ShopLanguages($this->connection));

        $before = $recorder->productAddressState(10);
        self::assertNotNull($before);
        $this->connection->update('tl_consho_product', ['manufacturer' => 8], ['id' => 10]);
        $recorder->recordProductAddress(10, $before, self::NOW);

        self::assertSame([[1, 'de'], [1, 'en']], $calls);
    }

    public function testDieAlteAdresseFuehrtZumProdukt(): void
    {
        $this->submit(10, ['manufacturer' => 8]);

        self::assertSame(10, $this->resolver()->find('/produkt/kraeuterhaus/schwedenbitter.html', 'example.org')?->productId);
        self::assertNull($this->resolver()->find('/produkt/bienenhof/schwedenbitter.html', 'example.org'));
    }

    public function testDieRueckkehrZumAltenHerstellerHinterlaesstKeineVeralteteZeile(): void
    {
        $this->submit(10, ['manufacturer' => 8]);
        $this->submit(10, ['manufacturer' => 7]);

        self::assertSame(
            [
                [1, '/produkt/bienenhof/schwedenbitter.html', 10],
                [2, '/en/product/bienenhof/herbal-bitters.html', 10],
            ],
            $this->recorded(),
        );
        self::assertNull($this->resolver()->find('/produkt/kraeuterhaus/schwedenbitter.html', 'example.org'));
    }

    public function testEinWechselAufEinenHerstellerOhneAliasIstEineWanderung(): void
    {
        /*
         * Like a manufacturer without a previous alias in the recorder: the template
         * leaves out the empty segment, and the product afterwards has the address
         * /produkt/schwedenbitter.html. A row still lying there goes.
         */
        $this->redirect(1, '/produkt/schwedenbitter.html', 10);

        $this->submit(10, ['manufacturer' => 9]);

        self::assertSame(
            [
                [1, '/produkt/kraeuterhaus/schwedenbitter.html', 10],
                [2, '/en/product/kraeuterhaus/herbal-bitters.html', 10],
            ],
            $this->recorded(),
        );
    }

    public function testKeinHerstellerIstEbenfallsEineWanderung(): void
    {
        $this->submit(10, ['manufacturer' => 0]);

        self::assertSame(
            [
                [1, '/produkt/kraeuterhaus/schwedenbitter.html', 10],
                [2, '/en/product/kraeuterhaus/herbal-bitters.html', 10],
            ],
            $this->recorded(),
        );
    }

    public function testEinProduktOhneBisherigenHerstellerLoestDieAdresseOhneSegmentAb(): void
    {
        $this->connection->update('tl_consho_product', ['manufacturer' => 0], ['id' => 10]);

        $this->submit(10, ['manufacturer' => 7]);

        self::assertSame(
            [
                [1, '/produkt/schwedenbitter.html', 10],
                [2, '/en/product/herbal-bitters.html', 10],
            ],
            $this->recorded(),
        );
    }

    // ── Translation language ────────────────────────────────────────────

    public function testDasNeueSegmentIstDerUebersetzteAliasDesNeuenHerstellers(): void
    {
        /*
         * The new address can be recognised by which row is deleted: a
         * row on the new address goes, one on the manufacturer's own alias
         * — not the new address — stays.
         */
        $this->insertTranslation(['pid' => 8, 'ptable' => 'tl_consho_manufacturer', 'language' => 'en', 'title' => 'Bee Farm', 'alias' => 'bee-farm']);
        $this->redirect(2, '/en/product/bienenhof/herbal-bitters.html', 12);
        $this->redirect(2, '/en/product/bee-farm/herbal-bitters.html', 10);

        $this->submit(10, ['manufacturer' => 8]);

        self::assertSame(
            [
                [2, '/en/product/bienenhof/herbal-bitters.html', 12],
                [1, '/produkt/kraeuterhaus/schwedenbitter.html', 10],
                [2, '/en/product/kraeuterhaus/herbal-bitters.html', 10],
            ],
            $this->recorded(),
        );
    }

    public function testOhneUebersetzungFaelltDasNeueSegmentAufDenEigenenAliasZurueck(): void
    {
        $this->redirect(2, '/en/product/bienenhof/herbal-bitters.html', 10);

        $this->submit(10, ['manufacturer' => 8]);

        self::assertNotContains([2, '/en/product/bienenhof/herbal-bitters.html', 10], $this->recorded());
        self::assertContains([2, '/en/product/kraeuterhaus/herbal-bitters.html', 10], $this->recorded());
    }

    public function testDasAlteSegmentIstDerUebersetzteAliasDesAltenHerstellers(): void
    {
        $this->insertTranslation(['pid' => 7, 'ptable' => 'tl_consho_manufacturer', 'language' => 'en', 'title' => 'Herb House', 'alias' => 'herb-house']);

        $this->submit(10, ['manufacturer' => 8]);

        self::assertSame(
            [
                [1, '/produkt/kraeuterhaus/schwedenbitter.html', 10],
                [2, '/en/product/herb-house/herbal-bitters.html', 10],
            ],
            $this->recorded(),
        );
    }

    // ── Translated alias ────────────────────────────────────────────────

    public function testEinErsterUebersetzterAliasZeichnetNichtsAuf(): void
    {
        /*
         * Without a translated alias the product was invisible in this language
         * (VisibilityCriteria::translated()). The address comes into being, it does
         * not move — the fallback address with the empty alias never existed.
         */
        $this->connection->delete('tl_consho_translation', ['pid' => 10, 'ptable' => 'tl_consho_product']);

        $translations = $this->translationListener();
        $translations->save('swedish-bitters', $this->dc(10, 'alias__en'));
        $this->submit(10, [], $translations);

        self::assertSame([], $this->recorded());
        self::assertSame('swedish-bitters', (new TranslationStorage($this->connection))->read('tl_consho_product', 10, 'en', 'alias'));
    }

    public function testEinGeleerterUebersetzterAliasZeichnetNichtsAuf(): void
    {
        // Without an alias the product disappears from the language; a redirect
        // would lead to an address that answers 404.
        $this->storeAndRecord(10, function (): void {
            $this->connection->update('tl_consho_translation', ['alias' => ''], ['pid' => 10, 'ptable' => 'tl_consho_product', 'language' => 'en']);
        });

        self::assertSame([], $this->recorded());
    }

    public function testEinUebersetzterAliasInDerStandardspracheZeichnetNichtsAuf(): void
    {
        // In the default language every start point shows the record's alias.
        $this->insertTranslation(['pid' => 10, 'ptable' => 'tl_consho_product', 'language' => 'de', 'title' => 'Schwedenbitter', 'alias' => 'schwedenbitter-de']);

        $this->storeAndRecord(10, function (): void {
            $this->connection->update('tl_consho_translation', ['alias' => 'schwedenbitter-neu'], ['pid' => 10, 'ptable' => 'tl_consho_product', 'language' => 'de']);
        });

        self::assertSame([], $this->recorded());
    }

    // ── Product type ────────────────────────────────────────────────────

    public function testEinProdukttypwechselZeichnetNurAufStartpunktenMitTypAuf(): void
    {
        $this->submit(10, ['productType' => 4]);

        self::assertSame([[3, '/artikel/tinktur/sb-100/schwedenbitter.html', 10]], $this->recorded());
    }

    public function testEinProdukttypwechselOhneTypInDerVorlageZeichnetNichtsAuf(): void
    {
        $this->connection->update('tl_page', ['conshoPathTemplate' => '{sku}/{alias}'], ['id' => 125]);

        $this->submit(10, ['productType' => 4]);

        self::assertSame([], $this->recorded());
    }

    // ── SKU ─────────────────────────────────────────────────────────────

    public function testEineGeaenderteSkuZeichnetBeiSkuInDerVorlageAuf(): void
    {
        $this->submit(10, ['sku' => 'sb-101']);

        self::assertSame([[3, '/artikel/tinktur/sb-100/schwedenbitter.html', 10]], $this->recorded());
    }

    public function testEineGeaenderteSkuOhneSkuInDerVorlageZeichnetNichtsAuf(): void
    {
        $this->connection->update('tl_page', ['conshoPathTemplate' => '{type}/{alias}'], ['id' => 125]);

        $this->submit(10, ['sku' => 'sb-101']);

        self::assertSame([], $this->recorded());
    }

    public function testEineGeleerteSkuLaesstDasSegmentWegUndLeitetDieAlteAdresseWeiter(): void
    {
        /*
         * A template of {sku} alone used to be allowed: without a number only
         * the bare product page remained, and nothing was recorded.
         * Since {alias} is required, the empty segment drops out — the address
         * moves, as with a product without a manufacturer.
         */
        $this->connection->update('tl_page', ['conshoPathTemplate' => '{sku}/{alias}'], ['id' => 125]);

        $this->submit(10, ['sku' => '']);

        self::assertSame([[3, '/artikel/sb-100/schwedenbitter.html', 10]], $this->recorded());
    }

    // ── Variants ────────────────────────────────────────────────────────

    public function testEineGeaenderteSkuEinerVarianteZeichnetNichtsAuf(): void
    {
        // {sku} is the SKU of the main product: variants have no address.
        $this->submit(13, ['sku' => 'sb-500']);

        self::assertSame([], $this->recorded());
    }

    public function testEineVarianteDieZumHauptproduktWirdHatteKeineAdresse(): void
    {
        // pid and SKU in the same save: there never was an address under sb-250.
        $this->submit(13, ['pid' => 0, 'sku' => 'sb-500']);

        self::assertSame([], $this->recorded());
    }

    public function testEinHauptproduktDasZurVarianteWirdZeichnetNichtsAuf(): void
    {
        // The address disappears, it does not move — a redirect would lead to a variant.
        $this->submit(10, ['pid' => 13, 'sku' => 'sb-101']);

        self::assertSame([], $this->recorded());
    }

    // ── Same value ──────────────────────────────────────────────────────

    public function testDieselbenWerteGespeichertZeichnenNichtsAuf(): void
    {
        // "Mehrere bearbeiten → Überschreiben" (edit multiple → overwrite) writes every selected field, even unchanged.
        $this->submit(10, ['manufacturer' => 7, 'productType' => 3, 'sku' => 'sb-100', 'alias' => 'schwedenbitter']);

        self::assertSame([], $this->recorded());
    }

    // ── Mehrere Teile in einem Speichervorgang ──────────────────────────

    public function testAliasUndHerstellerInEinemSpeichervorgangErgebenDieVollstaendigAlteAdresse(): void
    {
        /*
         * The half-new address /produkt/kraeuterhaus/neu.html is neither old
         * nor new. Here it is an earlier address of the product with its own
         * row — that one must stay. The fully new one goes.
         */
        $this->redirect(1, '/produkt/kraeuterhaus/neu.html', 10);
        $this->redirect(1, '/produkt/bienenhof/neu.html', 10);

        $this->submit(10, ['alias' => 'neu', 'manufacturer' => 8]);

        self::assertSame(
            [
                [1, '/produkt/kraeuterhaus/neu.html', 10],
                [1, '/produkt/kraeuterhaus/schwedenbitter.html', 10],
                [2, '/en/product/kraeuterhaus/herbal-bitters.html', 10],
                // Shop 2 has the alias in the template (it is required).
                [3, '/artikel/tinktur/sb-100/schwedenbitter.html', 10],
            ],
            $this->recorded(),
        );
    }

    public function testUebersetzterAliasUndHerstellerInEinemSpeichervorgangErgebenDieVollstaendigAlteAdresse(): void
    {
        /*
         * The translation is only written in onsubmit, AFTER the UPDATE of the
         * manufacturer. Compared with the database at that moment, the result would be
         * /en/product/bienenhof/herbal-bitters.html — an address that never existed.
         */
        $translations = $this->translationListener();
        $translations->save('swedish-bitters', $this->dc(10, 'alias__en'));

        $this->submit(10, ['manufacturer' => 8], $translations);

        self::assertSame(
            [
                [1, '/produkt/kraeuterhaus/schwedenbitter.html', 10],
                [2, '/en/product/kraeuterhaus/herbal-bitters.html', 10],
            ],
            $this->recorded(),
        );
        self::assertSame('swedish-bitters', (new TranslationStorage($this->connection))->read('tl_consho_product', 10, 'en', 'alias'));
    }

    public function testSkuUndTypInEinemSpeichervorgangErgebenEineZeile(): void
    {
        $this->submit(10, ['sku' => 'sb-101', 'productType' => 4]);

        self::assertSame([[3, '/artikel/tinktur/sb-100/schwedenbitter.html', 10]], $this->recorded());
    }

    // ── Restoring a version ─────────────────────────────────────────────

    public function testEineWiederhergestellteVersionMitAnderemHerstellerZeichnetAuf(): void
    {
        $listener = $this->restoreListener();
        $listener->onLoad($this->dc(10));

        // Versions::restore(): first the UPDATE, then onrestore_version_callback.
        $this->connection->update('tl_consho_product', ['manufacturer' => 8], ['id' => 10]);
        $listener->onRestoreVersion('tl_consho_product', 10, 2, []);

        self::assertSame(
            [
                [1, '/produkt/kraeuterhaus/schwedenbitter.html', 10],
                [2, '/en/product/kraeuterhaus/herbal-bitters.html', 10],
            ],
            $this->recorded(),
        );
    }

    public function testEineWiederhergestellteVersionMitAliasUndHerstellerZeichnetDieVollstaendigAlteAdresseAuf(): void
    {
        $listener = $this->restoreListener();
        $listener->onLoad($this->dc(10));

        $this->connection->update('tl_consho_product', ['alias' => 'schwedenbitter-alt', 'manufacturer' => 8, 'sku' => 'sb-099'], ['id' => 10]);
        $listener->onRestoreVersion('tl_consho_product', 10, 2, []);

        self::assertSame(
            [
                [1, '/produkt/kraeuterhaus/schwedenbitter.html', 10],
                [2, '/en/product/kraeuterhaus/herbal-bitters.html', 10],
                [3, '/artikel/tinktur/sb-100/schwedenbitter.html', 10],
            ],
            $this->recorded(),
        );
    }

    // ── The wiring ──────────────────────────────────────────────────────

    public function testDieCallbacksHaengenVorUndNachDemSchreibenDerUebersetzungen(): void
    {
        /*
         * DC_Table::submit(): onbeforesubmit, UPDATE, onsubmit. Contao puts
         * callbacks with priority > 0 in front, ≤ 0 in descending order behind the
         * others (DataContainerCallbackListener::addCallbacks()) —
         * TranslationFieldsListener::store() has 0 and so sits between the two
         * onsubmit callbacks.
         */
        self::assertSame([['tl_consho_product', 'config.onbeforesubmit', 0]], $this->callbacksOf('rememberBeforeUpdate'));
        self::assertSame([['tl_consho_product', 'config.onsubmit', 1]], $this->callbacksOf('rememberBeforeTranslations'));
        self::assertSame([['tl_consho_product', 'config.onsubmit', -1]], $this->callbacksOf('recordMovedAddresses'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * What DC_Table::submit() does for a record. Without a changed column
     * there is no UPDATE and no onbeforesubmit.
     *
     * @param array<string, int|string> $columns
     */
    private function submit(int $id, array $columns, ?TranslationFieldsListener $translations = null): void
    {
        $listener = $this->addressListener();
        $dc = $this->dc($id);

        if ([] !== $columns) {
            $columns = $listener->rememberBeforeUpdate($columns, $dc);
            $this->connection->update('tl_consho_product', $columns, ['id' => $id]);
        }

        $listener->rememberBeforeTranslations($dc);
        $translations?->store($dc);
        $listener->recordMovedAddresses($dc);
    }

    /**
     * A write path without a mask, such as writing back a translation:
     * state before, $write, a comparison afterwards.
     *
     * @param \Closure(): void $write
     */
    private function storeAndRecord(int $id, \Closure $write): void
    {
        $recorder = $this->recorder();
        $before = $recorder->productAddressState($id);
        self::assertNotNull($before);

        $write();
        $recorder->recordProductAddress($id, $before, self::NOW);
    }

    /**
     * @return list<array{string, string, int}>
     */
    private function callbacksOf(string $method): array
    {
        return array_map(
            static function (\ReflectionAttribute $attribute): array {
                $arguments = $attribute->getArguments();

                return [(string) $arguments['table'], (string) $arguments['target'], (int) ($arguments['priority'] ?? 0)];
            },
            (new \ReflectionMethod(ProductAddressListener::class, $method))->getAttributes(AsCallback::class),
        );
    }

    private function recorder(): RedirectRecorder
    {
        return new RedirectRecorder(
            $this->connection,
            new DbalProductPageRepository($this->connection),
            new ShopLanguages($this->connection),
        );
    }

    private function addressListener(): ProductAddressListener
    {
        return new ProductAddressListener($this->recorder());
    }

    private function resolver(): DbalRedirectResolver
    {
        return new DbalRedirectResolver($this->connection);
    }

    private function translationListener(): TranslationFieldsListener
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);

        return new TranslationFieldsListener(
            new ShopLanguages($this->connection),
            new TranslationStorage($this->connection),
            new AliasFactory($this->createMock(Slug::class), $this->connection),
            $security,
            $this->recorder(),
        );
    }

    private function restoreListener(): AliasRestoreListener
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/contao?do=consho_product&act=edit&id=10', 'POST', ['FORM_SUBMIT' => 'tl_version', 'version' => '2']));

        return new AliasRestoreListener($this->connection, $requests, $this->recorder());
    }

    /**
     * Reports for which shop and which language the one product is queried.
     *
     * @param \Closure(int, string): void $onQuery
     */
    private function countingRepository(\Closure $onQuery): ProductPageRepositoryInterface
    {
        return new class(new DbalProductPageRepository($this->connection), $onQuery) implements ProductPageRepositoryInterface {
            /**
             * @param \Closure(int, string): void $onQuery
             */
            public function __construct(
                private readonly DbalProductPageRepository $inner,
                private readonly \Closure $onQuery,
            ) {
            }

            public function findForRootPage(int $rootPageId): ?int
            {
                return $this->inner->findForRootPage($rootPageId);
            }

            public function urlValues(int $productId, string $language = '', string $fallback = ''): array
            {
                return $this->inner->urlValues($productId, $language, $fallback);
            }

            public function rootPageOf(int $pageId): ?int
            {
                return $this->inner->rootPageOf($pageId);
            }

            public function urlValuesForShop(int $shopId, string $language = '', string $fallback = ''): array
            {
                return $this->inner->urlValuesForShop($shopId, $language, $fallback);
            }

            public function urlValuesForProductInShop(int $productId, int $shopId, string $language = '', string $fallback = ''): array
            {
                ($this->onQuery)($shopId, $language);

                return $this->inner->urlValuesForProductInShop($productId, $shopId, $language, $fallback);
            }

            public function urlValuesForManufacturerInShop(int $manufacturerId, int $shopId, string $language = '', string $fallback = ''): array
            {
                return $this->inner->urlValuesForManufacturerInShop($manufacturerId, $shopId, $language, $fallback);
            }

            public function urlValuesForProductTypeInShop(int $productTypeId, int $shopId, string $language = '', string $fallback = ''): array
            {
                return $this->inner->urlValuesForProductTypeInShop($productTypeId, $shopId, $language, $fallback);
            }
        };
    }

    private function dc(int $id, string $field = ''): DC_Table
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnMap([['id', $id], ['table', 'tl_consho_product'], ['field', $field]]);
        $dc->method('getCurrentRecord')->willReturn([]);

        return $dc;
    }

    /**
     * @return list<array{int, string, int}>
     */
    private function recorded(): array
    {
        return array_map(
            static fn (array $row): array => [(int) $row['pid'], (string) $row['oldPath'], (int) $row['productId']],
            $this->connection->fetchAllAssociative('SELECT pid, oldPath, productId FROM tl_consho_url_redirect ORDER BY id'),
        );
    }

    private function redirect(int $rootId, string $path, int $productId): void
    {
        $this->connection->insert('tl_consho_url_redirect', ['pid' => $rootId, 'oldPath' => $path, 'productId' => $productId, 'createdAt' => 1]);
    }

    private function page(
        int $id,
        int $pid = 0,
        string $alias = '',
        string $type = 'regular',
        string $language = '',
        int $shop = 0,
        string $prefix = '',
        string $template = '',
    ): void {
        $this->connection->insert('tl_page', [
            'id' => $id,
            'pid' => $pid,
            'alias' => $alias,
            'type' => $type,
            'published' => 1,
            'urlPrefix' => $prefix,
            'urlSuffix' => 'root' === $type ? '.html' : '',
            'language' => $language,
            'consho_shop' => $shop,
            'conshoPathTemplate' => $template,
        ]);
    }

    private function product(int $id, string $alias, string $sku, int $pid = 0): void
    {
        $this->insertProduct(['id' => $id, 'pid' => $pid, 'title' => $alias, 'alias' => $alias, 'sku' => $sku, 'manufacturer' => 7, 'productType' => 3, 'published' => 1]);
        $this->insertProductShop(['pid' => $id, 'shop' => 1]);
        $this->insertProductShop(['pid' => $id, 'shop' => 2]);
    }
}

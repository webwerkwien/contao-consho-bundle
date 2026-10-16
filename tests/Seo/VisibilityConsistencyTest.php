<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlDecoder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSort;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTaxClasses;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalArticleTree;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalMarketRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Seo\ProductAddressLookup;
use Webwerkwien\ContaoConshoBundle\Seo\ProductJsonLd;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Pricing\SqliteProjectionWriter;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Sitemap, Detailseite und JSON-LD melden dieselben Produkte (Plan B-6,
 * Entscheidung 5; Spezifikation 6.6).
 *
 * Verglichen werden drei VERSCHIEDENE Wege, nicht derselbe zweimal:
 *
 * | A | ProductQuery::find() — SQL gegen die Projektion der Sichtbarkeit, genau das, was SitemapListener aufruft (SitemapListenerTest belegt den Aufruf) |
 * | B | ProductAddressLookup::isVisible() — die Detailseite antwortet 200 — und ein Preis aus ArticlePriceResolver gegen die Preistabelle, bei Varianten über VariantRepository |
 * | C | B, aber gefragt, ob ProductJsonLd daraus ein Angebot baut — so, wie ProductDetailController es aufruft |
 *
 * Geprüft wird nicht gegen eine erwartete Liste. Eine Liste müsste jemand
 * pflegen und würde beim nächsten Grenzfall vergessen; ein Vergleich schlägt
 * fehl, sobald ein Weg abweicht — auch bei einem Fall, an den beim Schreiben
 * niemand gedacht hat, sobald er im Katalog steht.
 *
 * Die Listenpreis-Projektion schreibt der echte Projektor aus tl_consho_price,
 * die Projektion der Sichtbarkeit (B-10) der echte Projektor daraus.
 */
class VisibilityConsistencyTest extends TestCase
{
    use CatalogSchema;
    use TaxFixture;

    private const NOW = 1_790_000_000;
    private const SHOP = 1;
    private const OTHER_SHOP = 2;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        // ShopLanguages liest die Sprachen aus den Startpunkten.
        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY, type TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, consho_currency TEXT NOT NULL DEFAULT '')",
        );
        // Seit C-1c hängt der Markt (EUR, AT) an diesen Startpunkten, nicht mehr
        // an der Währungsspalte des Shops.
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'language' => 'de', 'consho_shop' => self::SHOP, 'consho_currency' => 'EUR']);
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'language' => 'fr', 'consho_shop' => self::SHOP, 'consho_currency' => 'EUR']);
        $this->insertShop(['id' => self::SHOP, 'title' => 'Shop', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertShop(['id' => self::OTHER_SHOP, 'title' => 'Anderer Shop', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);

        $this->catalogue();

        $prices = new DbalPriceRepository($this->connection);
        (new ListPriceProjector($prices, $this->resolver(), new SqliteProjectionWriter($this->connection), new DbalArticleTree($this->connection), new DbalMarketRepository($this->connection)))->rebuild(self::NOW);

        (new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        ))->rebuild(self::NOW);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function languages(): iterable
    {
        yield 'Standardsprache' => ['de'];
        yield 'Übersetzung' => ['fr'];
    }

    #[DataProvider('languages')]
    public function testSitemapProductPageAndJsonLdReportTheSameProducts(string $language): void
    {
        $sitemap = $this->sitemap($language);
        $page = $this->pageWithPrice($language);
        $jsonLd = $this->jsonLd($language);

        // Der Vergleich misst nur etwas, wenn der Katalog beide Seiten hat.
        self::assertNotEmpty($sitemap, 'Kein einziges Produkt sichtbar — der Aufbau ist kaputt, nicht die Regel.');
        self::assertLessThan(\count($this->mainProducts()), \count($sitemap), 'Kein Produkt ausgeschlossen — die Grenzfälle greifen nicht.');

        self::assertSame($page, $sitemap, 'Sitemap (SQL) und Detailseite mit Preis (Resolver) weichen ab.');
        self::assertSame($jsonLd, $sitemap, 'Sitemap und JSON-LD weichen ab.');
    }

    public function testTheTranslationLanguageReallyHidesMore(): void
    {
        // Sonst prüfte der fr-Fall dieselbe Menge wie de, und die Sprachregeln stünden ungeprüft da.
        self::assertLessThan(\count($this->sitemap('de')), \count($this->sitemap('fr')));
    }

    /**
     * Die Grenzfälle, alle in Shop 1.
     */
    private function catalogue(): void
    {
        // 1: ohne Varianten, sichtbar, übersetzt
        $this->main(1, price: '12.0000', fr: true);

        // 2: unveröffentlicht
        $this->main(2, price: '12.0000', fr: true, overrides: ['published' => 0]);

        // 3: eine aktive Variante mit Preis, eine inaktive günstigere
        $this->main(3, fr: true);
        $this->variant(31, of: 3, price: '15.0000');
        $this->variant(32, of: 3, price: '5.0000', overrides: ['published' => 0]);

        // 4: nur inaktive Varianten — auch mit Preis unsichtbar (Regel 3)
        $this->main(4, price: '9.0000', fr: true);
        $this->variant(41, of: 4, price: '9.0000', overrides: ['published' => 0]);

        // 5: die einzige Variante ist auf einen fremden Shop eingeschränkt (Regel 1)
        $this->main(5, price: '9.0000', fr: true);
        $this->variant(51, of: 5, price: '9.0000');
        $this->insertProductShop(['pid' => 51, 'shop' => self::OTHER_SHOP]);

        // 6: sichtbar, aber ohne Preis — die Seite antwortet 200, gemeldet wird es nicht (E1, E3)
        $this->main(6, fr: true);

        // 7: Zeitfenster beginnt erst
        $this->main(7, price: '12.0000', fr: true, overrides: ['start' => (string) (self::NOW + 3600)]);

        // 8: Variante ohne eigenen Preis erbt den des Hauptprodukts (A-4b)
        $this->main(8, price: '13.0000', fr: true);
        $this->variant(81, of: 8);

        // 9: nur im anderen Shop
        $this->main(9, price: '12.0000', fr: true, shop: self::OTHER_SHOP);

        // 10: Warnhinweis in de, Übersetzung ohne Warnhinweis — in fr unsichtbar (Regel 4)
        $this->main(10, price: '12.0000', overrides: ['safetyWarnings' => '<p>Enthält Alkohol.</p>']);
        $this->insertTranslation(['ptable' => 'tl_consho_product', 'pid' => 10, 'language' => 'fr', 'title' => 'Dix', 'alias' => 'dix']);

        // 11: nicht übersetzt — in fr unsichtbar
        $this->main(11, price: '12.0000');

        // 12: eine Variante mit eigenem, unübersetztem Warnhinweis, eine ohne — in fr bleibt die zweite
        $this->main(12, fr: true);
        $this->variant(121, of: 12, price: '20.0000', overrides: ['safetyWarnings' => '<p>Eigener Hinweis.</p>']);
        $this->variant(122, of: 12, price: '25.0000');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function main(int $id, string|null $price = null, bool $fr = false, int $shop = self::SHOP, array $overrides = []): void
    {
        $this->insertProduct(['id' => $id, 'pid' => 0, 'title' => 'Produkt '.$id, 'alias' => 'produkt-'.$id, 'published' => 1, ...$overrides]);
        $this->insertProductShop(['pid' => $id, 'shop' => $shop]);

        if (null !== $price) {
            $this->insertPrice(['pid' => $id, 'currency' => 'EUR', 'net' => $price]);
        }

        if ($fr) {
            $this->insertTranslation(['ptable' => 'tl_consho_product', 'pid' => $id, 'language' => 'fr', 'title' => 'Produit '.$id, 'alias' => 'produit-'.$id]);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function variant(int $id, int $of, string|null $price = null, array $overrides = []): void
    {
        $this->insertProduct(['id' => $id, 'pid' => $of, 'title' => 'Variante '.$id, 'published' => 1, ...$overrides]);

        if (null !== $price) {
            $this->insertPrice(['pid' => $id, 'currency' => 'EUR', 'net' => $price]);
        }
    }

    /**
     * A: was die Sitemap meldet.
     *
     * @return list<int>
     */
    private function sitemap(string $language): array
    {
        $ids = (new ProductQuery($this->connection))
            ->find(self::SHOP, [], $language, 'EUR', 'de', ProductSort::TITLE)
        ;

        sort($ids);

        return $ids;
    }

    /**
     * B: die Detailseite antwortet 200 und hat einen Preis.
     *
     * @return list<int>
     */
    private function pageWithPrice(string $language): array
    {
        return array_values(array_filter(
            $this->mainProducts(),
            fn (int $id): bool => $this->visible($id, $language) && [] !== $this->articlePrices($id, $language),
        ));
    }

    /**
     * C: die Detailseite antwortet 200, und ProductJsonLd baut ein Angebot —
     * mit denselben Eingaben wie ProductDetailController.
     *
     * @return list<int>
     */
    private function jsonLd(string $language): array
    {
        $insertTags = $this->createMock(InsertTagParser::class);
        $insertTags->method('replaceInline')->willReturnArgument(0);
        $builder = new ProductJsonLd(new ResponseContextAccessor(new RequestStack()), new HtmlDecoder($insertTags));

        return array_values(array_filter(
            $this->mainProducts(),
            function (int $id) use ($builder, $language): bool {
                if (!$this->visible($id, $language)) {
                    return false;
                }

                $matrix = (new VariantRepository($this->connection, new VisibilityCriteria()))->matrixFor($id, self::SHOP, self::NOW, $language, 'de');
                $resolver = $this->resolver();
                $units = ['unit' => '', 'purchaseUnit' => '0', 'referenceUnit' => '0'];

                $price = $matrix->needsChoice()
                    ? null
                    : $resolver->forArticle($matrix->selectedId() ?? $id, $id, 'EUR', 'AT', self::NOW, $units)?->gross;

                $variantPrices = $matrix->needsChoice()
                    ? array_map(static fn (ArticlePrice $p): string => $p->gross, $this->articlePrices($id, $language))
                    : [];

                return null !== $builder->build('Produkt '.$id, '', '', '', '', '', '', $price, $variantPrices, 'EUR', 'https://example.org/p', []);
            },
        ));
    }

    private function visible(int $id, string $language): bool
    {
        return (new ProductAddressLookup($this->connection, new VisibilityCriteria()))->isVisible($id, self::SHOP, self::NOW, $language, 'de');
    }

    /**
     * Die Preise der Artikel, die auf der Seite gekauft werden könnten: der
     * sichtbaren Varianten, ohne Varianten des Produkts selbst.
     *
     * @return list<ArticlePrice>
     */
    private function articlePrices(int $id, string $language): array
    {
        $matrix = (new VariantRepository($this->connection, new VisibilityCriteria()))->matrixFor($id, self::SHOP, self::NOW, $language, 'de');
        $hasVariants = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product WHERE pid = ?', [$id]) > 0;
        $articles = $hasVariants ? $matrix->variantIds() : [$id];
        $units = ['unit' => '', 'purchaseUnit' => '0', 'referenceUnit' => '0'];

        return array_values(array_filter(array_map(
            fn (int $articleId): ArticlePrice|null => $this->resolver()->forArticle($articleId, $id, 'EUR', 'AT', self::NOW, $units),
            $articles,
        )));
    }

    /**
     * @return list<int>
     */
    private function mainProducts(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_product WHERE pid = 0 ORDER BY id'));
    }

    private function resolver(): ArticlePriceResolver
    {
        $prices = new DbalPriceRepository($this->connection);

        return new ArticlePriceResolver(new PriceCalculator($prices), $prices, new BasePriceCalculator(), $this->grossCalculator(), new DbalArticleTaxClasses($this->connection));
    }
}

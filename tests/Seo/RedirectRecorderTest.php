<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Seo\DbalProductPageRepository;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPathTemplate;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class RedirectRecorderTest extends TestCase
{
    private const NOW = 1_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            'CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                alias TEXT NOT NULL DEFAULT "",
                type TEXT NOT NULL DEFAULT "regular",
                published INTEGER NOT NULL DEFAULT 0,
                urlPrefix TEXT NOT NULL DEFAULT "",
                urlSuffix TEXT NOT NULL DEFAULT "",
                language TEXT NOT NULL DEFAULT "",
                consho_shop INTEGER NOT NULL DEFAULT 0,
                consho_currency TEXT NOT NULL DEFAULT ""
            )',
        );
        // From the DCA; the translation is needed so urlValuesForShop() can
        // join one onto a product.
        foreach (['tl_consho_product', 'tl_consho_product_shop', 'tl_consho_manufacturer', 'tl_consho_product_type', 'tl_consho_shop', 'tl_consho_translation', 'tl_consho_url_redirect'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        // A site with a shop, a product page below it, and two products.
        $this->page(1, type: 'root', suffix: '.html', shop: 1);
        $this->page(123, pid: 1, alias: 'produkt', type: 'consho_product');
        $this->connection->insert('tl_consho_manufacturer', ['id' => 7, 'alias' => 'kraeuterhaus']);
        $this->product(10, 'schwedenbitter', manufacturer: 7);
        $this->product(11, 'melissengeist');
    }

    public function testRecordsOneAddressPerProduct(): void
    {
        $written = $this->recorder()->record(123, ProductPathTemplate::fromString('{alias}'), self::NOW);

        self::assertSame(2, $written);
        self::assertSame(
            ['/produkt/schwedenbitter.html' => 10, '/produkt/melissengeist.html' => 11],
            $this->recorded(),
        );
    }

    public function testTheAddressCarriesPrefixAndSuffixExactlyAsARequestDoes(): void
    {
        /*
         * Leaving the suffix off would file "/produkt/schwedenbitter" for a
         * site that only ever answered "/produkt/schwedenbitter.html" — a table
         * of redirects that never match anything.
         */
        $this->connection->update('tl_page', ['urlPrefix' => 'de'], ['id' => 1]);

        $this->recorder()->record(123, ProductPathTemplate::fromString('{alias}'), self::NOW);

        self::assertArrayHasKey('/de/produkt/schwedenbitter.html', $this->recorded());
    }

    public function testUsesTheTemplateItIsGivenNotTheOneOnThePage(): void
    {
        // The point of passing it in: what has to be recorded is yesterday's
        // pattern, and by the time anybody asks the page it is already today's.
        $this->recorder()->record(123, ProductPathTemplate::fromString('{manufacturer}/{alias}'), self::NOW);

        $recorded = $this->recorded();

        self::assertArrayHasKey('/produkt/kraeuterhaus/schwedenbitter.html', $recorded);
        // No manufacturer on that one, so the segment falls away.
        self::assertArrayHasKey('/produkt/melissengeist.html', $recorded);
    }

    public function testRecordingTheSamePatternTwiceChangesNothing(): void
    {
        $template = ProductPathTemplate::fromString('{alias}');

        self::assertSame(2, $this->recorder()->record(123, $template, self::NOW));
        self::assertSame(0, $this->recorder()->record(123, $template, self::NOW + 10));
        self::assertCount(2, $this->recorded());
    }

    public function testKeepsBothGenerationsWhenThePatternChangesTwice(): void
    {
        /*
         * Both point at the same product, which is why the table holds
         * productId and not a successor path — otherwise the first generation
         * would resolve to the second and redirect again.
         */
        $this->recorder()->record(123, ProductPathTemplate::fromString('{alias}'), self::NOW);
        $this->recorder()->record(123, ProductPathTemplate::fromString('{manufacturer}/{alias}'), self::NOW + 10);

        $recorded = $this->recorded();

        self::assertSame(10, $recorded['/produkt/schwedenbitter.html']);
        self::assertSame(10, $recorded['/produkt/kraeuterhaus/schwedenbitter.html']);
    }

    public function testRecordsUnpublishedProductsToo(): void
    {
        // Publication changes over time, the shape of the address did not. A
        // product switched on again next week still needs its old address.
        $this->connection->update('tl_consho_product', ['published' => 0], ['id' => 10]);

        self::assertSame(2, $this->recorder()->record(123, ProductPathTemplate::fromString('{alias}'), self::NOW));
    }

    public function testIgnoresProductsOfAnotherShop(): void
    {
        $this->product(12, 'fremdartikel', shop: 2);

        self::assertSame(2, $this->recorder()->record(123, ProductPathTemplate::fromString('{alias}'), self::NOW));
    }

    public function testIgnoresVariants(): void
    {
        // A variant has no address of its own, so it has none to remember.
        $this->product(13, 'schwedenbitter-250', pid: 10);

        self::assertSame(2, $this->recorder()->record(123, ProductPathTemplate::fromString('{alias}'), self::NOW));
    }

    public function testDoesNothingForAPageOutsideAShop(): void
    {
        $this->page(2, type: 'root', suffix: '.html');
        $this->page(200, pid: 2, alias: 'produkt', type: 'consho_product');

        self::assertSame(0, $this->recorder()->record(200, ProductPathTemplate::fromString('{alias}'), self::NOW));
    }

    public function testDoesNothingForAPageThatDoesNotExist(): void
    {
        self::assertSame(0, $this->recorder()->record(999, ProductPathTemplate::fromString('{alias}'), self::NOW));
    }

    // ── Language (M-3, Aufgabe 4) ───────────────────────────────────────

    public function testItRecordsThePathsOfTheRootPagesOwnLanguage(): void
    {
        // Two startpoints on the same shop, one de, one en — the case Aufgabe
        // 4 exists for. Recording for the English startpoint must write the
        // English alias, not the German one.
        $this->connection->update('tl_page', ['language' => 'de'], ['id' => 1]);
        $this->page(2, type: 'root', suffix: '.html', shop: 1, language: 'en');
        $this->page(124, pid: 2, alias: 'produkt', type: 'consho_product');
        $this->translate(10, 'en', alias: 'herbal-bitters');

        $this->recorder()->record(124, ProductPathTemplate::fromString('{alias}'), self::NOW);

        $recorded = $this->recorded();

        self::assertArrayHasKey('/produkt/herbal-bitters.html', $recorded);
        self::assertArrayNotHasKey('/produkt/schwedenbitter.html', $recorded);
    }

    public function testTheGermanRootStillRecordsGermanPaths(): void
    {
        // The Gegenprobe: without it, the first test would only show that
        // *something* changed, not that the right language was picked.
        $this->connection->update('tl_page', ['language' => 'de'], ['id' => 1]);
        $this->page(2, type: 'root', suffix: '.html', shop: 1, language: 'en');
        $this->page(124, pid: 2, alias: 'produkt', type: 'consho_product');
        $this->translate(10, 'en', alias: 'herbal-bitters');

        $this->recorder()->record(123, ProductPathTemplate::fromString('{alias}'), self::NOW);

        $recorded = $this->recorded();

        self::assertArrayHasKey('/produkt/schwedenbitter.html', $recorded);
        self::assertArrayNotHasKey('/produkt/herbal-bitters.html', $recorded);
    }

    public function testARecorderWithoutTranslationsIsUnchanged(): void
    {
        // Der Prüfstein: ein einsprachiger Shop — kein Startpunkt trägt eine
        // Sprache, keine Übersetzung existiert — zeichnet exakt auf wie vor
        // dieser Änderung.
        $written = $this->recorder()->record(123, ProductPathTemplate::fromString('{alias}'), self::NOW);

        self::assertSame(2, $written);
        self::assertSame(
            ['/produkt/schwedenbitter.html' => 10, '/produkt/melissengeist.html' => 11],
            $this->recorded(),
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

    /**
     * @return array<string, int>
     */
    private function recorded(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT oldPath, productId FROM tl_consho_url_redirect ORDER BY id');
        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['oldPath']] = (int) $row['productId'];
        }

        return $map;
    }

    private function page(int $id, int $pid = 0, string $alias = '', string $type = 'regular', string $suffix = '', int $shop = 0, string $language = ''): void
    {
        $this->connection->insert('tl_page', [
            'id' => $id,
            'pid' => $pid,
            'alias' => $alias,
            'type' => $type,
            'published' => 1,
            'urlSuffix' => $suffix,
            'consho_shop' => $shop,
            'language' => $language,
        ]);
    }

    private function translate(int $pid, string $language, ?string $alias, string $ptable = 'tl_consho_product'): void
    {
        $this->connection->insert('tl_consho_translation', [
            'pid' => $pid,
            'ptable' => $ptable,
            'language' => $language,
            'alias' => $alias,
        ]);
    }

    private function product(int $id, string $alias, int $pid = 0, int $manufacturer = 0, int $shop = 1): void
    {
        $this->connection->insert('tl_consho_product', [
            'id' => $id,
            'pid' => $pid,
            'alias' => $alias,
            'manufacturer' => $manufacturer,
            'published' => 1,
        ]);

        $this->connection->insert('tl_consho_product_shop', ['pid' => $id, 'shop' => $shop]);
    }
}

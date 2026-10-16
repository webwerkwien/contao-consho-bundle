<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Seo\DbalProductPageRepository;
use Webwerkwien\ContaoConshoBundle\Seo\ProductAddressLookup;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalProductPageRepositoryTest extends TestCase
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
                type TEXT NOT NULL DEFAULT "regular",
                published INTEGER NOT NULL DEFAULT 0
            )',
        );

        // From the DCA, as in ProductAddressLookupTest — the round-trip test in
        // this class needs both to agree on what a translation row looks like.
        foreach (['tl_consho_product', 'tl_consho_manufacturer', 'tl_consho_product_type', 'tl_consho_product_shop', 'tl_consho_translation'] as $table) {
            DcaSchema::create($this->connection, $table);
        }
    }

    public function testFindsTheProductPageBelowItsRootPage(): void
    {
        $this->page(1, type: 'root');
        $this->page(2, pid: 1);
        $this->page(3, pid: 2, type: 'consho_product');

        self::assertSame(3, $this->repository()->findForRootPage(1));
    }

    public function testTellsTwoSitesApart(): void
    {
        /*
         * The case that matters on a multilingual shop: two root pages, each
         * with its own product page. Returning "the" product page would give
         * every German product a French address half the time.
         */
        $this->page(1, type: 'root');
        $this->page(2, pid: 1, type: 'consho_product');
        $this->page(10, type: 'root');
        $this->page(11, pid: 10, type: 'consho_product');

        self::assertSame(2, $this->repository()->findForRootPage(1));
        self::assertSame(11, $this->repository()->findForRootPage(10));
    }

    public function testIgnoresAnUnpublishedProductPage(): void
    {
        $this->page(1, type: 'root');
        $this->page(2, pid: 1, type: 'consho_product', published: false);

        self::assertNull($this->repository()->findForRootPage(1));
    }

    public function testReturnsNullWhenTheSiteHasNoProductPage(): void
    {
        $this->page(1, type: 'root');
        $this->page(2, pid: 1);

        self::assertNull($this->repository()->findForRootPage(1));
    }

    public function testSurvivesAPageWhoseParentIsItself(): void
    {
        // Not a real tree, but a real database can hold one — and without the
        // depth guard the request would never end.
        $this->page(1, type: 'root');
        $this->page(2, pid: 2, type: 'consho_product');

        self::assertNull($this->repository()->findForRootPage(1));
    }

    public function testReadsTheFourUrlValues(): void
    {
        $this->connection->insert('tl_consho_manufacturer', ['id' => 7, 'alias' => 'kraeuterhaus']);
        $this->connection->insert('tl_consho_product_type', ['id' => 4, 'alias' => 'kraeuterbitter']);
        $this->connection->insert('tl_consho_product', [
            'id' => 1,
            'alias' => 'schwedenbitter',
            'sku' => 'SB-250',
            'manufacturer' => 7,
            'productType' => 4,
        ]);

        self::assertSame(
            ['alias' => 'schwedenbitter', 'sku' => 'SB-250', 'manufacturer' => 'kraeuterhaus', 'type' => 'kraeuterbitter'],
            $this->repository()->urlValues(1),
        );
    }

    public function testGivesEmptyStringsRatherThanNullForWhatIsMissing(): void
    {
        /*
         * ProductPathTemplate::render() drops a segment whose value is empty.
         * Handing it null would put the word "null" in an address, which is the
         * kind of thing nobody notices until it is indexed.
         */
        $this->connection->insert('tl_consho_product', ['id' => 1, 'alias' => 'schwedenbitter', 'sku' => '']);

        self::assertSame(
            ['alias' => 'schwedenbitter', 'sku' => '', 'manufacturer' => '', 'type' => ''],
            $this->repository()->urlValues(1),
        );
    }

    public function testReturnsNothingForAProductThatIsGone(): void
    {
        self::assertSame([], $this->repository()->urlValues(999));
    }

    // ── Language (M-3, Aufgabe 3) ───────────────────────────────────────

    public function testTheTranslatedAliasIsUsed(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 1, 'alias' => 'kraeuterbitter']);
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame('herbal-bitters', $this->repository()->urlValues(1, 'en', 'de')['alias']);
    }

    public function testAnUntranslatedManufacturerKeepsItsOwnAlias(): void
    {
        $this->connection->insert('tl_consho_manufacturer', ['id' => 7, 'alias' => 'kraeuterhaus']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'alias' => 'kraeuterbitter', 'manufacturer' => 7]);
        // No translation row for the manufacturer at all.

        self::assertSame('kraeuterhaus', $this->repository()->urlValues(1, 'en', 'de')['manufacturer']);
    }

    public function testTheSkuIsNeverTranslated(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 1, 'alias' => 'kraeuterbitter', 'sku' => 'SB-250']);
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame('SB-250', $this->repository()->urlValues(1, 'en', 'de')['sku']);
    }

    public function testWithoutALanguageNothingChanges(): void
    {
        // Der Prüfstein: ohne Sprachangabe exakt das Verhalten von vorher, auch
        // wenn eine Übersetzung existiert.
        $this->connection->insert('tl_consho_product', ['id' => 1, 'alias' => 'kraeuterbitter']);
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame(
            ['alias' => 'kraeuterbitter', 'sku' => '', 'manufacturer' => '', 'type' => ''],
            $this->repository()->urlValues(1),
        );
    }

    /**
     * Der Rundlauf: was der Erzeuger in eine Adresse schreibt, muss die Suche
     * wiederfinden. Mit einem Hersteller, der übersetzt ist (Produkt 1), und
     * einem zweiten, der es nicht ist (Produkt 2) — Produkt 2 ist der Fall,
     * der bei abweichenden Ausdrücken zwischen Erzeuger und Suche bricht.
     */
    public function testAGeneratedAddressIsFoundAgain(): void
    {
        $this->connection->insert('tl_consho_manufacturer', ['id' => 7, 'alias' => 'kraeuterhaus']);
        $this->connection->insert('tl_consho_manufacturer', ['id' => 8, 'alias' => 'schwedenkraft']);

        $this->product(1, alias: 'kraeuterbitter', manufacturer: 7);
        $this->translate(1, 'en', alias: 'herbal-bitters');
        $this->translate(7, 'en', alias: 'herb-house', ptable: 'tl_consho_manufacturer');

        $this->product(2, alias: 'schwedenbitter', manufacturer: 8);
        $this->translate(2, 'en', alias: 'swedish-bitters');
        // Herstellerübersetzung für Produkt 2 fehlt absichtlich.

        $repository = $this->repository();
        $lookup = new ProductAddressLookup($this->connection, new VisibilityCriteria());

        foreach ([1, 2] as $productId) {
            $values = $repository->urlValues($productId, 'en', 'de');

            $segments = ['manufacturer' => $values['manufacturer'], 'alias' => $values['alias']];

            self::assertSame(
                $productId,
                $lookup->find($segments, shopId: 1, now: self::NOW, language: 'en', fallback: 'de'),
                \sprintf('product %d was not found again through its generated address', $productId),
            );
        }
    }

    private function repository(): DbalProductPageRepository
    {
        return new DbalProductPageRepository($this->connection);
    }

    private function page(int $id, int $pid = 0, string $type = 'regular', bool $published = true): void
    {
        $this->connection->insert('tl_page', [
            'id' => $id,
            'pid' => $pid,
            'type' => $type,
            'published' => $published ? 1 : 0,
        ]);
    }

    private function product(
        int $id,
        string $alias = '',
        string $sku = '',
        int $manufacturer = 0,
        bool $published = true,
        int $shop = 1,
    ): void {
        $this->connection->insert('tl_consho_product', [
            'id' => $id,
            'pid' => 0,
            'alias' => $alias,
            'sku' => $sku,
            'manufacturer' => $manufacturer,
            'published' => $published ? 1 : 0,
        ]);

        $this->connection->insert('tl_consho_product_shop', ['pid' => $id, 'shop' => $shop]);
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
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Migration\VisibilityCurrencyMigration;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * The existing rows get the currency of their start point (C-1c, 5b).
 *
 * contao:migrate adds `currency` as '', and '' is not a currency: every
 * list asks for the currency of its start point and would find nothing.
 */
class VisibilityCurrencyMigrationTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testOhneDieTabellenLaeuftSieNicht(): void
    {
        // First pass of contao:migrate, before the schema update.
        self::assertFalse($this->migration()->shouldRun());
    }

    public function testOhneDieWaehrungsspalteLaeuftSieNicht(): void
    {
        // The first of the two passes: the column it is meant to fill does not
        // exist yet at all. Without this guard the pass would fail on an
        // unknown column instead of simply having nothing to do.
        $this->catalogue();
        $this->visibilityTableBefore5b();
        $this->storedRow();

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testOhneDieWaehrungsspalteAmStartpunktLaeuftSieNicht(): void
    {
        // The rebuild path reads the currency on the start points. If the
        // column is missing, shouldRun() must not say yes — the same guard as
        // in RootCurrencyMigration.
        $this->createCatalogSchema($this->connection);
        $this->rootPageTableBeforeC1c();
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'language' => 'de', 'consho_shop' => 1, 'published' => 1]);
        $this->storedRow();

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testDieBestehendenZeilenBekommenDieWaehrungIhresStartpunkts(): void
    {
        $this->catalogue();
        $this->storedRow();

        $migration = $this->migration();

        self::assertTrue($migration->shouldRun());
        self::assertTrue($migration->run()->isSuccessful());
        self::assertSame(['EUR'], $this->currencies());
        self::assertFalse($migration->shouldRun());
    }

    public function testEinZweiterDurchlaufAendertNichts(): void
    {
        // The criterion is a data state, not a "has run" marker:
        // after filling there is no row without a currency any more.
        $this->catalogue();
        $this->storedRow();
        $this->migration()->run();

        self::assertFalse($this->migration()->shouldRun());
        self::assertSame(['EUR'], $this->currencies());
    }

    public function testZweiWaehrungenInEinerSpracheWerdenNeuProjiziertStattGeraten(): void
    {
        // The DACH case. Nobody knows which of the two currencies the stored
        // row means — so it is computed instead of guessed. Product 1 is only
        // priced in euros and afterwards only stands under EUR.
        $this->catalogue();
        $this->insertRootPage(2, 'de', 1, 'CHF');
        $this->pricedProduct(1);
        $this->storedRow();

        self::assertTrue($this->migration()->shouldRun());
        $this->migration()->run();

        self::assertSame(
            [['language' => 'de', 'currency' => 'EUR', 'pid' => 1]],
            $this->rows(),
        );
        self::assertFalse($this->migration()->shouldRun());
    }

    public function testEineEindeutigeSpracheNebenEinerMehrdeutigenWirdNichtUmsonstGefuellt(): void
    {
        // Both paths meet in one run: French is unambiguous (euro only),
        // German ambiguous (euro and franc). If the loop filled the
        // unambiguous case along the way, the rebuild would rewrite every row
        // afterwards anyway — wasted work, and the message would speak of a
        // split that did not happen.
        $this->catalogue();
        $this->insertRootPage(2, 'fr', 1);
        $this->insertRootPage(3, 'de', 1, 'CHF');
        $this->pricedProduct(1);
        $this->storedRow();
        $this->connection->insert('tl_consho_product_visibility', ['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 1, 'language' => 'fr', 'net' => '10.0000', 'gross' => '12.0000']);

        $result = $this->migration()->run();

        self::assertStringStartsWith('Rebuilt the visibility projection', (string) $result->getMessage());
        // Product 1 is untranslated and only priced in euros: one row.
        self::assertSame([['language' => 'de', 'currency' => 'EUR', 'pid' => 1]], $this->rows());
        self::assertFalse($this->migration()->shouldRun());
    }

    public function testZeilenOhneStartpunktWerdenNichtGeratenSondernAbgeraeumt(): void
    {
        // Shop 9 no longer exists; its rows have no currency from which
        // anything could be derived. A rebuild clears them away.
        $this->catalogue();
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 9, 'language' => 'de', 'currency' => '', 'net' => '10.0000', 'gross' => '12.0000']);

        self::assertTrue($this->migration()->shouldRun());
        $this->migration()->run();

        self::assertSame([], $this->rows());
        self::assertFalse($this->migration()->shouldRun());
    }

    public function testNebenSchonGeschriebenenZeilenWirdNeuGebautStattAktualisiert(): void
    {
        // If the target is already in the table, a newer projector run has
        // written it and the row without a currency is a leftover. An UPDATE
        // would run into the unique key instead of saying so.
        $this->catalogue();
        $this->pricedProduct(1);
        $this->projector()->rebuild(self::NOW);
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 1, 'language' => 'de', 'currency' => '', 'net' => '10.0000', 'gross' => '12.0000']);

        self::assertTrue($this->migration()->shouldRun());
        $this->migration()->run();

        self::assertSame(
            [['language' => 'de', 'currency' => 'EUR', 'pid' => 1]],
            $this->rows(),
        );
        self::assertFalse($this->migration()->shouldRun());
    }

    public function testEinStartpunktOhneWaehrungHaeltSieNichtEwigFaellig(): void
    {
        // Here the row rightly carries '': its target does too, because the
        // projector skips a start point without a language, not one without a
        // currency. If "row without currency" alone were the criterion,
        // contao:migrate would stay due for ever.
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1, '');
        $this->storedRow();

        self::assertFalse($this->migration()->shouldRun());
    }

    /**
     * @return list<string>
     */
    private function currencies(): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn('SELECT DISTINCT currency FROM tl_consho_product_visibility ORDER BY currency'));
    }

    /**
     * @return list<array{language: string, currency: string, pid: int}>
     */
    private function rows(): array
    {
        return array_map(
            static fn (array $row): array => ['language' => (string) $row['language'], 'currency' => (string) $row['currency'], 'pid' => (int) $row['pid']],
            $this->connection->fetchAllAssociative('SELECT language, currency, pid FROM tl_consho_product_visibility ORDER BY language, currency, pid'),
        );
    }

    private function catalogue(): void
    {
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);
    }

    /**
     * A row as contao:migrate finds it: without a currency.
     */
    private function storedRow(): void
    {
        $this->connection->insert('tl_consho_product_visibility', ['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 1, 'language' => 'de', 'net' => '10.0000', 'gross' => '12.0000']);
    }

    private function pricedProduct(int $id): void
    {
        $this->insertProduct(['id' => $id, 'pid' => 0, 'published' => 1]);
        $this->insertProductShop(['pid' => $id, 'shop' => 1]);
        $this->insertListPrice(['pid' => $id, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);
    }

    /**
     * The projection table as it looked before task 5b — without `currency`.
     */
    private function visibilityTableBefore5b(): void
    {
        $this->connection->executeStatement('DROP TABLE tl_consho_product_visibility');
        $this->connection->executeStatement(
            "CREATE TABLE tl_consho_product_visibility (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tstamp INTEGER NOT NULL DEFAULT 0,
                pid INTEGER NOT NULL DEFAULT 0,
                main INTEGER NOT NULL DEFAULT 0,
                variant INTEGER NOT NULL DEFAULT 0,
                shop INTEGER NOT NULL DEFAULT 0,
                language TEXT NOT NULL DEFAULT '',
                net NUMERIC NULL DEFAULT NULL,
                gross NUMERIC NULL DEFAULT NULL,
                UNIQUE (pid, shop, language)
            )",
        );
    }

    /**
     * tl_page as it looked before C-1c — without consho_currency.
     */
    private function rootPageTableBeforeC1c(): void
    {
        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->executeStatement('ALTER TABLE tl_page DROP COLUMN consho_currency');
    }

    private function projector(): VisibilityProjector
    {
        return new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        );
    }

    private function migration(): VisibilityCurrencyMigration
    {
        return new VisibilityCurrencyMigration($this->connection, $this->projector());
    }
}

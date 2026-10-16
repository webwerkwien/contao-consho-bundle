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
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Die bestehenden Zeilen bekommen die Währung ihres Startpunkts (C-1c, 5b).
 *
 * contao:migrate trägt `currency` als '' nach, und '' ist keine Währung: Jede
 * Liste fragt nach der Währung ihres Startpunkts und fände gar nichts.
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
        // Erster Durchlauf von contao:migrate, vor dem Schema-Update.
        self::assertFalse($this->migration()->shouldRun());
    }

    public function testOhneDieWaehrungsspalteLaeuftSieNicht(): void
    {
        // Der erste der beiden Durchläufe: Die Spalte, die sie füllen soll,
        // gibt es noch gar nicht. Ohne diesen Wächter scheiterte der Durchlauf
        // an einer unbekannten Spalte, statt schlicht nichts zu tun zu haben.
        $this->catalogue();
        $this->visibilityTableBefore5b();
        $this->storedRow();

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testOhneDieWaehrungsspalteAmStartpunktLaeuftSieNicht(): void
    {
        // Der Neubau-Weg liest die Währung an den Startpunkten. Fehlt die
        // Spalte, darf shouldRun() nicht ja sagen — derselbe Wächter wie in
        // RootCurrencyMigration.
        $this->createCatalogSchema($this->connection);
        $this->rootPageTableBeforeC1c();
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'language' => 'de', 'consho_shop' => 1]);
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
        // Das Kriterium ist ein Datenzustand, kein „ist gelaufen"-Marker:
        // Nach dem Füllen gibt es keine Zeile ohne Währung mehr.
        $this->catalogue();
        $this->storedRow();
        $this->migration()->run();

        self::assertFalse($this->migration()->shouldRun());
        self::assertSame(['EUR'], $this->currencies());
    }

    public function testZweiWaehrungenInEinerSpracheWerdenNeuProjiziertStattGeraten(): void
    {
        // Der DACH-Fall. Welche der beiden Währungen die gespeicherte Zeile
        // meint, weiß niemand — also wird gerechnet statt geraten. Produkt 1
        // ist nur in Euro bepreist und steht danach nur unter EUR.
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
        // Beide Wege treffen sich in einem Lauf: Französisch ist eindeutig
        // (nur Euro), Deutsch mehrdeutig (Euro und Franken). Würde die
        // Schleife den eindeutigen Fall unterwegs füllen, schriebe der Neubau
        // danach ohnehin jede Zeile neu — verlorene Arbeit, und die Meldung
        // spräche von einer Aufteilung, die es nicht gab.
        $this->catalogue();
        $this->insertRootPage(2, 'fr', 1);
        $this->insertRootPage(3, 'de', 1, 'CHF');
        $this->pricedProduct(1);
        $this->storedRow();
        $this->connection->insert('tl_consho_product_visibility', ['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 1, 'language' => 'fr', 'net' => '10.0000', 'gross' => '12.0000']);

        $result = $this->migration()->run();

        self::assertStringStartsWith('Rebuilt the visibility projection', (string) $result->getMessage());
        // Produkt 1 ist unübersetzt und nur in Euro bepreist: eine Zeile.
        self::assertSame([['language' => 'de', 'currency' => 'EUR', 'pid' => 1]], $this->rows());
        self::assertFalse($this->migration()->shouldRun());
    }

    public function testZeilenOhneStartpunktWerdenNichtGeratenSondernAbgeraeumt(): void
    {
        // Shop 9 gibt es nicht mehr; seine Zeilen haben keine Währung, aus der
        // sich etwas ableiten ließe. Ein Neubau räumt sie weg.
        $this->catalogue();
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 9, 'language' => 'de', 'currency' => '', 'net' => '10.0000', 'gross' => '12.0000']);

        self::assertTrue($this->migration()->shouldRun());
        $this->migration()->run();

        self::assertSame([], $this->rows());
        self::assertFalse($this->migration()->shouldRun());
    }

    public function testNebenSchonGeschriebenenZeilenWirdNeuGebautStattAktualisiert(): void
    {
        // Steht das Ziel schon in der Tabelle, hat ein neuerer Projektorlauf
        // geschrieben und die Zeile ohne Währung ist ein Überbleibsel. Ein
        // UPDATE liefe in den eindeutigen Schlüssel, statt das zu sagen.
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
        // Hier trägt die Zeile zu Recht '': Ihr Ziel tut es auch, denn der
        // Projektor überspringt einen Startpunkt ohne Sprache, nicht einen
        // ohne Währung. Wäre „Zeile ohne Währung" allein das Kriterium, bliebe
        // contao:migrate für immer fällig.
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'currency' => '', 'fallbackLanguage' => 'de', 'country' => 'AT']);
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
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);
    }

    /**
     * Eine Zeile, wie contao:migrate sie vorfindet: ohne Währung.
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
     * Die Projektionstabelle, wie sie vor Aufgabe 5b aussah — ohne `currency`.
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
     * tl_page, wie es vor C-1c aussah — ohne consho_currency.
     */
    private function rootPageTableBeforeC1c(): void
    {
        $this->connection->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                type TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL DEFAULT '',
                language TEXT NOT NULL DEFAULT '',
                consho_shop INTEGER NOT NULL DEFAULT 0,
                published TEXT NOT NULL DEFAULT '1'
            )",
        );
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

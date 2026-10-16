<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Migration\VisibilityProjectionMigration;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Erstbefüllung nach dem Deploy (B-10): Ohne sie wäre jede Liste leer, bis
 * jemand consho:visibility:rebuild ruft.
 */
class VisibilityProjectionMigrationTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testWithoutTheTablesItDoesNotRun(): void
    {
        // Erster Durchlauf von contao:migrate, vor dem Schema-Update.
        $this->connection->executeStatement('CREATE TABLE tl_consho_product (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, published INTEGER NOT NULL DEFAULT 0)');
        $this->connection->insert('tl_consho_product', ['id' => 1, 'published' => 1]);

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testOhneDieWaehrungsspalteAmStartpunktLaeuftSieNichtAn(): void
    {
        // Enges, aber echtes Fenster: Die Sichtbarkeitstabelle steht schon,
        // der Marker fehlt — so sieht es nach einem abgebrochenen
        // contao:migrate aus. Im ersten Durchlauf danach, vor dem
        // Schemaabgleich, gibt es tl_page.consho_currency noch nicht, und
        // rebuild() fragt seit C-1c genau diese Spalte. Sagt shouldRun() hier
        // ja, scheitert run() an der unbekannten Spalte statt schlicht nichts
        // zu tun zu haben.
        $this->createCatalogSchema($this->connection);
        $this->rootPageTableBeforeC1c();
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'language' => 'de', 'consho_shop' => 1]);
        $this->pricedProduct(1);

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testAfterTheDeployItBuildsTheProjectionOnce(): void
    {
        $this->catalogue();
        $this->pricedProduct(1);

        $migration = $this->migration();

        self::assertTrue($migration->shouldRun());
        self::assertTrue($migration->run()->isSuccessful());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility'));
        self::assertFalse($migration->shouldRun());
    }

    public function testACatalogueWithoutAnyPriceDoesNotStayPendingForever(): void
    {
        // contao:migrate läuft, solange eine Migration fällig ist. „Tabelle
        // leer" als Kriterium bliebe hier nach dem Neubau wahr.
        $this->catalogue();
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 1]);

        $migration = $this->migration();
        $migration->run();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility'));
        self::assertFalse($migration->shouldRun());
    }

    public function testWithoutAPublishedProductThereIsNothingToBuild(): void
    {
        $this->catalogue();
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 0]);

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testAnInstallationThatWasBuiltBeforeIsLeftAlone(): void
    {
        $this->catalogue();
        $this->pricedProduct(1);
        (new ProjectionState($this->connection))->set(ProjectionState::VISIBILITY_BOUNDARIES, 1_790_000_000);

        self::assertFalse($this->migration()->shouldRun());
    }

    private function catalogue(): void
    {
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);
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

    private function pricedProduct(int $id): void
    {
        $this->insertProduct(['id' => $id, 'pid' => 0, 'published' => 1]);
        $this->insertProductShop(['pid' => $id, 'shop' => 1]);
        $this->insertListPrice(['pid' => $id, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);
    }

    private function migration(): VisibilityProjectionMigration
    {
        return new VisibilityProjectionMigration(
            $this->connection,
            new VisibilityProjector(
                $this->connection,
                new VisibilityRows(new VisibilityCriteria()),
                new ShopLanguages($this->connection),
                new ProjectionState($this->connection),
            ),
        );
    }
}

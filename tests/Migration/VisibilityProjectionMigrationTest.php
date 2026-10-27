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
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Initial fill after the update (B-10): without it every list would be
 * empty until someone calls consho:visibility:rebuild.
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
        // First pass of contao:migrate, before the schema update.
        $this->connection->executeStatement('CREATE TABLE tl_consho_product (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, published INTEGER NOT NULL DEFAULT 0)');
        $this->connection->insert('tl_consho_product', ['id' => 1, 'published' => 1]);

        self::assertFalse($this->migration()->shouldRun());
    }

    public function testOhneDieWaehrungsspalteAmStartpunktLaeuftSieNichtAn(): void
    {
        // A narrow but real window: the visibility table already exists,
        // the marker is missing — that is how it looks after an aborted
        // contao:migrate. In the first pass after that, before the schema
        // update, tl_page.consho_currency does not exist yet, and since C-1c
        // rebuild() queries exactly this column. If shouldRun() says yes
        // here, run() fails on the unknown column instead of simply having
        // nothing to do.
        $this->createCatalogSchema($this->connection);
        $this->rootPageTableBeforeC1c();
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'language' => 'de', 'consho_shop' => 1, 'published' => 1]);
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
        // contao:migrate runs as long as a migration is due. "Table empty"
        // as the criterion would stay true here after the rebuild.
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
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);
    }

    /**
     * tl_page as it looked before C-1c — without consho_currency.
     */
    private function rootPageTableBeforeC1c(): void
    {
        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->executeStatement('ALTER TABLE tl_page DROP COLUMN consho_currency');
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Contao\Config;
use Contao\Database;
use Contao\DC_Table;
use Contao\DcaLoader;
use Contao\Environment;
use Contao\System;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PriceRefreshTriggerListener;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogRefreshQueue;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * When a product is copied, the copy reports its NEW product — checked
 * against the real DC_Table::copyChildren().
 *
 * The queue from A-4b rests on two assumptions about the core, both read
 * from vendor/contao/core-bundle/contao/drivers/DC_Table.php:
 *
 * 1. copyChildren() calls the oncopy_callback of the CHILD table, not just
 *    the one of the copied product — otherwise the copy would never get a
 *    projection row.
 * 2. At the time of the call the new row is already inserted — otherwise the
 *    listener would not find its pid.
 *
 * And because the core calls once per copied price row: the product is
 * still in the queue only once afterwards.
 *
 * Set up like FacetPageCascadeTest; the real DCA files of product and price
 * are loaded, the translation table only with what copyChildren reads.
 */
class PriceCopyCascadeTest extends TestCase
{
    use CatalogSchema;

    private const DCA = __DIR__.'/../../contao/dca';

    private Connection $connection;

    private CatalogRefreshQueue $queue;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://example.com/contao?do=consho_product&act=copy'));

        $container = new Container();
        $container->set('database_connection', $this->connection);
        $container->set('request_stack', $requestStack);
        $container->set(Config::class, (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor());
        System::setContainer($container);

        DcaLoader::switchToCurrentRequest();

        $GLOBALS['TL_DCA'] = [];

        require self::DCA.'/tl_consho_product.php';
        require self::DCA.'/tl_consho_price.php';

        // ctable of the product since CW-3, but doNotCopyRecords: copyChildren()
        // skips them before any query — ProductCopyListener copies them. Were
        // the flag missing, the query below would need columns arrCache lacks.
        require self::DCA.'/tl_consho_product_shop.php';
        require self::DCA.'/tl_consho_product_property.php';
        require self::DCA.'/tl_consho_product_option.php';

        $GLOBALS['TL_DCA']['tl_consho_translation'] = ['config' => ['dynamicPtable' => true]];

        // As #[AsCallback] would register it — the core calls it with ($insertId, $dc).
        $this->queue = new CatalogRefreshQueue();
        $listener = new PriceRefreshTriggerListener($this->connection, $this->queue);
        $GLOBALS['TL_DCA']['tl_consho_price']['config']['oncopy_callback'] = [
            static fn (int|string $insertId, DC_Table $dc) => $listener->onCopy($insertId, $dc),
        ];

        $this->setStatic(DcaLoader::class, 'arrLoaded', [
            'dcaFiles' => array_fill_keys(array_keys($GLOBALS['TL_DCA']), true),
        ]);

        // Database::fieldExists() asks MySQL with SHOW FULL COLUMNS. copyChildren()
        // only needs to know from it whether there is a sorting column — neither table has one.
        $this->setStatic(Database::class, 'objInstance', null);
        $this->setProperty(Database::getInstance(), 'arrCache', [
            'tl_consho_price' => [['name' => 'id', 'type' => 'int'], ['name' => 'pid', 'type' => 'int']],
            'tl_consho_translation' => [['name' => 'id', 'type' => 'int'], ['name' => 'pid', 'type' => 'int']],
        ]);
    }

    protected function tearDown(): void
    {
        DcaLoader::reset();
        Environment::reset();

        $this->setStatic(Database::class, 'objInstance', null);
        $this->setStatic(System::class, 'objContainer', null);
    }

    public function testCopyingAProductReportsTheCopyOnceAfterItsPricesAreInserted(): void
    {
        $this->insertProduct(['id' => 1, 'pid' => 0, 'title' => 'Schwedenbitter']);
        $this->insertProduct(['id' => 20, 'pid' => 0, 'title' => 'Schwedenbitter (Kopie)']);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.0000']);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '9.0000', 'minQuantity' => '10.0000']);

        // copy() has already created product 20 and then calls copyChildren(table, new id, old id, new id).
        (new \ReflectionMethod(DC_Table::class, 'copyChildren'))->invoke($this->dataContainer(), 'tl_consho_product', 20, 1, 20);

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_price WHERE pid = 20'), 'Der Kern hat die Preise nicht kopiert.');
        self::assertSame([20], $this->queue->take());
    }

    private function dataContainer(): DC_Table
    {
        return (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
    }

    private function setStatic(string $class, string $property, mixed $value): void
    {
        (new \ReflectionProperty($class, $property))->setValue(null, $value);
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }
}

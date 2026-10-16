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
 * Kopiert ein Produkt, meldet die Kopie ihr NEUES Produkt — gegen den echten
 * DC_Table::copyChildren() geprüft.
 *
 * Die Warteschlange aus A-4b steht auf zwei Annahmen über den Kern, beide aus
 * vendor/contao/core-bundle/contao/drivers/DC_Table.php gelesen:
 *
 * 1. copyChildren() ruft den oncopy_callback der KINDtabelle auf, nicht nur den
 *    des kopierten Produkts — sonst bekäme die Kopie nie eine Projektionszeile.
 * 2. Beim Aufruf ist die neue Zeile schon eingefügt — sonst fände der Listener
 *    ihren pid nicht.
 *
 * Und weil der Kern je kopierter Preiszeile einmal aufruft: Das Produkt steht
 * danach trotzdem nur einmal in der Warteschlange.
 *
 * Aufbau wie FacetPageCascadeTest; geladen werden die echten DCA-Dateien von
 * Produkt und Preis, die Übersetzungstabelle nur mit dem, was copyChildren liest.
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

        // Wie #[AsCallback] ihn eintrüge — der Kern ruft ihn mit ($insertId, $dc).
        $this->queue = new CatalogRefreshQueue();
        $listener = new PriceRefreshTriggerListener($this->connection, $this->queue);
        $GLOBALS['TL_DCA']['tl_consho_price']['config']['oncopy_callback'] = [
            static fn (int|string $insertId, DC_Table $dc) => $listener->onCopy($insertId, $dc),
        ];

        $this->setStatic(DcaLoader::class, 'arrLoaded', [
            'dcaFiles' => array_fill_keys(array_keys($GLOBALS['TL_DCA']), true),
        ]);

        // Database::fieldExists() fragt MySQL mit SHOW FULL COLUMNS. copyChildren()
        // braucht davon nur, ob es eine sorting-Spalte gibt — beide Tabellen haben keine.
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

        // copy() hat Produkt 20 schon angelegt und ruft dann copyChildren(Tabelle, neue id, alte id, neue id).
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

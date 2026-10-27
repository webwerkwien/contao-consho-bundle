<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Contao\Config;
use Contao\CoreBundle\Exception\RedirectResponseException;
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
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The landing pages are NOT in the ctable of tl_page — checked against the
 * real Contao code.
 *
 * What is checked is not what the DCA says, but what DC_Table::deleteChildren(),
 * DC_Table::copyChildren() and DC_Table::reviseTable() from
 * vendor/contao/core-bundle do with it. All three run only through the ctable.
 *
 * Why the table must not be there: reviseTable() runs every time the site
 * structure is opened and deletes every child row whose pid points to no page
 * — without a tstamp check. A click on "New" in the landing page module
 * immediately creates a row with pid 0; it would be gone. Deleting with the
 * recycle bin is now done by FacetPageDeleteListener (own test).
 *
 * The bundle's real DCA files are loaded. Of the core tl_page only what the
 * cascade reads (ctable, sorting mode 5 from
 * vendor/contao/core-bundle/contao/dca/tl_page.php) and the two palettes
 * that contao/dca/tl_page.php extends.
 */
class FacetPageCascadeTest extends TestCase
{
    private const DCA = __DIR__.'/../../contao/dca';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_page', 'tl_article'] as $table) {
            $this->connection->executeStatement(
                "CREATE TABLE $table (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, sorting INTEGER NOT NULL DEFAULT 0)",
            );
        }

        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        DcaSchema::create($this->connection, 'tl_consho_number_range');
        // A child table of the shop since C-8 (invoice texts); dynamicPtable, so
        // chosen by ptable AND pid.
        DcaSchema::create($this->connection, 'tl_consho_translation');

        // From the DCA, so as strict as it is — the UNIQUE index on pid,alias
        // would have rejected a copy to the same page, but not to another one.
        DcaSchema::create($this->connection, 'tl_consho_facet_page');

        // A backend request with a session: reviseTable() reads the bag
        // "contao_backend" from it and reloads via the URI. It must come before
        // switchToCurrentRequest() — a request pushed later counts as a new one
        // for the DcaLoader, which then discards the DCA state that was set.
        $session = new Session(new MockArraySessionStorage());
        $bag = new AttributeBag('_contao_be_attributes');
        $bag->setName('contao_backend');
        $session->registerBag($bag);

        $request = Request::create('https://example.com/contao?do=page');
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $container = new Container();
        $container->set('database_connection', $this->connection);
        $container->set('request_stack', $requestStack);

        // Every System instance (DcaLoader too) imports Config in its constructor.
        // Without the service the import would fall back to Config::getInstance()
        // and the configuration files — irrelevant for the cascade.
        $container->set(Config::class, (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor());
        System::setContainer($container);

        // Switch to the request first, then set the DCA: the first switch
        // discards $GLOBALS['TL_DCA'], after that it stays.
        DcaLoader::switchToCurrentRequest();

        $GLOBALS['TL_DCA'] = [];
        $GLOBALS['TL_DCA']['tl_page'] = [
            'config' => ['ctable' => ['tl_article']],
            'list' => ['sorting' => ['mode' => 5]],
            'palettes' => [
                'root' => '{title_legend},title;{publish_legend},published',
                'rootfallback' => '{title_legend},title;{publish_legend},published',
            ],
        ];
        $GLOBALS['TL_DCA']['tl_article'] = ['config' => ['ptable' => 'tl_page']];
        $GLOBALS['TL_DCA']['tl_consho_order_status'] = ['config' => []];
        $GLOBALS['TL_DCA']['tl_consho_number_range'] = ['config' => []];
        $GLOBALS['TL_DCA']['tl_consho_translation'] = ['config' => ['dynamicPtable' => true]];

        require self::DCA.'/tl_page.php';
        require self::DCA.'/tl_consho_shop.php';
        require self::DCA.'/tl_consho_facet_page.php';

        $this->setStatic(DcaLoader::class, 'arrLoaded', [
            'dcaFiles' => array_fill_keys(array_keys($GLOBALS['TL_DCA']), true),
        ]);

        // Database::fieldExists() asks MySQL with SHOW FULL COLUMNS. copyChildren()
        // only needs to know from it whether there is a sorting column.
        $this->setStatic(Database::class, 'objInstance', null);
        $this->setProperty(Database::getInstance(), 'arrCache', [
            'tl_article' => [['name' => 'id', 'type' => 'int'], ['name' => 'pid', 'type' => 'int'], ['name' => 'sorting', 'type' => 'int']],
            'tl_consho_facet_page' => [['name' => 'id', 'type' => 'int'], ['name' => 'pid', 'type' => 'int']],
        ]);
    }

    protected function tearDown(): void
    {
        // reset() also empties $GLOBALS['TL_DCA'] and the request mapping.
        DcaLoader::reset();
        Environment::reset();

        $this->setStatic(Database::class, 'objInstance', null);
        $this->setStatic(System::class, 'objContainer', null);
    }

    public function testDeletingChildrenOfAPageDoesNotCollectLandingPages(): void
    {
        // Collecting them, recycle bin included, is done by FacetPageDeleteListener.
        // If deleteChildren() collected them as well, they would be in the undo entry
        // twice — and restoring would fail on the primary key.
        $this->facet(page: 12, alias: 'schwedenbitter-250-ml');

        $delete = [];
        $this->dataContainer()->deleteChildren('tl_page', 12, $delete);

        self::assertArrayNotHasKey('tl_consho_facet_page', $delete);
    }

    public function testOpeningTheSiteStructureLeavesAnUnsavedLandingPage(): void
    {
        // The actual bug: "New" in the landing page module creates the row with
        // pid 0. If somebody opens the site structure in between, reviseTable()
        // runs for tl_page.
        $this->connection->insert('tl_consho_facet_page', ['pid' => 0, 'tstamp' => 0]);
        $unsaved = (int) $this->connection->lastInsertId();
        $this->facet(page: 99, alias: 'seite-geloescht');

        // An orphaned article proves that the ctable loop really ran:
        // reviseTable() deletes it and reloads afterwards.
        $this->connection->insert('tl_article', ['id' => 5, 'pid' => 99]);

        $dc = $this->dataContainer();
        $this->setProperty($dc, 'strTable', 'tl_page');

        try {
            (new \ReflectionMethod(DC_Table::class, 'reviseTable'))->invoke($dc);
            self::fail('reviseTable() hätte nach dem Löschen des Artikels neu laden müssen.');
        } catch (RedirectResponseException) {
        }

        self::assertFalse($this->connection->fetchOne('SELECT id FROM tl_article WHERE id = 5'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_facet_page'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_facet_page WHERE id = ?', [$unsaved]));
    }

    public function testDeletingAShopLeavesTheLandingPagesOfThePageWithTheSameId(): void
    {
        // The case this guards: shop 12 and page 12 have nothing to do with each
        // other. If the table were still in the ctable of the shop,
        // deleteChildren() would find it via pid = 12.
        $this->connection->insert('tl_consho_shop', ['id' => 12]);
        $this->facet(page: 12, alias: 'schwedenbitter-250-ml');

        $delete = [];
        $this->dataContainer()->deleteChildren('tl_consho_shop', 12, $delete);

        self::assertArrayNotHasKey('tl_consho_facet_page', $delete);
    }

    public function testCopyingAPageDoesNotCopyItsLandingPages(): void
    {
        // The copy would have the same filter preset — every copied landing page
        // would be a second indexed page with identical content.
        $this->facet(page: 12, alias: 'schwedenbitter-250-ml');

        $copyChildren = new \ReflectionMethod(DC_Table::class, 'copyChildren');
        $copyChildren->invoke($this->dataContainer(), 'tl_page', 20, 12, 20);

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_facet_page WHERE pid = 20'),
        );
    }

    public function testArticlesStayTheOnlyChildTableOfAPage(): void
    {
        // DefaultOperationsListener builds the children button from ctable[0]; it
        // must lead to the articles. And the landing pages must not be in it at all
        // (reviseTable, see above).
        self::assertSame(['tl_article'], $GLOBALS['TL_DCA']['tl_page']['config']['ctable']);
    }

    private function facet(int $page, string $alias): int
    {
        $this->connection->insert('tl_consho_facet_page', [
            'pid' => $page, 'tstamp' => 1, 'filterState' => 'volume:250ml-'.$alias, 'alias' => $alias, 'pageTitle' => 'Titel',
        ]);

        return (int) $this->connection->lastInsertId();
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

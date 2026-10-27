<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\BackendUser;
use Contao\Config;
use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\DataContainer\VirtualFieldsHandler;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\Database;
use Contao\DataContainer;
use Contao\DC_Table;
use Contao\DcaLoader;
use Contao\Environment;
use Contao\System;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\FacetPageDeleteListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Deleting a page takes its landing pages along — recycle bin included.
 *
 * The landing pages are not in the ctable of tl_page (why: see
 * contao/dca/tl_page.php). So the listener collects them and appends them to
 * the undo entry that DC_Table::delete() has just written.
 *
 * Order in DC_Table::delete() (Contao 5.7.13, contao/drivers/DC_Table.php):
 *
 *   collect page + subpages + child rows → INSERT tl_undo (serialize) →
 *   ondelete_callback($this, $undoId) → DELETE per row
 *
 * The first tests write the undo entry by hand in the core format. The
 * last one lets delete() and undo() from vendor/contao/core-bundle run
 * themselves — that is the proof that the format is right.
 *
 * The schemas follow the DCAs, nullability included: tl_consho_facet_page
 * from contao/dca/tl_consho_facet_page.php, tl_undo from
 * vendor/contao/core-bundle/contao/dca/tl_undo.php. Of tl_page and tl_article
 * only the columns read here — undo() only inserts what the table still has
 * anyway.
 */
class FacetPageDeleteListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        // Contao appends a "LIMIT 1" to every DELETE (Statement::limit()), which
        // SQLite does not understand without special translation. All of these
        // DELETEs hit exactly one row via WHERE id=?; dropping the LIMIT therefore
        // changes nothing about their effect.
        $configuration = (new Configuration())->setMiddlewares([new class implements Middleware {
            public function wrap(Driver $driver): Driver
            {
                return new class($driver) extends AbstractDriverMiddleware {
                    public function connect(array $params): DriverConnection
                    {
                        return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                            public function prepare(string $sql): DriverStatement
                            {
                                return parent::prepare(FacetPageDeleteListenerTest::withoutDeleteLimit($sql));
                            }

                            public function query(string $sql): DriverResult
                            {
                                return parent::query(FacetPageDeleteListenerTest::withoutDeleteLimit($sql));
                            }

                            public function exec(string $sql): int|string
                            {
                                return parent::exec(FacetPageDeleteListenerTest::withoutDeleteLimit($sql));
                            }
                        };
                    }
                };
            }
        }]);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL DEFAULT 0, sorting INTEGER NOT NULL DEFAULT 0, tstamp INTEGER NOT NULL DEFAULT 0, title TEXT NOT NULL DEFAULT '')",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_article (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL DEFAULT 0, sorting INTEGER NOT NULL DEFAULT 0, tstamp INTEGER NOT NULL DEFAULT 0, title TEXT NOT NULL DEFAULT '')",
        );
        DcaSchema::create($this->connection, 'tl_consho_facet_page');
        $this->connection->executeStatement(
            "CREATE TABLE tl_undo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pid INTEGER NOT NULL DEFAULT 0,
                tstamp INTEGER NOT NULL DEFAULT 0,
                fromTable TEXT NOT NULL DEFAULT '',
                query TEXT NULL,
                affectedRows INTEGER NOT NULL DEFAULT 0,
                data BLOB NULL,
                preview BLOB NULL
            )",
        );

        // Page 10 with subpage 11; page 20 next to it; page 30 without landing pages.
        $this->connection->insert('tl_page', ['id' => 10, 'pid' => 0, 'sorting' => 128, 'tstamp' => 1, 'title' => 'Kräuterbitter']);
        $this->connection->insert('tl_page', ['id' => 11, 'pid' => 10, 'sorting' => 128, 'tstamp' => 1, 'title' => 'Schwedenbitter']);
        $this->connection->insert('tl_page', ['id' => 20, 'pid' => 0, 'sorting' => 256, 'tstamp' => 1, 'title' => 'Tees']);
        $this->connection->insert('tl_page', ['id' => 30, 'pid' => 0, 'sorting' => 384, 'tstamp' => 1, 'title' => 'Kontakt']);
        $this->connection->insert('tl_article', ['id' => 1, 'pid' => 10, 'sorting' => 128, 'tstamp' => 1, 'title' => 'Kräuterbitter']);
        $this->connection->insert('tl_article', ['id' => 2, 'pid' => 30, 'sorting' => 128, 'tstamp' => 1, 'title' => 'Kontakt']);

        $this->facet(page: 10, alias: 'schwedenbitter-250-ml', state: 'volume:250ml', description: 'Klein und handlich.');
        $this->facet(page: 11, alias: 'rote-farben', state: 'color:rot', description: null);
        $this->facet(page: 20, alias: 'schwedenbitter-250-ml', state: 'volume:250ml', description: null);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);

        // DC_Table::getCurrentRecord() unpacks virtual JSON fields. None of the
        // tables here has any; the row stays as it is.
        $virtualFields = $this->createMock(VirtualFieldsHandler::class);
        $virtualFields->method('expandFields')->willReturnArgument(0);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://example.com/contao?do=page'));

        $container = new Container();
        $container->set('database_connection', $this->connection);
        $container->set('request_stack', $requestStack);
        $container->set('security.helper', $security);
        $container->set('contao.data_container.virtual_fields_handler', $virtualFields);
        $container->set('monolog.logger.contao.general', new NullLogger());
        $container->set('contao.cache.tag_manager', $this->createMock(CacheTagManager::class));
        $container->set('contao.routing.scope_matcher', new ScopeMatcher(
            $this->createStub(RequestMatcherInterface::class),
            $this->createStub(RequestMatcherInterface::class),
            $requestStack,
        ));
        $container->set(Config::class, (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor());
        System::setContainer($container);

        DcaLoader::switchToCurrentRequest();

        $GLOBALS['TL_DCA'] = [
            'tl_page' => ['config' => ['ctable' => ['tl_article']], 'list' => ['sorting' => ['mode' => 5]]],
            'tl_article' => ['config' => ['ptable' => 'tl_page'], 'list' => ['sorting' => ['mode' => 4]]],
            'tl_consho_facet_page' => ['config' => [], 'list' => ['sorting' => ['mode' => 1]]],
            'tl_undo' => ['config' => [], 'list' => ['sorting' => ['mode' => 2]]],
        ];

        $this->setStatic(DcaLoader::class, 'arrLoaded', [
            'dcaFiles' => array_fill_keys(array_keys($GLOBALS['TL_DCA']), true),
        ]);

        // Database::listFields() asks MySQL with SHOW FULL COLUMNS. Only the
        // column names are needed — for fieldExists() in delete() and
        // getFieldNames() in undo(). They come from the SQLite schema itself.
        $this->setStatic(Database::class, 'objInstance', null);
        $columns = [];

        foreach (['tl_page', 'tl_article', 'tl_consho_facet_page', 'tl_undo'] as $table) {
            foreach ($this->connection->fetchFirstColumn("SELECT name FROM pragma_table_info('$table')") as $name) {
                $columns[$table][] = ['name' => $name, 'type' => 'int'];
            }
        }

        $this->setProperty(Database::getInstance(), 'arrCache', $columns);

        $user = (new \ReflectionClass(BackendUser::class))->newInstanceWithoutConstructor();
        $this->setProperty($user, 'arrData', ['id' => 1]);
        $this->setStatic(BackendUser::class, 'objInstance', $user);

        DataContainer::clearCurrentRecordCache();
    }

    protected function tearDown(): void
    {
        DataContainer::clearCurrentRecordCache();
        DcaLoader::reset();
        Environment::reset();

        $this->setStatic(BackendUser::class, 'objInstance', null);
        $this->setStatic(Database::class, 'objInstance', null);
        $this->setStatic(System::class, 'objContainer', null);
    }

    /**
     * @internal only for the middleware in setUp()
     */
    public static function withoutDeleteLimit(string $sql): string
    {
        return preg_replace('/^(DELETE\s.+?)\s+LIMIT\s+\d+(?:\s*,\s*\d+)?\s*$/is', '$1', $sql) ?? $sql;
    }

    public function testTheListenerHangsOffDeletingAPage(): void
    {
        $attributes = (new \ReflectionMethod(FacetPageDeleteListener::class, '__invoke'))->getAttributes(AsCallback::class);

        self::assertCount(1, $attributes);
        self::assertSame(['table' => 'tl_page', 'target' => 'config.ondelete'], $attributes[0]->getArguments());
    }

    public function testLandingPagesOfThePageAndItsSubpageMoveIntoTheUndoEntry(): void
    {
        $landingPages = $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_facet_page WHERE pid IN (10, 11) ORDER BY id');
        $undoId = $this->undoEntryFor([10, 11]);
        $before = $this->undoData($undoId);

        // $dc->id is only the page that was clicked — only the undo entry knows
        // subpage 11.
        (new FacetPageDeleteListener($this->connection))($this->pageContainer(10), $undoId);

        $data = $this->undoData($undoId);

        self::assertCount(2, $landingPages);
        self::assertSame($landingPages, $data['tl_consho_facet_page'] ?? null);
        self::assertSame($before['tl_page'], $data['tl_page']);
        self::assertSame($before['tl_article'], $data['tl_article']);
        self::assertSame(3 + 2, (int) $this->connection->fetchOne('SELECT affectedRows FROM tl_undo WHERE id = ?', [$undoId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_facet_page WHERE pid IN (10, 11)'));
    }

    public function testLandingPagesOfOtherPagesStay(): void
    {
        (new FacetPageDeleteListener($this->connection))($this->pageContainer(10), $this->undoEntryFor([10, 11]));

        self::assertSame(
            [['pid' => 20, 'alias' => 'schwedenbitter-250-ml']],
            $this->connection->fetchAllAssociative('SELECT pid, alias FROM tl_consho_facet_page'),
        );
    }

    public function testAnEntryWithoutLandingPagesStaysUntouched(): void
    {
        $undoId = $this->undoEntryFor([30]);
        $before = $this->connection->fetchAssociative('SELECT * FROM tl_undo WHERE id = ?', [$undoId]);

        (new FacetPageDeleteListener($this->connection))($this->pageContainer(30), $undoId);

        self::assertSame($before, $this->connection->fetchAssociative('SELECT * FROM tl_undo WHERE id = ?', [$undoId]));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_facet_page'));
    }

    public function testContaoRestoresTheLandingPagesItDeleted(): void
    {
        $landingPages = $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_facet_page WHERE pid IN (10, 11) ORDER BY id');
        $pages = $this->connection->fetchAllAssociative('SELECT * FROM tl_page WHERE id IN (10, 11) ORDER BY id');

        $GLOBALS['TL_DCA']['tl_page']['config']['ondelete_callback'] = [new FacetPageDeleteListener($this->connection)];

        // The real delete(): collects page 10 with subpage 11 and article 1,
        // writes tl_undo, calls the listener, deletes.
        $page = $this->pageContainer(10);
        $this->setProperty($page, 'ctable', ['tl_article']);
        $page->delete(true);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_page WHERE id IN (10, 11)'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_facet_page WHERE pid IN (10, 11)'));

        $undo = $this->connection->fetchAssociative('SELECT id, fromTable, affectedRows FROM tl_undo');
        self::assertIsArray($undo);
        self::assertSame('tl_page', $undo['fromTable']);

        // 2 pages + 1 article + 2 landing pages.
        self::assertSame(5, (int) $undo['affectedRows']);

        // The real undo() on the extended entry.
        $restore = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
        $this->setProperty($restore, 'strTable', 'tl_undo');
        $this->setProperty($restore, 'intId', (int) $undo['id']);

        try {
            $restore->undo();
            self::fail('undo() leitet am Ende immer weiter.');
        } catch (RedirectResponseException) {
        }

        self::assertSame($landingPages, $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_facet_page WHERE pid IN (10, 11) ORDER BY id'));
        self::assertSame($pages, $this->connection->fetchAllAssociative('SELECT * FROM tl_page WHERE id IN (10, 11) ORDER BY id'));

        // Without errors on insert, undo() deletes the entry — otherwise it would stay.
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_undo'));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_facet_page'));
    }

    private function facet(int $page, string $alias, string $state, string|null $description): void
    {
        $this->connection->insert('tl_consho_facet_page', [
            'pid' => $page, 'tstamp' => 1, 'filterState' => $state, 'alias' => $alias, 'pageTitle' => 'Titel '.$alias, 'description' => $description,
        ]);
    }

    /**
     * An undo entry as DC_Table::delete() writes it: data per table a list of
     * whole rows, serialize(), affectedRows = number of collected IDs.
     *
     * @param list<int> $pageIds the deleted page first, then its subpages
     */
    private function undoEntryFor(array $pageIds): string
    {
        $data = [];

        foreach ($pageIds as $id) {
            $data['tl_page'][] = $this->connection->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$id]);
        }

        foreach ($pageIds as $id) {
            foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_article WHERE pid = ?', [$id]) as $row) {
                $data['tl_article'][] = $row;
            }
        }

        $this->connection->insert('tl_undo', [
            'pid' => 1,
            'tstamp' => 1,
            'fromTable' => 'tl_page',
            'query' => 'DELETE FROM tl_page WHERE id='.$pageIds[0],
            'affectedRows' => \count($data['tl_page']) + \count($data['tl_article'] ?? []),
            'data' => serialize($data),
        ]);

        return (string) $this->connection->lastInsertId();
    }

    private function undoData(string $undoId): array
    {
        $data = unserialize((string) $this->connection->fetchOne('SELECT data FROM tl_undo WHERE id = ?', [$undoId]));
        self::assertIsArray($data);

        return $data;
    }

    private function pageContainer(int $id): DC_Table
    {
        $dc = (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
        $this->setProperty($dc, 'strTable', 'tl_page');
        $this->setProperty($dc, 'intId', $id);

        return $dc;
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

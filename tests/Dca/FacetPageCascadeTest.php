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
 * Die Landingpages stehen NICHT in der ctable von tl_page — gegen den echten
 * Contao-Code geprüft.
 *
 * Geprüft wird nicht, was die DCA sagt, sondern was DC_Table::deleteChildren(),
 * DC_Table::copyChildren() und DC_Table::reviseTable() aus
 * vendor/contao/core-bundle damit tun. Alle drei laufen nur über die ctable.
 *
 * Warum die Tabelle dort nicht stehen darf: reviseTable() läuft bei jedem
 * Öffnen der Seitenstruktur und löscht jede Kindzeile, deren pid auf keine Seite
 * zeigt — ohne tstamp-Prüfung. Ein Klick auf „Neu" im Landingpage-Modul legt
 * sofort eine Zeile mit pid 0 an; die wäre weg. Das Löschen mit Papierkorb
 * übernimmt jetzt FacetPageDeleteListener (eigener Test).
 *
 * Geladen werden die echten DCA-Dateien des Bundles. Vom Core-tl_page nur, was
 * die Kaskade liest (ctable, Sortiermodus 5 aus
 * vendor/contao/core-bundle/contao/dca/tl_page.php) und die beiden Paletten,
 * die contao/dca/tl_page.php erweitert.
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
        // Seit C-8 Kindtabelle des Shops (Rechnungstexte); dynamicPtable, also
        // über ptable UND pid gewählt.
        DcaSchema::create($this->connection, 'tl_consho_translation');

        // Aus der DCA, also so streng wie sie — der UNIQUE-Index auf pid,alias
        // hätte ein Kopieren auf dieselbe Seite abgelehnt, auf eine andere nicht.
        DcaSchema::create($this->connection, 'tl_consho_facet_page');

        // Eine Backend-Anfrage mit Sitzung: reviseTable() liest daraus die Tasche
        // „contao_backend" und lädt über die URI neu. Sie muss vor
        // switchToCurrentRequest() stehen — eine später aufgelegte Anfrage
        // gilt dem DcaLoader als neue und verwirft den gesetzten DCA-Stand.
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

        // Jede System-Instanz (auch DcaLoader) importiert im Konstruktor Config.
        // Ohne Dienst fiele der Import auf Config::getInstance() und die
        // Konfigurationsdateien zurück — für die Kaskade bedeutungslos.
        $container->set(Config::class, (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor());
        System::setContainer($container);

        // Erst auf die Anfrage umschalten, dann die DCA setzen: Der erste
        // Wechsel verwirft $GLOBALS['TL_DCA'], danach bleibt sie stehen.
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

        // Database::fieldExists() fragt MySQL mit SHOW FULL COLUMNS. copyChildren()
        // braucht davon nur, ob es eine sorting-Spalte gibt.
        $this->setStatic(Database::class, 'objInstance', null);
        $this->setProperty(Database::getInstance(), 'arrCache', [
            'tl_article' => [['name' => 'id', 'type' => 'int'], ['name' => 'pid', 'type' => 'int'], ['name' => 'sorting', 'type' => 'int']],
            'tl_consho_facet_page' => [['name' => 'id', 'type' => 'int'], ['name' => 'pid', 'type' => 'int']],
        ]);
    }

    protected function tearDown(): void
    {
        // reset() leert auch $GLOBALS['TL_DCA'] und die Anfrage-Zuordnung.
        DcaLoader::reset();
        Environment::reset();

        $this->setStatic(Database::class, 'objInstance', null);
        $this->setStatic(System::class, 'objContainer', null);
    }

    public function testDeletingChildrenOfAPageDoesNotCollectLandingPages(): void
    {
        // Das Einsammeln samt Papierkorb macht FacetPageDeleteListener. Sammelte
        // deleteChildren() sie zusätzlich, stünden sie zweimal im Undo-Eintrag —
        // und das Wiederherstellen scheiterte am Primärschlüssel.
        $this->facet(page: 12, alias: 'schwedenbitter-250-ml');

        $delete = [];
        $this->dataContainer()->deleteChildren('tl_page', 12, $delete);

        self::assertArrayNotHasKey('tl_consho_facet_page', $delete);
    }

    public function testOpeningTheSiteStructureLeavesAnUnsavedLandingPage(): void
    {
        // Der eigentliche Fehler: „Neu" im Landingpage-Modul legt die Zeile mit
        // pid 0 an. Öffnet jemand dazwischen die Seitenstruktur, läuft
        // reviseTable() für tl_page.
        $this->connection->insert('tl_consho_facet_page', ['pid' => 0, 'tstamp' => 0]);
        $unsaved = (int) $this->connection->lastInsertId();
        $this->facet(page: 99, alias: 'seite-geloescht');

        // Ein verwaister Artikel belegt, dass die ctable-Schleife wirklich lief:
        // Ihn löscht reviseTable() und lädt danach neu.
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
        // Der Fall aus der Aufgabe: Shop 12 und Seite 12 haben nichts miteinander
        // zu tun. Stünde die Tabelle noch in der ctable des Shops, fände
        // deleteChildren() sie über pid = 12.
        $this->connection->insert('tl_consho_shop', ['id' => 12]);
        $this->facet(page: 12, alias: 'schwedenbitter-250-ml');

        $delete = [];
        $this->dataContainer()->deleteChildren('tl_consho_shop', 12, $delete);

        self::assertArrayNotHasKey('tl_consho_facet_page', $delete);
    }

    public function testCopyingAPageDoesNotCopyItsLandingPages(): void
    {
        // Die Kopie hätte dieselbe Filtervorgabe — jede kopierte Landingpage wäre
        // eine zweite indexierte Seite mit identischem Inhalt.
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
        // DefaultOperationsListener baut den Kinder-Knopf aus ctable[0]; der muss
        // zu den Artikeln führen. Und die Landingpages dürfen gar nicht drinstehen
        // (reviseTable, siehe oben).
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

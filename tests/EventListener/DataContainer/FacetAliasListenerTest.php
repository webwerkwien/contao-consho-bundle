<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DC_Table;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\FacetAliasListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class FacetAliasListenerTest extends TestCase
{
    private const PAGE = 12;

    private Connection $connection;

    /** @var array<int, PageModel> */
    private array $pages = [];

    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['errors'] = [
            'facetAliasForm' => 'form: %s',
            'facetAliasDuplicate' => 'duplicate: %s %s',
            'facetAliasShadowed' => 'shadowed: %s %s %s',
        ];

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                title TEXT NOT NULL DEFAULT '',
                alias VARCHAR(255) NOT NULL DEFAULT ''
            )",
        );
        DcaSchema::create($this->connection, 'tl_consho_facet_page');

        $this->page(self::PAGE, 'Kräuterbitter', 'kraeuter');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testKeepsAWellFormedAlias(): void
    {
        self::assertSame('schwedenbitter-250-ml', $this->save('schwedenbitter-250-ml'));
    }

    /**
     * Contao's rgxp „alias" lässt Großbuchstaben, Unterstrich und Punkt durch
     * (Validator::isAlias: [\w.-]). Die Filteradresse nicht — dieselbe Form wie
     * FilterStateCodec::ALIAS, damit Seiten- und Filteradressen gleich aussehen.
     */
    #[DataProvider('malformed')]
    public function testRefusesAnAliasOfAnotherForm(string $alias): void
    {
        $this->expectExceptionMessage('form: '.$alias);

        $this->save($alias);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'Großbuchstabe' => ['Schwedenbitter'];
        yield 'Schrägstrich' => ['a/b'];
        yield 'Punkt' => ['mit.punkt'];
        yield 'Unterstrich' => ['mit_unterstrich'];
        yield 'Umlaut' => ['kräuter'];
    }

    public function testRefusesAnAliasAnotherLandingPageOnThePageHolds(): void
    {
        // Sonst antwortet der UNIQUE-Index pid,alias — mit einem 500.
        $this->facet(id: 3, page: self::PAGE, alias: 'schwedenbitter-250-ml');

        $this->expectExceptionMessage('duplicate: schwedenbitter-250-ml 3');

        $this->save('schwedenbitter-250-ml');
    }

    public function testKeepsTheSameAliasOnAnotherPage(): void
    {
        $this->facet(id: 3, page: 13, alias: 'schwedenbitter-250-ml');

        self::assertSame('schwedenbitter-250-ml', $this->save('schwedenbitter-250-ml'));
    }

    public function testARecordDoesNotCollideWithItself(): void
    {
        $this->facet(id: 7, page: self::PAGE, alias: 'schwedenbitter-250-ml');

        self::assertSame('schwedenbitter-250-ml', $this->save('schwedenbitter-250-ml', id: 7));
    }

    public function testRefusesAnAliasASubpageWithFolderUrlsAlreadyAnswersTo(): void
    {
        /*
         * ⚠️ Der unauffällige Fall. Mit Ordner-URLs hat die Unterseite zwei
         * Pfadsegmente, die Kategorie eins; bei gleicher Priorität gewinnt die
         * längere Route (AbstractPageRouteProvider::compareRoutes()). Die
         * Landingpage wäre gespeichert und nie erreichbar.
         */
        $this->page(30, 'Schwedenbitter klein', 'kraeuter/schwedenbitter-250-ml');

        $this->expectExceptionMessage('shadowed: Schwedenbitter klein 30 /kraeuter/schwedenbitter-250-ml.html');

        $this->save('schwedenbitter-250-ml');
    }

    public function testASubpageUnderAnotherDomainShadowsNothing(): void
    {
        // Gleicher Alias, andere Adresse: eigene Domain, eigene Route.
        $this->page(30, 'Schwedenbitter klein', 'kraeuter/schwedenbitter-250-ml', domain: 'example.org');

        self::assertSame('schwedenbitter-250-ml', $this->save('schwedenbitter-250-ml'));
    }

    public function testASubpageUnderAnotherLanguagePrefixShadowsNothing(): void
    {
        $this->page(30, 'Schwedenbitter klein', 'kraeuter/schwedenbitter-250-ml', urlPrefix: 'en');

        self::assertSame('schwedenbitter-250-ml', $this->save('schwedenbitter-250-ml'));
    }

    public function testAListingWithoutADomainCanBeShadowedByAPageWithADomain(): void
    {
        /*
         * Ein Startpunkt mit leerem dns antwortet auf jeder Domain
         * (PageModel::loadDetails() vererbt $objParentPage->dns nach unten).
         * Nach AbstractPageRouteProvider::compareRoutes() (core-bundle, Z.
         * 104-111) schlägt eine Route MIT Host eine Route OHNE Host, noch vor
         * der Segmentzahl. Die Landingpage unter der domain-losen Listenseite
         * hätte also gar keine eigene Route mit Host — die Unterseite mit
         * Domain gewinnt auf ihrer Domain in jedem Fall.
         */
        $this->pages[self::PAGE] = $this->pageModel(self::PAGE, 'Kräuterbitter', 'kraeuter', domain: '', urlPrefix: '', urlSuffix: '.html');
        $this->page(30, 'Schwedenbitter klein', 'kraeuter/schwedenbitter-250-ml', domain: 'example.org');

        $this->expectExceptionMessage('shadowed: Schwedenbitter klein 30 /kraeuter/schwedenbitter-250-ml.html');

        $this->save('schwedenbitter-250-ml');
    }

    public function testAListingWithADomainIsNotShadowedByAPageWithoutADomain(): void
    {
        // Umgekehrt: die Listenseite selbst hat eine Domain, die Unterseite
        // nicht. Die Route MIT Host gewinnt — das ist die der Listenseite,
        // die Unterseite ohne Domain verdeckt hier nichts.
        $this->page(30, 'Schwedenbitter klein', 'kraeuter/schwedenbitter-250-ml', domain: '');

        self::assertSame('schwedenbitter-250-ml', $this->save('schwedenbitter-250-ml'));
    }

    public function testChecksOnlyTheFormBeforeAPageIsChosen(): void
    {
        // pid ist Pflichtfeld und meldet sich selbst.
        self::assertSame('schwedenbitter-250-ml', $this->save('schwedenbitter-250-ml', pid: 0));
    }

    private function save(string $value, int $pid = self::PAGE, int $id = 7): string
    {
        $pages = $this->createMock(Adapter::class);
        $pages
            ->method('__call')
            ->willReturnCallback(fn (string $method, array $arguments): ?PageModel => 'findWithDetails' === $method ? ($this->pages[$arguments[0]] ?? null) : null)
        ;

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->with(PageModel::class)->willReturn($pages);

        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnMap([['id', $id]]);
        $dc->method('getActiveRecord')->willReturn(['id' => $id, 'pid' => $pid]);

        return (new FacetAliasListener($this->connection, $framework))($value, $dc);
    }

    private function page(int $id, string $title, string $alias, string $domain = 'shop.example.com', string $urlPrefix = '', string $urlSuffix = '.html'): void
    {
        $this->connection->insert('tl_page', ['id' => $id, 'title' => $title, 'alias' => $alias]);

        $this->pages[$id] = $this->pageModel($id, $title, $alias, $domain, $urlPrefix, $urlSuffix);
    }

    /**
     * Only the PageModel mock, without the tl_page row — for overriding a
     * page already inserted by page(), such as the listing page itself.
     */
    private function pageModel(int $id, string $title, string $alias, string $domain, string $urlPrefix, string $urlSuffix): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', $id],
            ['title', $title],
            ['alias', $alias],
            ['domain', $domain],
            ['urlPrefix', $urlPrefix],
            ['urlSuffix', $urlSuffix],
        ]);

        return $page;
    }

    private function facet(int $id, int $page, string $alias): void
    {
        $this->connection->insert('tl_consho_facet_page', [
            'id' => $id, 'pid' => $page, 'filterState' => 'volume:250ml', 'alias' => $alias, 'pageTitle' => 'Titel',
        ]);
    }
}

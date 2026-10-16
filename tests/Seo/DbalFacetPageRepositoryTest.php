<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Seo\DbalFacetPageRepository;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalFacetPageRepositoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // So streng wie die DCA: filterState und alias sind nullable, beide
        // hängen an einem UNIQUE-Index mit pid.
        DcaSchema::create($this->connection, 'tl_consho_facet_page');
    }

    public function testFindsTheLandingPageOfAStateOnItsPage(): void
    {
        $this->facet(page: 12, state: 'volume:250ml', alias: 'schwedenbitter-250-ml', title: 'Schwedenbitter 250 ml');

        $record = $this->repository()->findByState(12, 'volume:250ml');

        self::assertNotNull($record);
        self::assertSame('schwedenbitter-250-ml', $record->alias);
        self::assertSame('Schwedenbitter 250 ml', $record->pageTitle);
        self::assertSame(12, $record->pageId);
    }

    public function testTheSameStateOnAnotherPageIsNotFound(): void
    {
        // Zwei Kategorien, zwei verschiedene Mengen — die Freigabe gehört der Seite.
        $this->facet(page: 12, state: 'volume:250ml', alias: 'schwedenbitter-250-ml');

        self::assertNull($this->repository()->findByState(13, 'volume:250ml'));
    }

    public function testFindsTheLandingPageByItsAliasOnItsPage(): void
    {
        $this->facet(page: 12, state: 'volume:250ml', alias: 'schwedenbitter-250-ml');

        self::assertSame('volume:250ml', $this->repository()->findByAlias(12, 'schwedenbitter-250-ml')?->filterState);
        self::assertNull($this->repository()->findByAlias(13, 'schwedenbitter-250-ml'));
    }

    public function testAnUnfinishedRecordIsNoLandingPage(): void
    {
        // Contao legt beim Anlegen erst eine leere Zeile an. Mit Alias, aber ohne
        // Kombination, darf sie keine Adresse bekommen — sie zeigte die ganze Kategorie.
        $this->connection->insert('tl_consho_facet_page', ['pid' => 12, 'alias' => 'halb-fertig']);

        self::assertNull($this->repository()->findByAlias(12, 'halb-fertig'));
    }

    public function testARecordWithoutAliasHasNoLandingPageForItsState(): void
    {
        // Die Gegenrichtung: Kombination gespeichert, Alias noch leer. Ohne Alias
        // gibt es kein Pfadsegment — ein Link darauf wäre /kraeuter/.html.
        $this->connection->insert('tl_consho_facet_page', ['pid' => 12, 'filterState' => 'volume:250ml', 'alias' => '']);

        self::assertNull($this->repository()->findByState(12, 'volume:250ml'));
    }

    public function testAMissingDescriptionIsAnEmptyString(): void
    {
        // description ist nullable. Der Seitenkopf fällt bei leerer Beschreibung
        // auf die der Seite zurück — dafür reicht ein Vergleich mit ''.
        $this->facet(page: 12, state: 'volume:250ml', alias: 'schwedenbitter-250-ml');

        self::assertSame('', $this->repository()->findByState(12, 'volume:250ml')?->description);
    }

    public function testFindAllListsEveryCompleteLandingPageByPageAndAlias(): void
    {
        // Für die Sitemap: alle Seiten auf einmal, in fester Reihenfolge — eine
        // Sitemap, deren Zeilen bei jedem Abruf wandern, sieht aus wie ständig geändert.
        $this->facet(page: 13, state: 'volume:500ml', alias: 'tee-500-ml');
        $this->facet(page: 12, state: 'volume:250ml', alias: 'schwedenbitter-250-ml');
        $this->facet(page: 12, state: 'colour:red', alias: 'rote-kraeuter');

        self::assertSame(
            [[12, 'rote-kraeuter'], [12, 'schwedenbitter-250-ml'], [13, 'tee-500-ml']],
            array_map(static fn ($record) => [$record->pageId, $record->alias], $this->repository()->findAll()),
        );
    }

    public function testFindAllSkipsUnfinishedRecords(): void
    {
        // Dieselbe Regel wie die Einzelsuche: ohne Kombination oder Alias keine Adresse.
        $this->facet(page: 12, state: 'volume:250ml', alias: 'schwedenbitter-250-ml');
        $this->connection->insert('tl_consho_facet_page', ['pid' => 12, 'alias' => 'halb-fertig']);
        $this->connection->insert('tl_consho_facet_page', ['pid' => 13, 'filterState' => 'volume:500ml', 'alias' => '']);
        $this->connection->insert('tl_consho_facet_page', ['pid' => 14]);

        self::assertSame(['schwedenbitter-250-ml'], array_map(static fn ($record) => $record->alias, $this->repository()->findAll()));
    }

    private function facet(int $page, string $state, string $alias, string $title = 'Titel'): void
    {
        $this->connection->insert('tl_consho_facet_page', [
            'pid' => $page, 'filterState' => $state, 'alias' => $alias, 'pageTitle' => $title,
        ]);
    }

    private function repository(): DbalFacetPageRepository
    {
        return new DbalFacetPageRepository($this->connection);
    }
}

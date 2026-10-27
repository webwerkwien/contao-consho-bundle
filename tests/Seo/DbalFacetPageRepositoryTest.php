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

        // As strict as the DCA: filterState and alias are nullable, both
        // are part of a UNIQUE index with pid.
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
        // Two categories, two different sets — the release belongs to the page.
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
        // On create Contao first inserts an empty row. With an alias but without
        // a combination it must not get an address — it would show the whole category.
        $this->connection->insert('tl_consho_facet_page', ['pid' => 12, 'alias' => 'halb-fertig']);

        self::assertNull($this->repository()->findByAlias(12, 'halb-fertig'));
    }

    public function testARecordWithoutAliasHasNoLandingPageForItsState(): void
    {
        // The other direction: combination saved, alias still empty. Without an alias
        // there is no path segment — a link to it would be /kraeuter/.html.
        $this->connection->insert('tl_consho_facet_page', ['pid' => 12, 'filterState' => 'volume:250ml', 'alias' => '']);

        self::assertNull($this->repository()->findByState(12, 'volume:250ml'));
    }

    public function testAMissingDescriptionIsAnEmptyString(): void
    {
        // description is nullable. The page head falls back to the page's
        // description when it is empty — a comparison with '' is enough for that.
        $this->facet(page: 12, state: 'volume:250ml', alias: 'schwedenbitter-250-ml');

        self::assertSame('', $this->repository()->findByState(12, 'volume:250ml')?->description);
    }

    public function testFindAllListsEveryCompleteLandingPageByPageAndAlias(): void
    {
        // For the sitemap: all pages at once, in a fixed order — a
        // sitemap whose rows move on every request looks constantly changed.
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
        // The same rule as the single lookup: without a combination or alias, no address.
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

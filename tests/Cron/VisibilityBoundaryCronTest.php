<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cron;

use Contao\CoreBundle\Cache\CacheTagManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Cron\VisibilityBoundaryCron;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Start und Ende eines Zeitfensters in der Projektion der Sichtbarkeit (B-10).
 * Shop 1 in EUR, Startpunkt de. Mit echtem Projektor gegen SQLite — der Cron
 * tut nichts anderes, als ihn im richtigen Moment aufzurufen.
 */
class VisibilityBoundaryCronTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_790_000_000;

    private Connection $connection;

    private ProjectionState $state;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);

        $this->state = new ProjectionState($this->connection);
    }

    public function testWithoutAMarkerEverythingIsBuiltAndTheMarkerSet(): void
    {
        $this->product(1);

        $this->runCron(self::NOW);

        self::assertSame([1], $this->visibleMains());
        self::assertSame(self::NOW, $this->state->get(ProjectionState::VISIBILITY_BOUNDARIES));
    }

    public function testAProductWhoseStartPassedSinceTheMarkerEntersTheListing(): void
    {
        $this->product(1, start: self::NOW - 30);
        $this->state->set(ProjectionState::VISIBILITY_BOUNDARIES, self::NOW - 60);

        $this->runCron(self::NOW);

        self::assertSame([1], $this->visibleMains());
        self::assertSame(self::NOW, $this->state->get(ProjectionState::VISIBILITY_BOUNDARIES));
    }

    public function testAProductWhoseStopPassedSinceTheMarkerLeavesTheListing(): void
    {
        $this->product(1, stop: self::NOW - 30);
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 1, 'language' => 'de', 'net' => '10.0000', 'gross' => '12.0000']);
        $this->state->set(ProjectionState::VISIBILITY_BOUNDARIES, self::NOW - 60);

        $this->runCron(self::NOW);

        self::assertSame([], $this->visibleMains());
    }

    public function testABoundaryBeforeTheMarkerIsNotDueAgain(): void
    {
        $this->product(1, start: self::NOW - 120);

        self::assertSame([], $this->cron()->productIdsDue(self::NOW - 60, self::NOW));
    }

    public function testABoundaryLaterThanNowIsNotDueYet(): void
    {
        $this->product(1, start: self::NOW + 60);

        self::assertSame([], $this->cron()->productIdsDue(self::NOW - 60, self::NOW));
    }

    public function testACronThatDidNotRunCatchesUpOnTheBacklog(): void
    {
        $this->product(1, start: self::NOW - 2 * 86400);
        $this->state->set(ProjectionState::VISIBILITY_BOUNDARIES, self::NOW - 3 * 86400);

        $this->runCron(self::NOW);

        self::assertSame([1], $this->visibleMains());
    }

    public function testAVariantBoundaryNamesItsMainProduct(): void
    {
        $this->product(1);
        $this->insertProduct(['id' => 2, 'pid' => 1, 'published' => 1, 'stop' => (string) (self::NOW - 30)]);

        self::assertSame([1], $this->cron()->productIdsDue(self::NOW - 60, self::NOW));
    }

    public function testAProductThatStartsButStaysInvisibleIsNotDueEveryMinute(): void
    {
        // Ohne Shop: Es entsteht nie eine Zeile. Mit dem Trick des Preis-Crons
        // („Zeile älter als die Grenze") wäre es jede Minute wieder fällig.
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1, 'start' => (string) (self::NOW - 30)]);
        $this->state->set(ProjectionState::VISIBILITY_BOUNDARIES, self::NOW - 60);

        $this->runCron(self::NOW);

        self::assertSame([], $this->visibleMains());
        self::assertSame([], $this->cron()->productIdsDue(self::NOW, self::NOW + 60));
    }

    public function testAFailedRunLeavesTheMarkerWhereItWas(): void
    {
        $this->product(1, start: self::NOW - 30);
        $this->state->set(ProjectionState::VISIBILITY_BOUNDARIES, self::NOW - 60);
        $this->connection->executeStatement('DROP TABLE tl_consho_product_visibility');

        try {
            $this->runCron(self::NOW);
            self::fail('Ohne Tabelle muss der Lauf scheitern.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('tl_consho_product_visibility', $exception->getMessage());
        }

        self::assertSame(self::NOW - 60, $this->state->get(ProjectionState::VISIBILITY_BOUNDARIES));
    }

    public function testAChangeInvalidatesTheListings(): void
    {
        $this->product(1, start: self::NOW - 30);
        $this->state->set(ProjectionState::VISIBILITY_BOUNDARIES, self::NOW - 60);

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with([CatalogCacheTags::LISTINGS]);

        $this->runCron(self::NOW, new CatalogCacheTags($this->connection, $tags));
    }

    public function testNothingDueInvalidatesNothing(): void
    {
        $this->product(1);
        $this->state->set(ProjectionState::VISIBILITY_BOUNDARIES, self::NOW - 60);

        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::never())->method('invalidateTags');

        $this->runCron(self::NOW, new CatalogCacheTags($this->connection, $tags));
    }

    private function runCron(int $now, CatalogCacheTags|null $catalog = null): void
    {
        $this->cron($now, $catalog)();
    }

    private function cron(int $now = self::NOW, CatalogCacheTags|null $catalog = null): VisibilityBoundaryCron
    {
        $projector = new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            $this->state,
        );

        return new VisibilityBoundaryCron($this->connection, $projector, $this->state, null, $catalog, static fn (): int => $now);
    }

    /**
     * Ein Hauptprodukt in Shop 1 mit Listenpreis.
     */
    private function product(int $id, int|null $start = null, int|null $stop = null): void
    {
        $this->insertProduct([
            'id' => $id,
            'pid' => 0,
            'published' => 1,
            'start' => null === $start ? '' : (string) $start,
            'stop' => null === $stop ? '' : (string) $stop,
        ]);
        $this->insertProductShop(['pid' => $id, 'shop' => 1]);
        $this->insertListPrice(['pid' => $id, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);
    }

    /**
     * @return list<int>
     */
    private function visibleMains(): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(
            "SELECT pid FROM tl_consho_product_visibility WHERE variant = 0 AND shop = 1 AND language = 'de' ORDER BY pid",
        ));
    }
}

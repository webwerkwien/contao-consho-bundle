<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;

/**
 * The persistent markers of the projections (B-10, 5.7) — against a real
 * database, because writing has to manage without ON DUPLICATE KEY.
 */
class ProjectionStateTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
    }

    public function testAnUnknownNameHasNoValue(): void
    {
        // null, not 0: "never set" means a full rebuild for the cron.
        self::assertNull((new ProjectionState($this->connection))->get(ProjectionState::VISIBILITY_BOUNDARIES));
    }

    public function testSetStoresAndOverwritesTheValueInOneRow(): void
    {
        $state = new ProjectionState($this->connection);

        $state->set(ProjectionState::VISIBILITY_BOUNDARIES, 1_790_000_000);
        self::assertSame(1_790_000_000, $state->get(ProjectionState::VISIBILITY_BOUNDARIES));

        $state->set(ProjectionState::VISIBILITY_BOUNDARIES, 1_790_000_060);
        self::assertSame(1_790_000_060, $state->get(ProjectionState::VISIBILITY_BOUNDARIES));

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_projection_state'));
    }

    public function testSettingTheSameValueTwiceKeepsOneRow(): void
    {
        // MariaDB reports 0 affected rows for an unchanged UPDATE — a
        // blind INSERT afterwards would hit the unique name.
        $state = new ProjectionState($this->connection);

        $state->set('other', 5);
        $state->set('other', 5);

        self::assertSame(5, $state->get('other'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_projection_state'));
    }

    public function testNamesAreKeptApart(): void
    {
        $state = new ProjectionState($this->connection);

        $state->set('a', 1);
        $state->set('b', 2);

        self::assertSame(1, $state->get('a'));
        self::assertSame(2, $state->get('b'));
    }

    public function testDeleteRemovesOnlyThatMarker(): void
    {
        $state = new ProjectionState($this->connection);

        $state->set('a', 1);
        $state->set('b', 2);
        $state->delete('a');
        $state->delete('never-set');

        self::assertNull($state->get('a'));
        self::assertSame(2, $state->get('b'));
    }

    public function testWithPrefixListsOnlyNamesThatStartWithIt(): void
    {
        // The underscore is a wildcard in LIKE: visibilityXshop_2 must
        // not come along.
        $state = new ProjectionState($this->connection);

        $state->set('visibility_shop_12', 12);
        $state->set('visibility_shop_1', 11);
        $state->set('visibilityXshop_2', 2);
        $state->set(ProjectionState::VISIBILITY_BOUNDARIES, 5);

        self::assertSame(
            ['visibility_shop_1' => 11, 'visibility_shop_12' => 12],
            $state->withPrefix(ProjectionState::VISIBILITY_SHOP_PREFIX),
        );
        self::assertSame([], $state->withPrefix('nothing_'));
    }

    public function testSetStampsTheRow(): void
    {
        $before = time();

        (new ProjectionState($this->connection))->set('a', 1);

        self::assertGreaterThanOrEqual($before, (int) $this->connection->fetchOne('SELECT tstamp FROM tl_consho_projection_state'));
    }
}

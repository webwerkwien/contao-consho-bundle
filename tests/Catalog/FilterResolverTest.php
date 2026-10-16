<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterResolver;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class FilterResolverTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        DcaSchema::create($this->connection, 'tl_consho_property_group');
        DcaSchema::create($this->connection, 'tl_consho_property_option');

        $this->group(1, 'colour');
        $this->option(11, 1, 'red');
        $this->option(12, 1, 'blue');

        $this->group(2, 'volume');
        $this->option(21, 2, '250ml');

        // Same option alias under another group — legitimate, because the
        // address spells it "colour:red" and the group is part of it.
        $this->group(3, 'paint');
        $this->option(31, 3, 'red');
    }

    public function testResolvesAliasesToIds(): void
    {
        $resolved = $this->resolve(['colour' => ['red'], 'volume' => ['250ml']]);

        self::assertSame([1 => [11], 2 => [21]], $resolved);
    }

    public function testTwoOptionsOfOneGroupStayTogether(): void
    {
        self::assertSame([1 => [11, 12]], $this->resolve(['colour' => ['red', 'blue']]));
    }

    public function testTheSameOptionAliasUnderAnotherGroupIsNotConfused(): void
    {
        // The reason option aliases are unique per group and not table-wide.
        self::assertSame([3 => [31]], $this->resolve(['paint' => ['red']]));
    }

    public function testAnUnknownGroupIsDropped(): void
    {
        /*
         * Comes out of a URL, where a link from last year may name something
         * that has since been deleted. Refusing would turn a stale bookmark
         * into an error page.
         */
        self::assertSame([1 => [11]], $this->resolve(['colour' => ['red'], 'gibtsnicht' => ['egal']]));
    }

    public function testAnUnknownOptionIsDropped(): void
    {
        self::assertSame([1 => [11]], $this->resolve(['colour' => ['red', 'neongelb']]));
    }

    public function testAGroupWithOnlyUnknownOptionsDisappearsEntirely(): void
    {
        // Not an empty group — an empty group would be a filter matching
        // nothing. It simply was not a filter at all.
        self::assertSame([], $this->resolve(['colour' => ['neongelb']]));
    }

    public function testAGroupThatIsNotFilterableIsIgnored(): void
    {
        /*
         * `filterable` exists on the group since block A. Without this check a
         * hand-written URL could filter by "internal marking" or "supplier
         * group" — dimensions an operator never meant to expose.
         */
        $this->group(4, 'intern', filterable: false);
        $this->option(41, 4, 'ja');

        self::assertSame([], $this->resolve(['intern' => ['ja']]));
    }

    public function testAnEmptyStateAsksNothingOfTheDatabase(): void
    {
        self::assertSame([], (new FilterResolver($this->connection))->resolve(new FilterState()));
    }

    // ── Vorgabe und Besucherwahl ─────────────────────────────────────────

    public function testGroupsBareOptionIdsWithoutCheckingFilterable(): void
    {
        /*
         * A preset is the editor's own choice on their own page. A shop may
         * well build a category on a dimension it does not offer as a filter —
         * "Aktion" is the obvious one.
         */
        $this->group(4, 'intern', filterable: false);
        $this->option(41, 4, 'ja');

        self::assertSame([1 => [11], 4 => [41]], (new FilterResolver($this->connection))->group([11, 41]));
    }

    public function testAChoiceInAnUntouchedGroupIsAdded(): void
    {
        $narrowed = (new FilterResolver($this->connection))->narrow([1 => [11]], [2 => [21]]);

        self::assertSame([1 => [11], 2 => [21]], $narrowed);
    }

    public function testAChoiceNarrowsWithinThePresetRatherThanReplacingIt(): void
    {
        // The preset is the category, not a pre-ticked filter. A visitor must
        // not be able to click their way out of it.
        $narrowed = (new FilterResolver($this->connection))->narrow([1 => [11, 12]], [1 => [11]]);

        self::assertSame([1 => [11]], $narrowed);
    }

    public function testAChoiceOutsideThePresetYieldsNullRatherThanNoFilter(): void
    {
        /*
         * 🔴 Returning an empty group here would be read by ProductQuery as
         * "this group is not filtered" — and the visitor would see the whole
         * category instead of an empty result.
         */
        self::assertNull((new FilterResolver($this->connection))->narrow([1 => [11]], [1 => [12]]));
    }

    /**
     * @param array<string, list<string>> $filters
     *
     * @return array<int, list<int>>
     */
    private function resolve(array $filters): array
    {
        return (new FilterResolver($this->connection))->resolve(new FilterState($filters));
    }

    private function group(int $id, string $alias, bool $filterable = true): void
    {
        $this->connection->insert('tl_consho_property_group', [
            'id' => $id,
            'alias' => $alias,
            'filterable' => $filterable ? 1 : 0,
        ]);
    }

    private function option(int $id, int $groupId, string $alias): void
    {
        $this->connection->insert('tl_consho_property_option', ['id' => $id, 'pid' => $groupId, 'alias' => $alias]);
    }
}

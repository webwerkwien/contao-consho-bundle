<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\FilterGroupOptionsListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The groups that a filter element may offer (conshoGroups).
 */
class FilterGroupOptionsListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // From the DCA: alias NOT NULL DEFAULT ''.
        DcaSchema::create($this->connection, 'tl_consho_property_group');
    }

    public function testOffersTheFilterableGroupsByTitle(): void
    {
        $this->group(1, 'Füllmenge', 'volume');
        $this->group(2, 'Farbe', 'colour');

        self::assertSame([2 => 'Farbe', 1 => 'Füllmenge'], (new FilterGroupOptionsListener($this->connection))());
    }

    public function testLeavesOutAGroupThatIsNotFilterable(): void
    {
        // A link to it would be silently discarded when clicked.
        $this->group(1, 'Farbe', 'colour');
        $this->group(2, 'Aktion', 'sale', filterable: false);

        self::assertSame([1 => 'Farbe'], (new FilterGroupOptionsListener($this->connection))());
    }

    public function testLeavesOutAGroupWithoutAlias(): void
    {
        // Without an alias the group can never be in cq.
        $this->group(1, 'Farbe', 'colour');
        $this->group(2, 'Halb angelegt', '');

        self::assertSame([1 => 'Farbe'], (new FilterGroupOptionsListener($this->connection))());
    }

    public function testLeavesOutAGroupWhoseAliasTheAddressCannotCarry(): void
    {
        // Contao's alias check lets uppercase letters through, the codec does
        // not — FilterOptionRepository therefore leaves the group out in the
        // front end.
        $this->group(1, 'Farbe', 'colour');
        $this->group(2, 'Größe', 'Groesse');

        self::assertSame([1 => 'Farbe'], (new FilterGroupOptionsListener($this->connection))());
    }

    private function group(int $id, string $title, string $alias, bool $filterable = true): void
    {
        $this->connection->insert('tl_consho_property_group', [
            'id' => $id, 'title' => $title, 'alias' => $alias, 'filterable' => (int) $filterable,
        ]);
    }
}

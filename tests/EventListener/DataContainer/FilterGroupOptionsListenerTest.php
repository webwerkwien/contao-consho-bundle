<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\FilterGroupOptionsListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Die Gruppen, die ein Filterelement anbieten darf (conshoGroups).
 */
class FilterGroupOptionsListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Aus der DCA: alias NOT NULL DEFAULT ''.
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
        // Ein Link darauf würde beim Klicken still verworfen (Entscheidung 6).
        $this->group(1, 'Farbe', 'colour');
        $this->group(2, 'Aktion', 'sale', filterable: false);

        self::assertSame([1 => 'Farbe'], (new FilterGroupOptionsListener($this->connection))());
    }

    public function testLeavesOutAGroupWithoutAlias(): void
    {
        // Ohne Alias kann die Gruppe nie in cq stehen.
        $this->group(1, 'Farbe', 'colour');
        $this->group(2, 'Halb angelegt', '');

        self::assertSame([1 => 'Farbe'], (new FilterGroupOptionsListener($this->connection))());
    }

    public function testLeavesOutAGroupWhoseAliasTheAddressCannotCarry(): void
    {
        // Contaos Alias-Prüfung lässt Großbuchstaben durch, der Codec nicht —
        // FilterOptionRepository lässt die Gruppe deshalb im Frontend weg.
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

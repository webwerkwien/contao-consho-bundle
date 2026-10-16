<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Migration\ConfirmationBeforeMailMigration;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class ConfirmationBeforeMailMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // The order from its DCA (confirmationSentAt predates C-9); only
        // tl_page is historical — without the column C-9 adds.
        DcaSchema::create($this->connection, 'tl_consho_order');
        $this->connection->executeStatement("CREATE TABLE tl_page (id INTEGER PRIMARY KEY, consho_orderPage INTEGER NOT NULL DEFAULT 0)");
        // Numbered: orderNumber is unique in the DCA, as every real order has one.
        $this->connection->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'WEB-1']);
        $this->connection->insert('tl_consho_order', ['id' => 2, 'orderNumber' => 'WEB-2', 'confirmationSentAt' => 1_758_800_000]);
    }

    /**
     * Erster Durchlauf des Updates auf C-9: Die Spalte, die C-9 anlegt, fehlt
     * noch. Die alten Bestellungen bekommen 0 — „keine Bestätigung fällig",
     * weder ein Datum noch das NULL, das „gescheitert" heißt.
     */
    public function testVorC9AngelegteBestellungenBekommenNull(): void
    {
        $migration = new ConfirmationBeforeMailMigration($this->connection);

        self::assertTrue($migration->shouldRun());
        $migration->run();

        self::assertSame([['id' => 1, 'confirmationSentAt' => 0], ['id' => 2, 'confirmationSentAt' => 1_758_800_000]], $this->rows());
        self::assertFalse($migration->shouldRun());
    }

    /**
     * 🔴 Die Zusicherung, an der alles hängt: Steht die C-9-Spalte, läuft die
     * Migration nie wieder. Sonst räumte jedes Deploy die Warnung zu jeder
     * seither gescheiterten Bestätigung still ab.
     */
    public function testNachDemSchemaUpdateLaeuftSieNieWieder(): void
    {
        $this->connection->executeStatement('ALTER TABLE tl_page ADD COLUMN consho_warrantyNotice BLOB NULL');

        self::assertFalse((new ConfirmationBeforeMailMigration($this->connection))->shouldRun());
    }

    public function testEineFrischeInstallationHatNichtsZuTun(): void
    {
        $empty = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertFalse((new ConfirmationBeforeMailMigration($empty))->shouldRun());
    }

    /**
     * @return list<array{id: int, confirmationSentAt: int|null}>
     */
    private function rows(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'confirmationSentAt' => null === $row['confirmationSentAt'] ? null : (int) $row['confirmationSentAt']],
            $this->connection->fetchAllAssociative('SELECT id, confirmationSentAt FROM tl_consho_order ORDER BY id'),
        );
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #3 (Bernhard Renner, 2026-10-17, Version 0.1.3): Mit einer
 * Bestellnummer im Shop scheiterte jedes weitere „Neu" in den Nummernkreisen
 * an `Duplicate entry '1-order' for key 'pid_type'`.
 *
 * Contao legt bei „Neu" sofort einen Entwurf an, nur mit `pid` und den
 * Vorgaben der Spalten — und die Art hatte die Vorgabe `order`. Der Entwurf
 * war also eine zweite Bestellnummer, bevor jemand eine Art gewählt hatte.
 * Workaround des Melders: die Bestellnummer zuletzt anlegen.
 *
 * Das Schema kommt aus der DCA-Datei (DcaSchema), der Entwurf wird so
 * eingefügt, wie DC_Table::create() es tut: ohne `type`.
 */
class NumberRangeDraftTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_number_range');
        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'tstamp' => 1_000, 'type' => 'order', 'prefix' => 'B-']);
    }

    public function testNeuNebenEinerBestellnummerScheitertNicht(): void
    {
        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'tstamp' => 0]);
        // Ein zweiter offener Entwurf, etwa aus einem zweiten Tab.
        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'tstamp' => 0]);

        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_number_range WHERE pid = 1'));
    }

    public function testZweiBestellnummernImSelbenShopBleibenAusgeschlossen(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'tstamp' => 1_000, 'type' => 'order', 'prefix' => 'X-']);
    }
}

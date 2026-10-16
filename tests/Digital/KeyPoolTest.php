<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Digital;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Digital\Entitlement;
use Webwerkwien\ContaoConshoBundle\Digital\KeyPool;
use Webwerkwien\ContaoConshoBundle\Digital\KeyPoolHandler;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\KeyPoolListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * F-6, F19: Der Schlüsselvorrat — ein Code je Stück, alle oder keiner.
 */
class KeyPoolTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_key');
        DcaSchema::create($this->connection, 'tl_consho_product');
    }

    public function testEinfuegenUeberspringtLeerzeilenUndDoppelte(): void
    {
        $listener = new KeyPoolListener($this->connection, new KeyPool($this->connection));

        // Das Feld bleibt leer: import() gibt null zurück, die Codes stehen in tl_consho_key.
        $listener->import("AAA-1\r\n\r\n  BBB-2 \nAAA-1\n", $this->dc(7));
        $listener->import("BBB-2\nCCC-3", $this->dc(7));
        $listener->import('AAA-1', $this->dc(8));

        self::assertSame(['AAA-1', 'BBB-2', 'CCC-3'], $this->codes(7));
        self::assertSame(['AAA-1'], $this->codes(8), 'der Vorrat gehört dem Artikel');
    }

    /**
     * Was ein Befehl zurückgibt (CLI-Abdeckung K2): nur Zahlen, nie die Codes.
     * Übersprungen zählt, was schon im Vorrat liegt — auch innerhalb derselben
     * Liste; Leerzeilen zählen gar nicht.
     */
    public function testDerDienstNenntNurZahlen(): void
    {
        $pool = new KeyPool($this->connection);

        self::assertSame(['added' => 2, 'skipped' => 1, 'left' => 2], $pool->add(7, "AAA-1\n\n BBB-2\nAAA-1", 1_000));
        self::assertSame(['added' => 1, 'skipped' => 2, 'left' => 3], $pool->add(7, "BBB-2\r\nCCC-3\nAAA-1\n", 2_000));

        // Ein vergebener Code liegt noch im Vorrat, ist aber nicht mehr frei —
        // und wird trotzdem nicht ein zweites Mal aufgenommen.
        $this->connection->update('tl_consho_key', ['entitlement' => 9], ['code' => 'AAA-1']);
        self::assertSame(['added' => 0, 'skipped' => 1, 'left' => 2], $pool->add(7, 'AAA-1', 3_000));

        self::assertSame(['AAA-1', 'BBB-2', 'CCC-3'], $this->codes(7));
        self::assertSame(['added' => 1, 'skipped' => 0, 'left' => 1], $pool->add(8, 'AAA-1', 4_000), 'der Vorrat gehört dem Artikel');
    }

    /**
     * Die Spalte fasst 255 Zeichen: ein längerer Code wird gekürzt wie in der
     * Maske, und sein gekürzter Zwilling gilt danach als schon vorhanden.
     */
    public function testEinLangerCodeWirdGekuerzt(): void
    {
        $pool = new KeyPool($this->connection);

        self::assertSame(['added' => 1, 'skipped' => 1, 'left' => 1], $pool->add(7, str_repeat('ä', 256)."\n".str_repeat('ä', 255), 1_000));
        self::assertSame([str_repeat('ä', 255)], $this->codes(7));
    }

    public function testAlleOderKeiner(): void
    {
        (new KeyPoolListener($this->connection, new KeyPool($this->connection)))->import("A\nB\nC", $this->dc(7));
        $handler = new KeyPoolHandler($this->connection);

        self::assertTrue($handler->release($this->entitlement(1, '2.0000')));
        self::assertSame(['A', 'B'], $handler->codesOf(1));

        self::assertFalse($handler->release($this->entitlement(2, '2.0000')), 'nur noch einer frei');
        self::assertSame(['C'], $handler->codesOf(2), 'den nimmt die Freigabe per Savepoint zurück');
    }

    /**
     * Zwei Freigaben verschiedener Bestellungen sperren nicht denselben
     * Vorrat — ein Code, den die andere schon genommen hat, wird übersprungen.
     */
    public function testEinGenommenerCodeWirdUebersprungen(): void
    {
        (new KeyPoolListener($this->connection, new KeyPool($this->connection)))->import("A\nB", $this->dc(7));
        $handler = new KeyPoolHandler($this->connection);

        // Zwischen Auswahl und Übernahme: A gehört schon Berechtigung 9.
        $this->connection->update('tl_consho_key', ['entitlement' => 9], ['code' => 'A']);

        self::assertTrue($handler->release($this->entitlement(1, '1.0000')));
        self::assertSame(['B'], $handler->codesOf(1));
        self::assertSame(['A'], $handler->codesOf(9));
    }

    public function testVergebeneCodesBleiben(): void
    {
        $listener = new KeyPoolListener($this->connection, new KeyPool($this->connection));
        $listener->import("A\nB", $this->dc(7));
        (new KeyPoolHandler($this->connection))->release($this->entitlement(1, '1.0000'));

        $listener->deletePool($this->dc(7));
        self::assertSame(['A'], $this->codes(7), 'mit dem Artikel gehen nur die freien');

        $this->expectException(\RuntimeException::class);
        $listener->refuseAssigned($this->dc((int) $this->connection->fetchOne("SELECT id FROM tl_consho_key WHERE code = 'A'")));
    }

    /**
     * Ein gelöschtes Hauptprodukt nimmt seine Varianten mit
     * (VariantDeleteListener) — und damit deren freie Codes.
     */
    public function testDieVariantenNehmenIhreFreienCodesMit(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 7, 'pid' => 0]);
        $this->connection->insert('tl_consho_product', ['id' => 8, 'pid' => 7]);
        $this->connection->insert('tl_consho_product', ['id' => 9, 'pid' => 0]);

        $listener = new KeyPoolListener($this->connection, new KeyPool($this->connection));
        $listener->import('A', $this->dc(7));
        $listener->import('B', $this->dc(8));
        $listener->import('C', $this->dc(9));

        $listener->deletePool($this->dc(7));

        self::assertSame([], $this->codes(7));
        self::assertSame([], $this->codes(8));
        self::assertSame(['C'], $this->codes(9));
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }

    /**
     * @return list<string>
     */
    private function codes(int $productId): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn('SELECT code FROM tl_consho_key WHERE pid = ? ORDER BY id', [$productId]));
    }

    private function entitlement(int $id, string $quantity): Entitlement
    {
        return new Entitlement($id, 5, 10, 0, 7, $quantity, 'key', 1_000, null);
    }
}

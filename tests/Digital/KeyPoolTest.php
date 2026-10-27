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
 * F-6, F19: The key pool — one code per piece, all or none.
 */
class KeyPoolTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_key');
        DcaSchema::create($this->connection, 'tl_consho_product');
        // As vendor/contao/core-bundle/contao/dca/tl_undo.php.
        $this->connection->executeStatement(
            "CREATE TABLE tl_undo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pid INTEGER NOT NULL DEFAULT 0,
                tstamp INTEGER NOT NULL DEFAULT 0,
                fromTable TEXT NOT NULL DEFAULT '',
                query TEXT NULL,
                affectedRows INTEGER NOT NULL DEFAULT 0,
                data BLOB NULL,
                preview BLOB NULL
            )",
        );
    }

    public function testEinfuegenUeberspringtLeerzeilenUndDoppelte(): void
    {
        $listener = new KeyPoolListener($this->connection, new KeyPool($this->connection));

        // The field stays empty: import() returns null, the codes are in tl_consho_key.
        $listener->import("AAA-1\r\n\r\n  BBB-2 \nAAA-1\n", $this->dc(7));
        $listener->import("BBB-2\nCCC-3", $this->dc(7));
        $listener->import('AAA-1', $this->dc(8));

        self::assertSame(['AAA-1', 'BBB-2', 'CCC-3'], $this->codes(7));
        self::assertSame(['AAA-1'], $this->codes(8), 'der Vorrat gehört dem Artikel');
    }

    /**
     * What a command returns (CLI coverage K2): only numbers, never the codes.
     * Skipped counts what is already in the pool — also within the same list;
     * empty lines do not count at all.
     */
    public function testDerDienstNenntNurZahlen(): void
    {
        $pool = new KeyPool($this->connection);

        self::assertSame(['added' => 2, 'skipped' => 1, 'left' => 2], $pool->add(7, "AAA-1\n\n BBB-2\nAAA-1", 1_000));
        self::assertSame(['added' => 1, 'skipped' => 2, 'left' => 3], $pool->add(7, "BBB-2\r\nCCC-3\nAAA-1\n", 2_000));

        // An assigned code is still in the pool but no longer free —
        // and is still not taken in a second time.
        $this->connection->update('tl_consho_key', ['entitlement' => 9], ['code' => 'AAA-1']);
        self::assertSame(['added' => 0, 'skipped' => 1, 'left' => 2], $pool->add(7, 'AAA-1', 3_000));

        self::assertSame(['AAA-1', 'BBB-2', 'CCC-3'], $this->codes(7));
        self::assertSame(['added' => 1, 'skipped' => 0, 'left' => 1], $pool->add(8, 'AAA-1', 4_000), 'der Vorrat gehört dem Artikel');
    }

    /**
     * The column holds 255 characters: a longer code is shortened as in the
     * form, and its shortened twin then counts as already present.
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
     * Two releases of different orders do not lock the same pool — a code the
     * other one has already taken is skipped.
     */
    public function testEinGenommenerCodeWirdUebersprungen(): void
    {
        (new KeyPoolListener($this->connection, new KeyPool($this->connection)))->import("A\nB", $this->dc(7));
        $handler = new KeyPoolHandler($this->connection);

        // Between selection and taking over: A already belongs to entitlement 9.
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

        $listener->deletePool($this->dc(7), $this->undo(7));
        self::assertSame(['A'], $this->codes(7), 'mit dem Artikel gehen nur die freien');

        $this->expectException(\RuntimeException::class);
        $listener->refuseAssigned($this->dc((int) $this->connection->fetchOne("SELECT id FROM tl_consho_key WHERE code = 'A'")));
    }

    /**
     * A deleted main product takes its variants with it
     * (VariantDeleteListener) — and with them their free codes.
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

        $undoId = $this->undo(7);
        $listener->deletePool($this->dc(7), $undoId);

        self::assertSame([], $this->codes(7));
        self::assertSame([], $this->codes(8));
        self::assertSame(['C'], $this->codes(9));

        // The free codes go into the article's undo entry, "restore" brings
        // them back.
        $undo = $this->connection->fetchAssociative('SELECT data, affectedRows FROM tl_undo WHERE id = ?', [$undoId]);
        self::assertIsArray($undo);
        $data = unserialize((string) $undo['data']);
        self::assertSame(['A', 'B'], array_column($data['tl_consho_key'], 'code'));
        self::assertSame([7], array_map(intval(...), array_column($data['tl_consho_product'], 'id')), 'der Artikel bleibt vorn');
        self::assertSame(1 + 2, (int) $undo['affectedRows']);
    }

    /**
     * Without free codes the undo entry stays untouched; without an undo entry
     * nothing is deleted — purchased codes would otherwise be lost for good.
     */
    public function testOhneUndoEintragBleibtDerVorrat(): void
    {
        $listener = new KeyPoolListener($this->connection, new KeyPool($this->connection));
        $undoId = $this->undo(7);
        $before = $this->connection->fetchAssociative('SELECT * FROM tl_undo WHERE id = ?', [$undoId]);

        $listener->deletePool($this->dc(7), $undoId);
        self::assertSame($before, $this->connection->fetchAssociative('SELECT * FROM tl_undo WHERE id = ?', [$undoId]));

        $listener->import('A', $this->dc(7));

        try {
            $listener->deletePool($this->dc(7), 999);
            self::fail('nicht abgelehnt');
        } catch (\RuntimeException) {
        }

        self::assertSame(['A'], $this->codes(7));
    }

    /**
     * The undo entry DC_Table writes before the ondelete callbacks: the
     * article itself.
     */
    private function undo(int $productId): int
    {
        $this->connection->insert('tl_undo', [
            'fromTable' => 'tl_consho_product',
            'query' => 'DELETE FROM tl_consho_product WHERE id='.$productId,
            'affectedRows' => 1,
            'data' => serialize(['tl_consho_product' => [['id' => $productId, 'pid' => 0]]]),
        ]);

        return (int) $this->connection->lastInsertId();
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

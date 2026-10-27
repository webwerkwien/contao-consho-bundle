<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\VariantDeleteListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Whoever deletes a main product deletes its variants with it — prices,
 * translations and assignments included, all in THE SAME recycle bin entry,
 * so that "Restore" brings the product back in full. Previously the
 * variants were left behind as orphans.
 */
class VariantDeleteListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_price', 'tl_consho_translation', 'tl_consho_product_shop', 'tl_consho_product_property', 'tl_consho_product_option'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->executeStatement("CREATE TABLE tl_undo (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL DEFAULT 0, tstamp INTEGER NOT NULL DEFAULT 0, fromTable TEXT NOT NULL DEFAULT '', query TEXT, affectedRows INTEGER NOT NULL DEFAULT 0, data BLOB)");

        foreach (['tl_consho_product', 'tl_consho_price', 'tl_consho_translation', 'tl_consho_product_shop', 'tl_consho_product_property', 'tl_consho_product_option'] as $table) {
            require __DIR__.'/../../../contao/dca/'.$table.'.php';
        }

        // Main product 5 with variants 6 and 7; product 9 as a neighbour with its
        // own translation under the same number in another table.
        $this->connection->insert('tl_consho_product', ['id' => 5, 'pid' => 0, 'title' => 'Essig']);
        $this->connection->insert('tl_consho_product', ['id' => 6, 'pid' => 5, 'title' => 'Essig 1 l']);
        $this->connection->insert('tl_consho_product', ['id' => 7, 'pid' => 5, 'title' => 'Essig 2 l']);
        $this->connection->insert('tl_consho_product', ['id' => 9, 'pid' => 0, 'title' => 'Salz']);
        $this->connection->insert('tl_consho_price', ['id' => 60, 'pid' => 6, 'currency' => 'EUR', 'net' => '5.0000']);
        $this->connection->insert('tl_consho_translation', ['id' => 61, 'ptable' => 'tl_consho_product', 'pid' => 6, 'language' => 'en', 'title' => 'Vinegar 1 l']);
        $this->connection->insert('tl_consho_translation', ['id' => 62, 'ptable' => 'tl_consho_manufacturer', 'pid' => 6, 'language' => 'en', 'title' => 'Maker']);
        $this->connection->insert('tl_consho_product_shop', ['id' => 63, 'pid' => 7, 'shop' => 1]);
        $this->connection->insert('tl_consho_product_option', ['id' => 64, 'pid' => 7, 'optionId' => 3]);

        // The entry that DC_Table writes for the main product before the ondelete.
        $this->connection->insert('tl_undo', [
            'id' => 1,
            'fromTable' => 'tl_consho_product',
            'query' => 'DELETE FROM tl_consho_product WHERE id=5',
            'affectedRows' => 1,
            'data' => serialize(['tl_consho_product' => [['id' => 5, 'pid' => 0, 'title' => 'Essig']]]),
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testDieVariantenGehenMitSamtIhrenKindern(): void
    {
        $this->listener()->onDelete($this->dc(5), 1);

        self::assertSame([5, 9], $this->ids('tl_consho_product'));
        self::assertSame([], $this->ids('tl_consho_price'));
        self::assertSame([], $this->ids('tl_consho_product_shop'));
        self::assertSame([], $this->ids('tl_consho_product_option'));
        // Only the variant's translation, not that of manufacturer 6.
        self::assertSame([62], $this->ids('tl_consho_translation'));
    }

    public function testAllesStehtImSelbenPapierkorbEintrag(): void
    {
        $this->listener()->onDelete($this->dc(5), 1);

        $undo = $this->connection->fetchAssociative('SELECT affectedRows, data FROM tl_undo WHERE id = 1');
        self::assertIsArray($undo);
        $data = StringUtil::deserialize($undo['data'], true);

        self::assertSame([5, 6, 7], array_map(static fn (array $row): int => (int) $row['id'], $data['tl_consho_product']));
        self::assertSame([60], array_map(static fn (array $row): int => (int) $row['id'], $data['tl_consho_price']));
        self::assertSame([61], array_map(static fn (array $row): int => (int) $row['id'], $data['tl_consho_translation']));
        self::assertSame([63], array_map(static fn (array $row): int => (int) $row['id'], $data['tl_consho_product_shop']));
        self::assertSame([64], array_map(static fn (array $row): int => (int) $row['id'], $data['tl_consho_product_option']));
        // 1 main product + 2 variants + 4 children.
        self::assertSame(7, (int) $undo['affectedRows']);

        // Contao restores table by table: the product before its prices.
        self::assertSame('tl_consho_product', array_key_first($data));
    }

    public function testEineVarianteNimmtNichtsMit(): void
    {
        $this->listener()->onDelete($this->dc(6), 1);

        self::assertSame([5, 6, 7, 9], $this->ids('tl_consho_product'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT affectedRows FROM tl_undo WHERE id = 1'));
    }

    /**
     * Without variants the listener does not even ask for the entry — a delete
     * without a recycle bin entry does not fail on it.
     */
    public function testOhneVariantenBrauchtEsKeinenEintrag(): void
    {
        $this->listener()->onDelete($this->dc(9), 99);

        self::assertSame([5, 6, 7, 9], $this->ids('tl_consho_product'));
    }

    public function testOhneEintragBleibenDieVarianten(): void
    {
        try {
            $this->listener()->onDelete($this->dc(5), 99);
            self::fail('Keine Ausnahme.');
        } catch (\RuntimeException $e) {
            self::assertSame('The undo entry 99 is missing; the variants were not deleted.', $e->getMessage());
        }

        self::assertSame([5, 6, 7, 9], $this->ids('tl_consho_product'));
    }

    public function testOhneVariantenBleibtDerEintragUnberuehrt(): void
    {
        $before = $this->connection->fetchAssociative('SELECT * FROM tl_undo WHERE id = 1');

        $this->listener()->onDelete($this->dc(9), 1);

        self::assertSame($before, $this->connection->fetchAssociative('SELECT * FROM tl_undo WHERE id = 1'));
    }

    /**
     * After KeyPoolListener and StockFieldsListener: they still see the
     * variants in the database (Contao calls higher priority first).
     */
    public function testLaeuftAlsLetzterOndelete(): void
    {
        $attribute = (new \ReflectionMethod(VariantDeleteListener::class, 'onDelete'))->getAttributes(AsCallback::class)[0]->getArguments();

        self::assertSame('tl_consho_product', $attribute['table']);
        self::assertSame('config.ondelete', $attribute['target']);
        self::assertLessThan(0, $attribute['priority'] ?? 0);
    }

    /**
     * 🔴 If the product were in its own ctable, reviseTable() would delete every
     * main product (pid 0, no parent) the next time the list is called. The
     * assignments, on the other hand, belong in it — and are copied not by
     * Contao but by ProductCopyListener.
     */
    public function testDieCtableDesProdukts(): void
    {
        $ctable = $GLOBALS['TL_DCA']['tl_consho_product']['config']['ctable'];

        self::assertNotContains('tl_consho_product', $ctable);

        foreach (['tl_consho_product_shop', 'tl_consho_product_property', 'tl_consho_product_option'] as $join) {
            self::assertContains($join, $ctable);
            self::assertTrue($GLOBALS['TL_DCA'][$join]['config']['doNotCopyRecords'] ?? false, $join);
        }
    }

    /**
     * @return list<int>
     */
    private function ids(string $table): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(\sprintf('SELECT id FROM %s ORDER BY id', $table)));
    }

    private function listener(): VariantDeleteListener
    {
        return new VariantDeleteListener($this->connection);
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}

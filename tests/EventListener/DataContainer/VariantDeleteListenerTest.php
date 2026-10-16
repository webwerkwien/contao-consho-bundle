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
 * Michael, 2026-10-11 („1 als (b)"): Wer ein Hauptprodukt löscht, löscht
 * seine Varianten mit — samt Preisen, Übersetzungen und Zuordnungen, alles in
 * DEMSELBEN Papierkorb-Eintrag, damit „Wiederherstellen" das Produkt ganz
 * zurückbringt. Vorher blieben die Varianten verwaist zurück.
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

        // Hauptprodukt 5 mit den Varianten 6 und 7; Produkt 9 als Nachbar mit
        // eigener Übersetzung unter derselben Nummer in einer anderen Tabelle.
        $this->connection->insert('tl_consho_product', ['id' => 5, 'pid' => 0, 'title' => 'Essig']);
        $this->connection->insert('tl_consho_product', ['id' => 6, 'pid' => 5, 'title' => 'Essig 1 l']);
        $this->connection->insert('tl_consho_product', ['id' => 7, 'pid' => 5, 'title' => 'Essig 2 l']);
        $this->connection->insert('tl_consho_product', ['id' => 9, 'pid' => 0, 'title' => 'Salz']);
        $this->connection->insert('tl_consho_price', ['id' => 60, 'pid' => 6, 'currency' => 'EUR', 'net' => '5.0000']);
        $this->connection->insert('tl_consho_translation', ['id' => 61, 'ptable' => 'tl_consho_product', 'pid' => 6, 'language' => 'en', 'title' => 'Vinegar 1 l']);
        $this->connection->insert('tl_consho_translation', ['id' => 62, 'ptable' => 'tl_consho_manufacturer', 'pid' => 6, 'language' => 'en', 'title' => 'Maker']);
        $this->connection->insert('tl_consho_product_shop', ['id' => 63, 'pid' => 7, 'shop' => 1]);
        $this->connection->insert('tl_consho_product_option', ['id' => 64, 'pid' => 7, 'optionId' => 3]);

        // Der Eintrag, den DC_Table vor dem ondelete für das Hauptprodukt schreibt.
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
        // Nur die Übersetzung der Variante, nicht die des Herstellers 6.
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
        // 1 Hauptprodukt + 2 Varianten + 4 Kinder.
        self::assertSame(7, (int) $undo['affectedRows']);

        // Contao stellt Tabelle für Tabelle wieder her: das Produkt vor seinen Preisen.
        self::assertSame('tl_consho_product', array_key_first($data));
    }

    public function testEineVarianteNimmtNichtsMit(): void
    {
        $this->listener()->onDelete($this->dc(6), 1);

        self::assertSame([5, 6, 7, 9], $this->ids('tl_consho_product'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT affectedRows FROM tl_undo WHERE id = 1'));
    }

    /**
     * Ohne Varianten fragt der Listener nicht einmal nach dem Eintrag — ein
     * Löschen ohne Papierkorb-Eintrag scheitert nicht an ihm.
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
     * Nach KeyPoolListener und StockFieldsListener: die sehen die Varianten
     * noch in der Datenbank (Contao ruft höhere Priorität zuerst).
     */
    public function testLaeuftAlsLetzterOndelete(): void
    {
        $attribute = (new \ReflectionMethod(VariantDeleteListener::class, 'onDelete'))->getAttributes(AsCallback::class)[0]->getArguments();

        self::assertSame('tl_consho_product', $attribute['table']);
        self::assertSame('config.ondelete', $attribute['target']);
        self::assertLessThan(0, $attribute['priority'] ?? 0);
    }

    /**
     * 🔴 Stünde das Produkt in seiner eigenen ctable, löschte reviseTable()
     * beim nächsten Aufruf der Liste jedes Hauptprodukt (pid 0, kein Elternteil).
     * Die Zuordnungen dagegen gehören hinein — und werden nicht von Contao
     * kopiert, sondern von ProductCopyListener.
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ProductPropertiesListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Spec Abo, offene Punkte (Review Abo-6, S3): Eine kopierte Variante kommt
 * ohne Achsenwerte; setzt der Betreiber dieselben wie bei einer Schwester,
 * nahm die Variantenmatrix still die erste. Jetzt lehnt das Speichern ab.
 */
class ProductPropertiesListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_option');

        // Hauptprodukt 1 mit Variante 2 (rot, L); 3 ist die neue Kopie.
        // Hauptprodukt 10 mit Variante 11 (rot, L) — ein anderes Produkt.
        foreach ([[1, 0], [2, 1], [3, 1], [10, 0], [11, 10]] as [$id, $pid]) {
            $this->connection->insert('tl_consho_product', ['id' => $id, 'pid' => $pid]);
        }

        foreach ([[2, 21], [2, 31], [11, 21], [11, 31]] as [$pid, $option]) {
            $this->connection->insert('tl_consho_product_option', ['pid' => $pid, 'optionId' => $option]);
        }

        $GLOBALS['TL_LANG']['tl_consho_product']['variantDuplicate'] = 'doppelt: %s';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testDieGleicheKombinationWieDieSchwesterWirdAbgelehnt(): void
    {
        try {
            (new ProductPropertiesListener($this->connection))->saveVariantOptions(serialize(['31', '21']), $this->dc(3));
            self::fail('erwartet: Ablehnung');
        } catch (\RuntimeException $e) {
            self::assertSame('doppelt: 2', $e->getMessage(), 'nennt die Schwester');
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_option WHERE pid = 3'), 'nichts geschrieben');
    }

    public function testEineAndereKombinationGeht(): void
    {
        (new ProductPropertiesListener($this->connection))->saveVariantOptions(serialize(['21', '32']), $this->dc(3));

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_option WHERE pid = 3'));
    }

    /** Teilmenge ist nicht gleich: rot allein ist eine andere Kombination als rot, L. */
    public function testEineTeilmengeIstKeineDopplung(): void
    {
        (new ProductPropertiesListener($this->connection))->saveVariantOptions(serialize(['21']), $this->dc(3));

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_option WHERE pid = 3'));
    }

    public function testDieVarianteSelbstUndFremdeHauptprodukteZaehlenNicht(): void
    {
        $listener = new ProductPropertiesListener($this->connection);

        // Variante 2 speichert ihre eigenen Werte erneut; 11 hat sie unter einem anderen Produkt.
        $listener->saveVariantOptions(serialize(['21', '31']), $this->dc(2));

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_option WHERE pid = 2'));
    }

    public function testOhneAchsenwerteKeinePruefung(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 4, 'pid' => 1]);

        (new ProductPropertiesListener($this->connection))->saveVariantOptions(serialize([]), $this->dc(4));
        (new ProductPropertiesListener($this->connection))->saveVariantOptions(serialize([]), $this->dc(3));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_option WHERE pid IN (3, 4)'));
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}

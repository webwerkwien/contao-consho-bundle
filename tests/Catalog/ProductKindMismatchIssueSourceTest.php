<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKindMismatchIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * DL5, Spec 6.4: „Produkt passt nicht zu seiner Art" — drei Widersprüche,
 * ein gültiger Fall. Typ 1 digital, 2 Dienstleistung, 3 körperlich.
 */
class ProductKindMismatchIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_key'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'title' => 'Download', 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'title' => 'Beratung', 'kind' => 'service']);
        $this->connection->insert('tl_consho_product_type', ['id' => 3, 'title' => 'Ware', 'kind' => 'physical']);

        require __DIR__.'/../../contao/languages/de/default.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testDreiWidersprueche(): void
    {
        // 1: Dienstleistung mit Datei und Auslieferungsart.
        $this->product(1, 'Beratungspaket', 2, ['digitalDelivery' => 'file', 'digitalFile' => str_repeat('a', 16)]);
        // 2: Ware im Abo.
        $this->product(2, 'Wanderstock', 3, ['saleSubscription' => 1]);
        // 3: Variante einer Dienstleistung mit Schlüsseln im Vorrat — der Typ
        //    des Grundprodukts zählt, nicht ihr eigener (digital).
        $this->product(3, 'Mitgliedschaft', 2);
        $this->product(4, 'Mitgliedschaft Gold', 1, ['pid' => 3]);
        $this->connection->insert('tl_consho_key', ['pid' => 4, 'code' => 'ABC']);

        $issues = (new ProductKindMismatchIssueSource($this->connection))->issues();

        self::assertSame([ConfigurationFinding::ProductKindMismatch], array_values(array_unique(array_map(static fn ($i) => $i->finding, $issues), SORT_REGULAR)));
        self::assertSame([
            ['product' => 'Beratungspaket', 'kind' => 'eine Dienstleistung', 'fields' => 'eine Auslieferungsart und eine Datei'],
            ['product' => 'Wanderstock', 'kind' => 'körperliche Ware', 'fields' => 'den Verkauf im Abo'],
            ['product' => 'Mitgliedschaft Gold', 'kind' => 'eine Dienstleistung', 'fields' => 'Schlüssel im Vorrat'],
        ], array_map(static fn ($i) => $i->context, $issues));
        self::assertFalse(ConfigurationFinding::ProductKindMismatch->isWarning());
    }

    /**
     * Block GARAN (GA5): eine Haltbarkeitsgarantie ist ein Versprechen über
     * eine Ware — am E-Book nach einem Typwechsel ein Widerspruch.
     */
    public function testEineGarantieAnEinemNichtWarenproduktIstEinWiderspruch(): void
    {
        $this->product(1, 'E-Book', 1, ['durabilityYears' => 5]);
        $this->product(2, 'Beratung', 2, ['durabilityLabel' => str_repeat('b', 16)]);
        $this->product(3, 'Wanderstock', 3, ['durabilityYears' => 5, 'durabilityLabel' => str_repeat('c', 16)]);

        self::assertSame([
            ['product' => 'E-Book', 'kind' => 'digital', 'fields' => 'eine Haltbarkeitsgarantie (GARAN-Label)'],
            ['product' => 'Beratung', 'kind' => 'eine Dienstleistung', 'fields' => 'eine Haltbarkeitsgarantie (GARAN-Label)'],
        ], array_map(static fn ($i) => $i->context, (new ProductKindMismatchIssueSource($this->connection))->issues()));
    }

    public function testEinDigitalesProduktMitAllemIstRichtig(): void
    {
        $this->product(1, 'E-Book', 1, ['digitalDelivery' => 'file', 'digitalFile' => str_repeat('a', 16), 'saleSubscription' => 1]);
        $this->connection->insert('tl_consho_key', ['pid' => 1, 'code' => 'ABC']);
        // Eine Dienstleistung im Abo ist gewollt (Fitnesscenter).
        $this->product(2, 'Mitgliedschaft', 2, ['saleSubscription' => 1]);
        // Unveröffentlicht zählt nicht.
        $this->product(3, 'Entwurf', 3, ['saleSubscription' => 1, 'published' => 0]);

        self::assertSame([], (new ProductKindMismatchIssueSource($this->connection))->issues());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function product(int $id, string $title, int $type, array $values = []): void
    {
        $this->connection->insert('tl_consho_product', ['id' => $id, 'title' => $title, 'productType' => $type, 'published' => 1, ...$values]);
    }
}

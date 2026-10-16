<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * verify() meldet, wo die Projektion der Sichtbarkeit vom Katalog abweicht —
 * und schreibt nie (B-10, 5.7). Shop 1 in EUR, Startpunkte de und fr.
 */
class VisibilityProjectorVerifyTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);
        $this->insertRootPage(2, 'fr', 1);

        // Produkt 1 sichtbar, bepreist, übersetzt; Produkt 2 unveröffentlicht.
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 1]);
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => 'Produit', 'alias' => 'produit']);
        $this->insertProduct(['id' => 2, 'pid' => 0, 'published' => 0]);
        $this->insertProductShop(['pid' => 2, 'shop' => 1]);
        $this->insertListPrice(['pid' => 2, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);

        $this->projector()->rebuild(self::NOW);
    }

    public function testAfterARebuildThereIsNothingToReport(): void
    {
        self::assertSame([], $this->projector()->verify(self::NOW));
    }

    public function testAMissingRowIsReported(): void
    {
        $this->connection->executeStatement("DELETE FROM tl_consho_product_visibility WHERE pid = 1 AND language = 'fr'");

        $deviations = $this->projector()->verify(self::NOW);

        self::assertCount(1, $deviations);
        self::assertSame(['shop' => 1, 'language' => 'fr', 'currency' => 'EUR', 'pid' => 1], array_slice($deviations[0], 0, 4));
        self::assertNull($deviations[0]['stored']);
        self::assertSame('12.0000', $deviations[0]['expected']['gross'] ?? null);
    }

    public function testASurplusRowIsReported(): void
    {
        // Das unveröffentlichte Produkt steht am Backend vorbei in der Tabelle.
        $this->insertVisibility(['pid' => 2, 'main' => 2, 'variant' => 0, 'shop' => 1, 'language' => 'de', 'net' => '10.0000', 'gross' => '12.0000']);

        $deviations = $this->projector()->verify(self::NOW);

        self::assertCount(1, $deviations);
        self::assertSame(2, $deviations[0]['pid']);
        self::assertNull($deviations[0]['expected']);
    }

    public function testAWrongFromPriceIsReported(): void
    {
        $this->connection->executeStatement("UPDATE tl_consho_product_visibility SET gross = '11.0000' WHERE pid = 1 AND language = 'de'");

        $deviations = $this->projector()->verify(self::NOW);

        self::assertCount(1, $deviations);
        self::assertSame('11.0000', $deviations[0]['stored']['gross'] ?? null);
        self::assertSame('12.0000', $deviations[0]['expected']['gross'] ?? null);
    }

    public function testRowsOfALanguageWithoutARootPageAreReported(): void
    {
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 1, 'language' => 'it', 'net' => '10.0000', 'gross' => '12.0000']);

        $deviations = $this->projector()->verify(self::NOW);

        self::assertCount(1, $deviations);
        self::assertSame('it', $deviations[0]['language']);
        self::assertNull($deviations[0]['expected']);
    }

    public function testRowsInACurrencyWithoutARootPageAreReported(): void
    {
        // Die Gegenprobe zur Sprache: Eine Zeile in einer Währung, die kein
        // Startpunkt dieses Shops führt, liest keine Liste — ein rebuild
        // räumte sie weg, also ist die Tabelle nicht, was sie sein soll. Ohne
        // die Währung im Abgleich zählte sie als Zeile des EUR-Ziels und
        // fiele nirgends auf.
        // Der Betrag weicht bewusst ab: Läse der Abgleich des EUR-Ziels die
        // Frankenzeile mit, meldete er sie obendrein als falschen Ab-Preis —
        // eine zweite Abweichung, die es nicht gibt.
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 1, 'language' => 'de', 'currency' => 'CHF', 'net' => '12.5000', 'gross' => '15.0000']);

        $deviations = $this->projector()->verify(self::NOW);

        self::assertCount(1, $deviations);
        self::assertSame('CHF', $deviations[0]['currency']);
        self::assertNull($deviations[0]['expected']);
    }

    public function testVerifyNeverWrites(): void
    {
        $this->connection->executeStatement("DELETE FROM tl_consho_product_visibility WHERE language = 'fr'");
        $this->insertVisibility(['pid' => 2, 'main' => 2, 'variant' => 0, 'shop' => 1, 'language' => 'de', 'net' => '1.0000', 'gross' => '1.0000']);
        $before = $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_product_visibility ORDER BY id');

        $this->projector()->verify(self::NOW);

        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_product_visibility ORDER BY id'));
    }

    private function projector(): VisibilityProjector
    {
        return new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        );
    }
}

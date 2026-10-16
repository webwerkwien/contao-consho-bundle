<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;

/**
 * Die Zeilen, die die Projektion der Sichtbarkeit halten muss (B-10, 5.7) —
 * gegen eine echte Datenbank, weil es eine SQL-Regel ist.
 *
 * Die Sichtbarkeitsregeln selbst prüft VisibilityCriteriaTest. Hier geht es um
 * das, was dazukommt: bepreist, Ab-Preis, Variantenzeilen, Sprache.
 */
class VisibilityRowsTest extends TestCase
{
    use CatalogSchema;

    // Zehnstellig wie ein echter Zeitstempel — siehe VisibilityCriteriaTest.
    private const NOW = 1_790_000_000;
    private const SHOP = 1;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
    }

    public function testAPricedVisibleProductIsARowWithItsPrice(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');

        self::assertSame([1 => ['main' => 1, 'variant' => 0, 'net' => '10.0000', 'gross' => '12.0000']], $this->mains('de'));
    }

    public function testOnlyTheListPriceOfTheShopsHomeCountryCounts(): void
    {
        // C25: Zwei Euro-Shops in AT und DE — dieselbe Währung, verschiedene Brutto.
        // Die deutsche Zeile ist für den österreichischen Shop kein Preis.
        $this->main(1);
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => 'DE', 'net' => '10.0000', 'gross' => '11.9000']);

        self::assertSame([], $this->mains('de'));

        $this->listPrice(1, '12.0000');

        self::assertSame('12.0000', $this->mains('de')[1]['gross']);
    }

    public function testAVisibleProductWithoutAPriceIsNoRow(): void
    {
        $this->main(1);

        self::assertSame([], $this->mains('de'));
    }

    public function testAPriceInAnotherCurrencyDoesNotCount(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000', 'CHF');

        self::assertSame([], $this->mains('de'));
    }

    public function testAnInvisibleProductIsNoRowEvenWithAPrice(): void
    {
        $this->main(1, published: false);
        $this->listPrice(1, '12.0000');
        $this->main(2, start: (string) (self::NOW + 60));
        $this->listPrice(2, '12.0000');
        $this->main(3, shops: [2]);
        $this->listPrice(3, '12.0000');

        self::assertSame([], $this->mains('de'));
    }

    public function testTheFromPriceIsTheCheapestVisibleVariant(): void
    {
        $this->main(1);
        $this->variant(2, 1);
        $this->variant(3, 1);
        $this->variant(4, 1, published: false);
        $this->listPrice(2, '10.0000');
        $this->listPrice(3, '8.0000');
        $this->listPrice(4, '5.0000');

        self::assertSame('8.0000', $this->mains('de')[1]['gross']);
    }

    public function testWithVariantsTheOwnPriceDoesNotCount(): void
    {
        // A-4b, E2: the page asks only the variants.
        $this->main(1);
        $this->listPrice(1, '5.0000');
        $this->variant(2, 1);
        $this->listPrice(2, '15.0000');

        self::assertSame('15.0000', $this->mains('de')[1]['gross']);
    }

    public function testAProductWhoseVariantsHaveNoPriceIsNoRow(): void
    {
        $this->main(1);
        $this->listPrice(1, '5.0000');
        $this->variant(2, 1);

        self::assertSame([], $this->mains('de'));
    }

    public function testAVisibleVariantIsARowWithoutAPrice(): void
    {
        $this->main(1);
        $this->variant(2, 1);
        $this->variant(3, 1);
        $this->variant(4, 1, published: false);
        $this->listPrice(2, '10.0000');

        self::assertSame([
            2 => ['main' => 1, 'variant' => 1, 'net' => null, 'gross' => null],
            3 => ['main' => 1, 'variant' => 1, 'net' => null, 'gross' => null],
        ], $this->variants('de'));
    }

    public function testInATranslationLanguageAnUntranslatedProductIsNoRow(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->main(2);
        $this->listPrice(2, '12.0000');
        $this->translate(2);

        self::assertSame([1, 2], array_keys($this->mains('de')));
        self::assertSame([2], array_keys($this->mains('fr')));
    }

    public function testAVariantHiddenInTheLanguageHasNoRowAndNoPriceThere(): void
    {
        // B-8: its own German warning without a French one hides it in fr —
        // it must not lend the product its cheaper price there either.
        $this->main(1);
        $this->translate(1);
        $this->variant(2, 1, safetyWarnings: 'Achtung, heiß');
        $this->variant(3, 1);
        $this->listPrice(2, '6.0000');
        $this->listPrice(3, '9.0000');

        self::assertSame('6.0000', $this->mains('de')[1]['gross']);
        self::assertSame('9.0000', $this->mains('fr')[1]['gross']);
        self::assertSame([3], array_keys($this->variants('fr')));
    }

    public function testMainIdsLimitBothStatements(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->main(5);
        $this->variant(6, 5);
        $this->listPrice(6, '12.0000');
        $this->main(7);
        $this->variant(8, 7);
        $this->listPrice(8, '12.0000');

        self::assertSame([1, 5], array_keys($this->mains('de', [1, 5])));
        self::assertSame([6], array_keys($this->variants('de', [1, 5])));
    }

    /**
     * @param list<int>|null $mainIds
     *
     * @return array<int, array{main: int, variant: int, net: string|null, gross: string|null}>
     */
    private function mains(string $language, array|null $mainIds = null): array
    {
        $statement = (new VisibilityRows(new VisibilityCriteria()))->mains(self::SHOP, $language, 'de', 'EUR', 'AT', self::NOW, $mainIds);

        return $this->fetch($statement->sql, $statement->params, $statement->types);
    }

    /**
     * @param list<int>|null $mainIds
     *
     * @return array<int, array{main: int, variant: int, net: string|null, gross: string|null}>
     */
    private function variants(string $language, array|null $mainIds = null): array
    {
        $statement = (new VisibilityRows(new VisibilityCriteria()))->variants(self::SHOP, $language, 'de', self::NOW, $mainIds);

        return $this->fetch($statement->sql, $statement->params, $statement->types);
    }

    /**
     * Beträge auf vier Stellen: SQLite gibt NUMERIC als 12 oder 12.0 zurück,
     * MySQL als "12.0000".
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     *
     * @return array<int, array{main: int, variant: int, net: string|null, gross: string|null}>
     */
    private function fetch(string $sql, array $params, array $types): array
    {
        $rows = [];

        foreach ($this->connection->fetchAllAssociative($sql.' ORDER BY 1', $params, $types) as $row) {
            $rows[(int) $row['pid']] = [
                'main' => (int) $row['main'],
                'variant' => (int) $row['variant'],
                'net' => null === $row['net'] ? null : \sprintf('%.4F', $row['net']),
                'gross' => null === $row['gross'] ? null : \sprintf('%.4F', $row['gross']),
            ];
        }

        return $rows;
    }

    /**
     * @param list<int> $shops
     */
    private function main(int $id, bool $published = true, string $start = '', array $shops = [self::SHOP]): void
    {
        $this->insertProduct(['id' => $id, 'pid' => 0, 'title' => 'Produkt '.$id, 'published' => $published ? 1 : 0, 'start' => $start]);

        foreach ($shops as $shop) {
            $this->insertProductShop(['pid' => $id, 'shop' => $shop]);
        }
    }

    private function variant(int $id, int $pid, bool $published = true, string|null $safetyWarnings = null): void
    {
        $this->insertProduct(['id' => $id, 'pid' => $pid, 'title' => 'Variante '.$id, 'published' => $published ? 1 : 0, 'safetyWarnings' => $safetyWarnings]);
    }

    private function listPrice(int $productId, string $gross, string $currency = 'EUR'): void
    {
        $this->insertListPrice(['pid' => $productId, 'currency' => $currency, 'country' => 'AT', 'gross' => $gross, 'net' => \sprintf('%.4F', (float) $gross / 1.2)]);
    }

    private function translate(int $productId): void
    {
        $this->insertTranslation(['pid' => $productId, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => 'Produit '.$productId, 'alias' => 'produit-'.$productId]);
    }
}

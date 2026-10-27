<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * The equality test of the projection (B-10, 5.7, "Prüfung").
 *
 * After a rebuild the table must hold, per shop and language, exactly
 * what the visibility rules yield in real time — from-price included.
 *
 * 🔴 The expected value does NOT come from VisibilityRows. A comparison with
 * the same class that fills the table would always be equal — even if it
 * lacks a condition. Here the test computes itself: visible according to
 * VisibilityCriteria, priced and from-price in PHP from the list prices, by
 * the rule from Block A (A-4b, E2) — with variants only their visible
 * prices count, without variants the product's own.
 *
 * Catalogue: shop 1 (start points de, fr), shop 2 (de), both EUR, default language de.
 */
class VisibilityProjectionConsistencyTest extends TestCase
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
        $this->insertShop(['id' => 2, 'title' => 'Zweitshop', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);
        $this->insertRootPage(2, 'fr', 1);
        $this->insertRootPage(3, 'de', 2);

        $this->catalogue();

        (new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        ))->rebuild(self::NOW);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function targets(): iterable
    {
        yield 'Shop 1, Standardsprache' => [1, 'de'];
        yield 'Shop 1, Übersetzung' => [1, 'fr'];
        yield 'Shop 2' => [2, 'de'];
    }

    #[DataProvider('targets')]
    public function testMainProductRowsAreExactlyTheVisiblePricedProductsWithTheirFromPrice(int $shop, string $language): void
    {
        $stored = $this->connection->fetchAllKeyValue(
            'SELECT pid, gross FROM tl_consho_product_visibility WHERE shop = ? AND language = ? AND variant = 0 ORDER BY pid',
            [$shop, $language],
        );

        self::assertSame($this->expectedMains($shop, $language), array_map(static fn (mixed $gross): string => \sprintf('%.4F', $gross), $stored));
    }

    #[DataProvider('targets')]
    public function testVariantRowsAreExactlyTheVisibleVariants(int $shop, string $language): void
    {
        $criteria = (new VisibilityCriteria())->variant($shop, self::NOW, 'v', $language, 'de');

        $expected = array_map(intval(...), $this->connection->fetchFirstColumn('SELECT v.id FROM tl_consho_product v WHERE '.$criteria->sql.' ORDER BY v.id', $criteria->params));
        $stored = array_map(intval(...), $this->connection->fetchFirstColumn('SELECT pid FROM tl_consho_product_visibility WHERE shop = ? AND language = ? AND variant = 1 ORDER BY pid', [$shop, $language]));

        self::assertSame($expected, $stored);
    }

    public function testTheCatalogueCoversTheCasesItClaims(): void
    {
        // Otherwise the equality test checks a catalogue in which the difficult
        // cases are silently missing: a different from-price for 10 per target.
        self::assertSame('8.0000', $this->expectedMains(1, 'de')[10] ?? null, 'de: Variante 12 mit eigenem Warnhinweis zählt');
        self::assertSame('10.0000', $this->expectedMains(1, 'fr')[10] ?? null, 'fr: Variante 12 ohne übersetzten Warnhinweis zählt nicht');
        self::assertSame('6.0000', $this->expectedMains(2, 'de')[10] ?? null, 'Shop 2: Variante 14 nur dort');
        self::assertSame('15.0000', $this->expectedMains(1, 'de')[20] ?? null, 'Mit Varianten zählt der eigene Preis nicht');
        self::assertArrayNotHasKey(30, $this->expectedMains(1, 'de'), 'Varianten ohne Preis: nicht bepreist');
        self::assertArrayNotHasKey(40, $this->expectedMains(1, 'fr'), 'Ohne Übersetzung nicht in fr');
    }

    /**
     * The expected value, computed independently of VisibilityRows.
     *
     * @return array<int, string> main product id => from price (gross)
     */
    private function expectedMains(int $shop, string $language): array
    {
        $criteria = new VisibilityCriteria();
        $main = $criteria->mainProduct($shop, self::NOW, 'p', $language, 'de');
        $variant = $criteria->variant($shop, self::NOW, 'v', $language, 'de');

        $visibleMains = array_map(intval(...), $this->connection->fetchFirstColumn('SELECT p.id FROM tl_consho_product p WHERE '.$main->sql.' ORDER BY p.id', $main->params));
        $visibleVariants = $this->connection->fetchAllKeyValue('SELECT v.id, v.pid FROM tl_consho_product v WHERE '.$variant->sql, $variant->params);
        $withVariants = array_flip(array_map(intval(...), $this->connection->fetchFirstColumn('SELECT DISTINCT pid FROM tl_consho_product WHERE pid > 0')));
        $prices = $this->connection->fetchAllKeyValue("SELECT pid, gross FROM tl_consho_product_list_price WHERE currency = 'EUR'");

        $expected = [];

        foreach ($visibleMains as $id) {
            if (isset($withVariants[$id])) {
                $candidates = [];

                foreach ($visibleVariants as $variantId => $parentId) {
                    if ((int) $parentId === $id && isset($prices[$variantId])) {
                        $candidates[] = (float) $prices[$variantId];
                    }
                }
            } else {
                $candidates = isset($prices[$id]) ? [(float) $prices[$id]] : [];
            }

            if ([] !== $candidates) {
                $expected[$id] = \sprintf('%.4F', min($candidates));
            }
        }

        return $expected;
    }

    private function catalogue(): void
    {
        // 1: visible in both shops, translated.
        $this->main(1, shops: [1, 2], price: '12.0000', translated: true);
        // 2: without a price. 3: unpublished. 4: start in the future. 5: shop 2 only.
        $this->main(2, shops: [1]);
        $this->main(3, shops: [1], price: '12.0000', published: false);
        $this->main(4, shops: [1], price: '12.0000', start: (string) (self::NOW + 3600));
        $this->main(5, shops: [2], price: '7.0000');
        // A price in a foreign currency does not help.
        $this->insertListPrice(['pid' => 2, 'currency' => 'CHF', 'country' => 'AT', 'net' => '3.0000', 'gross' => '3.0000']);

        // 10: variants with different prices and visibilities.
        $this->main(10, shops: [1, 2], translated: true);
        $this->variant(11, 10, price: '10.0000');
        $this->variant(12, 10, price: '8.0000', safetyWarnings: 'Achtung, heiß');
        $this->variant(13, 10, price: '5.0000', published: false);
        $this->variant(14, 10, price: '6.0000');
        $this->insertProductShop(['pid' => 14, 'shop' => 2]);

        // 20: own price, but variants — only their price counts.
        $this->main(20, shops: [1], price: '5.0000', translated: true);
        $this->variant(21, 20, price: '15.0000');

        // 30: own price, variants without a price — not priced.
        $this->main(30, shops: [1], price: '9.0000', translated: true);
        $this->variant(31, 30);
        $this->variant(32, 30);

        // 40: without a translation.
        $this->main(40, shops: [1], price: '4.0000');

        // 50: all variants unpublished.
        $this->main(50, shops: [1], translated: true);
        $this->variant(51, 50, price: '3.0000', published: false);
    }

    /**
     * @param list<int> $shops
     */
    private function main(int $id, array $shops, string|null $price = null, bool $published = true, string $start = '', bool $translated = false): void
    {
        $this->insertProduct(['id' => $id, 'pid' => 0, 'title' => 'Produkt '.$id, 'published' => $published ? 1 : 0, 'start' => $start]);

        foreach ($shops as $shop) {
            $this->insertProductShop(['pid' => $id, 'shop' => $shop]);
        }

        if (null !== $price) {
            $this->insertListPrice(['pid' => $id, 'currency' => 'EUR', 'country' => 'AT', 'net' => $price, 'gross' => $price]);
        }

        if ($translated) {
            $this->insertTranslation(['pid' => $id, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => 'Produit '.$id, 'alias' => 'produit-'.$id]);
        }
    }

    private function variant(int $id, int $pid, string|null $price = null, bool $published = true, string|null $safetyWarnings = null): void
    {
        $this->insertProduct(['id' => $id, 'pid' => $pid, 'title' => 'Variante '.$id, 'published' => $published ? 1 : 0, 'safetyWarnings' => $safetyWarnings]);

        if (null !== $price) {
            $this->insertListPrice(['pid' => $id, 'currency' => 'EUR', 'country' => 'AT', 'net' => $price, 'gross' => $price]);
        }
    }
}

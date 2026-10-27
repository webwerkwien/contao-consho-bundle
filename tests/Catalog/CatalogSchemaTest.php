<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;

/**
 * A check of the trait: it must model the three deviations that once hid a
 * real bug — NULL instead of an empty string for the warnings,
 * `start`/`stop` as a string, and the real unique index on the translation
 * table.
 */
class CatalogSchemaTest extends TestCase
{
    use CatalogSchema;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($connection);
    }

    public function testAProductWithoutSafetyWarningsIsNullNotEmpty(): void
    {
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter']);

        self::assertNull($this->fetchColumn('tl_consho_product', 'safetyWarnings', 1));
    }

    public function testStartDefaultsToAnEmptyString(): void
    {
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter']);

        self::assertSame('', $this->fetchColumn('tl_consho_product', 'start', 1));
    }

    public function testADuplicateTranslationRowIsRejectedByTheDatabase(): void
    {
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter']);
        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => 'Amer suédois']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertTranslation(['pid' => 1, 'ptable' => 'tl_consho_product', 'language' => 'fr', 'title' => 'Doppelt']);
    }

    public function testAVariantRowOfTheVisibilityProjectionHasNoPrice(): void
    {
        $this->insertVisibility(['pid' => 2, 'main' => 1, 'variant' => 1, 'shop' => 1, 'language' => 'de']);

        $id = (int) $this->catalogConnection->fetchOne('SELECT id FROM tl_consho_product_visibility WHERE pid = 2');

        self::assertNull($this->fetchColumn('tl_consho_product_visibility', 'gross', $id));
        self::assertNull($this->fetchColumn('tl_consho_product_visibility', 'net', $id));
    }

    public function testDieselbeSpracheInZweiWaehrungenSindZweiZeilen(): void
    {
        // Since C-1c, task 5b, the key carries the currency: the DACH case
        // (example.de in euros, example.ch in francs, both in German) is
        // two rows. If the test schema here were more lenient than the table,
        // it would hide exactly the case this is about.
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'shop' => 1, 'language' => 'de', 'currency' => 'EUR', 'gross' => '12.0000', 'net' => '10.0000']);
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'shop' => 1, 'language' => 'de', 'currency' => 'CHF', 'gross' => '15.0000', 'net' => '12.5000']);

        self::assertSame(2, (int) $this->catalogConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility WHERE pid = 1'));
    }

    public function testAnArticleHasOneVisibilityRowPerShopLanguageAndCurrency(): void
    {
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'shop' => 1, 'language' => 'de', 'gross' => '12.0000', 'net' => '10.0000']);
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'shop' => 1, 'language' => 'fr', 'gross' => '12.0000', 'net' => '10.0000']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertVisibility(['pid' => 1, 'main' => 1, 'shop' => 1, 'language' => 'de', 'gross' => '13.0000', 'net' => '11.0000']);
    }

    public function testAProjectionMarkerExistsOnce(): void
    {
        $this->catalogConnection->insert('tl_consho_projection_state', ['name' => 'visibility_boundaries', 'value' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->catalogConnection->insert('tl_consho_projection_state', ['name' => 'visibility_boundaries', 'value' => 2]);
    }

    private function fetchColumn(string $table, string $column, int $id): mixed
    {
        // The trait holds the connection as its own property; since this class
        // uses it, access here is the same as in every inserter.
        return $this->catalogConnection->fetchOne(\sprintf('SELECT %s FROM %s WHERE id = ?', $column, $table), [$id]);
    }
}

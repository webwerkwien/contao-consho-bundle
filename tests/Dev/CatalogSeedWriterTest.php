<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dev;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Dev\CatalogSeedPlan;
use Webwerkwien\ContaoConshoBundle\Dev\CatalogSeedWriter;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

class CatalogSeedWriterTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        // Maintained by hand, as on a real installation: must never be touched.
        $this->insertProduct(['id' => 1, 'title' => 'Schwedenbitter', 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 1]);
        $this->insertPrice(['pid' => 1, 'currency' => 'EUR', 'net' => '10.8333']);
        $this->insertPropertyGroup(['id' => 1, 'title' => 'Volumen', 'alias' => 'volume']);
        $this->insertPropertyOption(['id' => 11, 'pid' => 1, 'title' => '250 ml', 'alias' => '250-ml']);
        $this->insertProductProperty(['pid' => 1, 'optionId' => 11]);
    }

    public function testWritesEveryRowOfThePlan(): void
    {
        $plan = $this->plan();
        $expected = $this->expectedRows($plan);

        $writer = new CatalogSeedWriter($this->connection);
        $written = $writer->writeGroups($plan) + $writer->write($plan);

        self::assertSame($expected['total'], $written);
        self::assertSame($expected['products'] + 1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product'));
        self::assertSame($expected['prices'] + 1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_price'));
    }

    public function testTheBatchSizeChangesNothing(): void
    {
        $plan = $this->plan();
        $writer = new CatalogSeedWriter($this->connection);

        $writer->write($plan, batch: 7);
        $small = $this->snapshot();

        $writer->remove();
        $writer->write($plan, batch: 1000);

        self::assertSame($small, $this->snapshot());
    }

    public function testRemovesOnlyTheSeed(): void
    {
        $plan = $this->plan();
        $writer = new CatalogSeedWriter($this->connection);
        $writer->writeGroups($plan);
        $writer->write($plan);
        $this->insertListPrice(['pid' => CatalogSeedPlan::PRODUCT_OFFSET, 'currency' => 'EUR', 'country' => 'AT', 'net' => '1', 'gross' => '1']);

        self::assertGreaterThan(0, $writer->remove());

        foreach (['tl_consho_product', 'tl_consho_product_shop', 'tl_consho_price', 'tl_consho_product_property', 'tl_consho_property_group', 'tl_consho_property_option'] as $table) {
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM $table"), $table);
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_translation'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_list_price'));
        self::assertSame('Schwedenbitter', $this->connection->fetchOne('SELECT title FROM tl_consho_product WHERE id = 1'));
    }

    public function testReportsProgressPerBatch(): void
    {
        $seen = [];

        (new CatalogSeedWriter($this->connection))->write($this->plan(), 50, 0, static function (int $done) use (&$seen): void {
            $seen[] = $done;
        });

        self::assertNotEmpty($seen);
        self::assertSame(60, end($seen));
        self::assertSame($seen, array_values(array_unique($seen)));
    }

    private function plan(): CatalogSeedPlan
    {
        return new CatalogSeedPlan(60, 7, 1, 'EUR', 'CHF', 'fr', 1_790_000_000);
    }

    /**
     * @return array{products: int, prices: int, total: int}
     */
    private function expectedRows(CatalogSeedPlan $plan): array
    {
        $products = 0;
        $prices = 0;
        $total = 0;

        foreach ($plan->groups() as $group) {
            $total += 1 + \count($group['options']);
        }

        for ($n = 0; $n < $plan->products; ++$n) {
            $rows = $plan->rows($n);
            $products += \count($rows['products']);
            $prices += \count($rows['prices']);
            $total += array_sum(array_map(\count(...), $rows));
        }

        return ['products' => $products, 'prices' => $prices, 'total' => $total];
    }

    /**
     * @return array<string, int>
     */
    private function snapshot(): array
    {
        $counts = [];

        foreach (['tl_consho_product', 'tl_consho_product_property', 'tl_consho_product_option', 'tl_consho_product_shop', 'tl_consho_price', 'tl_consho_translation'] as $table) {
            $counts[$table] = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM $table");
        }

        return $counts;
    }
}

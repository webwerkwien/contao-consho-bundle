<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Digital\KeyPoolHandler;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;

/**
 * Creates the stock rows of products that existed before Block G.
 *
 * StockRows runs when a product is saved. A product with a key pool from
 * Block F would otherwise count as unlimited until somebody saves it — and
 * be orderable with an empty pool, which G10 ends. No test would see it:
 * the tests always save first.
 *
 * Runs for every main product that needs rows and has no active one — kept
 * stock of a physical good, or a key pool of a digital one (its own delivery
 * or a variant's) — and asks StockRows, the one rule, to create them. A
 * product the rule gives no row (a digital product with "keep stock" ticked)
 * is not a candidate, so the migration does not come back on every run.
 */
final class StockRowsMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $connection,
        private readonly StockRows $rows,
    ) {
    }

    public function shouldRun(): bool
    {
        $schema = $this->connection->createSchemaManager();

        if (!$schema->tablesExist(['tl_consho_stock', 'tl_consho_product', 'tl_consho_product_type'])) {
            return false;
        }

        $columns = array_change_key_case($schema->listTableColumns('tl_consho_product'));

        return isset($columns['stocktracked'], $columns['digitaldelivery']) && [] !== $this->candidates();
    }

    public function run(): MigrationResult
    {
        $candidates = $this->candidates();
        $now = time();

        foreach ($candidates as $productId) {
            $this->rows->sync($productId, $now);
        }

        return $this->createResult(true, \sprintf('Stock rows created for %d products.', \count($candidates)));
    }

    /**
     * @return list<int>
     */
    private function candidates(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT p.id
               FROM tl_consho_product p
               LEFT JOIN tl_consho_product_type t ON t.id = p.productType AND p.productType > 0
              WHERE p.pid = 0
                AND (
                    (COALESCE(t.kind, 'physical') = 'physical' AND p.stockTracked = 1)
                    OR (t.kind = 'digital' AND (p.digitalDelivery = :key OR EXISTS (SELECT 1 FROM tl_consho_product v WHERE v.pid = p.id AND v.digitalDelivery = :key)))
                )
                AND NOT EXISTS (SELECT 1 FROM tl_consho_stock s WHERE s.main = p.id AND s.active = 1)
              ORDER BY p.id",
            ['key' => KeyPoolHandler::getType()],
        ));
    }
}

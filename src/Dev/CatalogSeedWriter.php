<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Dev;

use Doctrine\DBAL\Connection;

/**
 * Writes a CatalogSeedPlan and removes it again (plan B-9). Development only.
 *
 * Straight through DBAL, past DC_Table: no versions, no recycle bin, no
 * callbacks. For a throwaway catalogue of 100,000 products that is the point —
 * and the reason the commands exist only in the dev environment.
 *
 * Multi-row INSERTs, one transaction per batch, and an optional pause between
 * batches: on a MariaDB shared with other websites the load comes in portions
 * instead of one long write.
 */
final class CatalogSeedWriter
{
    private const TABLES = [
        'products' => 'tl_consho_product',
        'properties' => 'tl_consho_product_property',
        'options' => 'tl_consho_product_option',
        'shops' => 'tl_consho_product_shop',
        'prices' => 'tl_consho_price',
        'translations' => 'tl_consho_translation',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function writeGroups(CatalogSeedPlan $plan): int
    {
        $groups = [];
        $options = [];

        foreach ($plan->groups() as $group) {
            $groups[] = ['id' => $group['id'], 'tstamp' => $plan->now, 'title' => $group['title'], 'alias' => $group['alias'], 'filterable' => 1];

            foreach ($group['options'] as $sorting => $option) {
                $options[] = ['id' => $option['id'], 'pid' => $group['id'], 'tstamp' => $plan->now, 'title' => $option['title'], 'alias' => $option['alias'], 'sorting' => ($sorting + 1) * 128];
            }
        }

        $this->insert('tl_consho_property_group', $groups);
        $this->insert('tl_consho_property_option', $options);

        return \count($groups) + \count($options);
    }

    /**
     * @param (\Closure(int): void)|null $progress called with the number of main products written so far
     *
     * @return int rows written
     */
    public function write(CatalogSeedPlan $plan, int $batch = 1000, int $pauseMicroseconds = 0, \Closure|null $progress = null): int
    {
        $buffer = array_fill_keys(array_keys(self::TABLES), []);
        $written = 0;

        for ($n = 0; $n < $plan->products; ++$n) {
            foreach ($plan->rows($n) as $key => $rows) {
                array_push($buffer[$key], ...$rows);
            }

            if (max(array_map(\count(...), $buffer)) >= $batch || $n === $plan->products - 1) {
                $written += $this->flush($buffer);
                $buffer = array_fill_keys(array_keys(self::TABLES), []);

                if (null !== $progress) {
                    $progress($n + 1);
                }

                if ($pauseMicroseconds > 0 && $n < $plan->products - 1) {
                    usleep($pauseMicroseconds);
                }
            }
        }

        return $written;
    }

    /**
     * Removes every row in the seed ranges, and nothing else.
     *
     * @return int rows deleted
     */
    public function remove(): int
    {
        $product = ['from' => CatalogSeedPlan::PRODUCT_OFFSET];
        $option = ['from' => CatalogSeedPlan::OPTION_OFFSET];

        return $this->connection->transactional(fn (Connection $c): int => (int) $c->executeStatement("DELETE FROM tl_consho_translation WHERE ptable = 'tl_consho_product' AND pid >= :from", $product)
            + (int) $c->executeStatement('DELETE FROM tl_consho_product_list_price WHERE pid >= :from', $product)
            + (int) $c->executeStatement('DELETE FROM tl_consho_price WHERE pid >= :from', $product)
            + (int) $c->executeStatement('DELETE FROM tl_consho_product_shop WHERE pid >= :from', $product)
            + (int) $c->executeStatement('DELETE FROM tl_consho_product_option WHERE pid >= :from', $product)
            + (int) $c->executeStatement('DELETE FROM tl_consho_product_property WHERE pid >= :from', $product)
            + (int) $c->executeStatement('DELETE FROM tl_consho_product WHERE id >= :from', $product)
            + (int) $c->executeStatement('DELETE FROM tl_consho_property_option WHERE id >= :from', $option)
            + (int) $c->executeStatement('DELETE FROM tl_consho_property_group WHERE id >= :from', $option));
    }

    /**
     * @param array<string, list<array<string, int|string>>> $buffer
     */
    private function flush(array $buffer): int
    {
        return $this->connection->transactional(function () use ($buffer): int {
            $written = 0;

            foreach (self::TABLES as $key => $table) {
                $written += $this->insert($table, $buffer[$key]);
            }

            return $written;
        });
    }

    /**
     * @param list<array<string, int|string>> $rows all with the same keys
     */
    private function insert(string $table, array $rows): int
    {
        if ([] === $rows) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $placeholders = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
        $values = [];

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $values[] = $row[$column];
            }
        }

        return (int) $this->connection->executeStatement(
            \sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, \count($rows), $placeholders))),
            $values,
        );
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The property values of a product — as description (`tl_consho_product_property`)
 * and as variant axis (`tl_consho_product_option`). Two tables on purpose, see
 * ProductPropertiesListener.
 *
 * Two callers, as ProductShops: the mask, whose options offer only existing
 * values, uses assign…(); a console command (Connect Stufe 2, CW-4) uses
 * set…(), which also checks that the product and the values exist. Both refuse
 * a variant with the same axis values as a sister.
 */
class ProductProperties
{
    public const PROPERTIES = 'tl_consho_product_property';

    public const OPTIONS = 'tl_consho_product_option';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<int> $optionIds
     *
     * @throws ProductPropertiesRefused
     */
    public function setProperties(int $productId, array $optionIds): void
    {
        $this->assertWritable($productId, $optionIds);
        $this->assignProperties($productId, $optionIds);
    }

    /**
     * @param list<int> $optionIds
     *
     * @throws ProductPropertiesRefused
     */
    public function setVariantOptions(int $productId, array $optionIds): void
    {
        $this->assertWritable($productId, $optionIds);
        $this->assignVariantOptions($productId, $optionIds);
    }

    /**
     * @param list<int> $optionIds
     */
    public function assignProperties(int $productId, array $optionIds): void
    {
        $this->sync(self::PROPERTIES, $productId, self::normalised($optionIds));
    }

    /**
     * @param list<int> $optionIds
     *
     * @throws ProductPropertiesRefused when a sister variant has the same values
     */
    public function assignVariantOptions(int $productId, array $optionIds): void
    {
        $selected = self::normalised($optionIds);
        $this->refuseDuplicate($productId, $selected);
        $this->sync(self::OPTIONS, $productId, $selected);
    }

    /**
     * @return list<int>
     */
    public function propertiesOf(int $productId): array
    {
        return $this->assigned(self::PROPERTIES, $productId);
    }

    /**
     * @return list<int>
     */
    public function variantOptionsOf(int $productId): array
    {
        return $this->assigned(self::OPTIONS, $productId);
    }

    /**
     * @param list<int> $optionIds
     */
    private function assertWritable(int $productId, array $optionIds): void
    {
        if (false === $this->connection->fetchOne('SELECT id FROM tl_consho_product WHERE id = ?', [$productId])) {
            throw new ProductPropertiesRefused(\sprintf('There is no product %d.', $productId));
        }

        $wanted = self::normalised($optionIds);
        $known = [] === $wanted ? [] : array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_consho_property_option WHERE id IN (?)',
            [$wanted],
            [ArrayParameterType::INTEGER],
        ));

        if ([] !== ($unknown = array_diff($wanted, $known))) {
            throw new ProductPropertiesRefused(\sprintf('There is no property value %s.', implode(', ', $unknown)));
        }
    }

    /**
     * Two variants of one main product with the same axis values: the
     * variant matrix took the first in silence (spec Abo, Abo-6 —
     * a copied variant comes without its values, ProductCopyListener, and the
     * operator sets them). Refused, naming the sister. An empty set is
     * incomplete, not a duplicate.
     *
     * @param list<int> $selected
     */
    private function refuseDuplicate(int $productId, array $selected): void
    {
        $mainId = $productId > 0 ? (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_product WHERE id = ?', [$productId]) : 0;

        if ([] === $selected || $mainId < 1) {
            return;
        }

        sort($selected);

        $sisters = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT o.pid, o.optionId FROM tl_consho_product_option o
               JOIN tl_consho_product p ON p.id = o.pid
              WHERE p.pid = ? AND p.id <> ?',
            [$mainId, $productId],
        ) as $row) {
            $sisters[(int) $row['pid']][] = (int) $row['optionId'];
        }

        foreach ($sisters as $sisterId => $options) {
            sort($options);

            if ($options === $selected) {
                throw new ProductPropertiesRefused(\sprintf((string) ($GLOBALS['TL_LANG']['tl_consho_product']['variantDuplicate'] ?? 'Variant %s has the same values.'), $sisterId));
            }
        }
    }

    /**
     * A diff rather than delete-all-then-insert: otherwise every save of an
     * unrelated field churns every assignment, and the UNIQUE index turns an
     * ordering mistake into a hard error rather than a quiet duplicate.
     *
     * @param list<int> $selected
     */
    private function sync(string $table, int $productId, array $selected): void
    {
        if ($productId < 1) {
            return;
        }

        $current = $this->assigned($table, $productId);

        foreach (array_diff($current, $selected) as $optionId) {
            $this->connection->delete($table, ['pid' => $productId, 'optionId' => $optionId]);
        }

        foreach (array_diff($selected, $current) as $optionId) {
            $this->connection->insert($table, [
                'pid' => $productId,
                'optionId' => $optionId,
                'tstamp' => time(),
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function assigned(string $table, int $productId): array
    {
        if ($productId < 1) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            \sprintf('SELECT optionId FROM %s WHERE pid = ? ORDER BY optionId', $table),
            [$productId],
        ));
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<int>
     */
    private static function normalised(array $ids): array
    {
        $ids = array_values(array_unique(array_map(intval(...), array_filter($ids, is_scalar(...)))));

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }
}

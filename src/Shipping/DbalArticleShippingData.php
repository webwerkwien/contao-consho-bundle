<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;

/**
 * Reads weight and measurements for shipping (E-4, V1).
 *
 * The fields have been on the product since block A (spec A 284) and nothing
 * read them. The rules, as decided (V1):
 *
 *  - Weight is the SHIPPING weight — packaging included —, the product weight
 *    where none is set.
 *  - A variant's own value counts where it has one, otherwise its main
 *    product's: a T-shirt in six sizes is weighed once, not six times. Per
 *    level first (shipping weight, else weight), then variant before main
 *    product — a variant with only a product weight is not overruled by a
 *    main product's shipping weight, because the variant is what is packed.
 *  - Measurements field by field, the same way.
 *
 * Not frozen on the order: a carrier module that prints labels reads the
 * order itself, and until one exists there is nothing to freeze for.
 *
 * ── Kind (F-1, F13; DL1) ────────────────────────────────────────────────
 *
 * From `kind` of the product TYPE — the main product's type for a
 * variant, as the product page shows it (ProductDetailAssembler), the
 * variant's own only where the main product has none. No type at all counts
 * as physical: the field defaults to physical for the same reason (it switches
 * legal duties on), and wrongly calling a parcel digital would drop the
 * shipping step for it. Unlike weight, THIS is frozen on the order line
 * (DbalOrderProductRepository), because withdrawal and delivery depend on it.
 */
final class DbalArticleShippingData implements ArticleShippingDataInterface
{
    private const DIMENSIONS = ['length', 'width', 'height'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function of(array $articleIds): array
    {
        if ([] === $articleIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.id, a.weight, a.shippingWeight, a.length, a.width, a.height,
                    p.weight AS mainWeight, p.shippingWeight AS mainShippingWeight,
                    p.length AS mainLength, p.width AS mainWidth, p.height AS mainHeight,
                    COALESCE(tm.kind, ta.kind, \'physical\') AS kind
               FROM tl_consho_product a
               LEFT JOIN tl_consho_product p ON p.id = a.pid AND a.pid > 0
               LEFT JOIN tl_consho_product_type tm ON tm.id = p.productType
               LEFT JOIN tl_consho_product_type ta ON ta.id = a.productType
              WHERE a.id IN (?)',
            [array_values(array_unique($articleIds))],
            [ArrayParameterType::INTEGER],
        );

        $data = [];

        foreach ($rows as $row) {
            $own = self::firstPositive($row['shippingWeight'] ?? null, $row['weight'] ?? null);
            $main = self::firstPositive($row['mainShippingWeight'] ?? null, $row['mainWeight'] ?? null);

            $entry = ['weight' => self::firstPositive($own, $main)];

            foreach (self::DIMENSIONS as $field) {
                $entry[$field] = self::firstPositive($row[$field] ?? null, $row['main'.ucfirst($field)] ?? null);
            }

            $entry['kind'] = ProductKind::stored((string) $row['kind'])->value;

            $data[(int) $row['id']] = $entry;
        }

        return $data;
    }

    private static function firstPositive(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_numeric($value) && BigDecimal::of((string) $value)->isPositive()) {
                return (string) BigDecimal::of((string) $value)->toScale(4, RoundingMode::HalfUp);
            }
        }

        return '0.0000';
    }
}

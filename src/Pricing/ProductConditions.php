<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether an article meets the product conditions of a rule (block H):
 * catalogue price rules (H-3) and cart rules (H-4) ask the same question, so
 * it is answered once.
 *
 * Every condition stated has to match; an empty one matches anything. The
 * main product decides products, manufacturer and product type; a filter
 * property counts on the variant or on its main product.
 *
 * Attributes are read once per article and request.
 */
class ProductConditions implements ResetInterface
{
    /** @var array<int, array{manufacturer: int, productType: int, options: list<int>}> */
    private array $attributes = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{products: list<int>, manufacturers: list<int>, productTypes: list<int>, propertyOptions?: list<int>} $conditions
     */
    public function matches(array $conditions, int $articleId, int $mainProductId): bool
    {
        $properties = $conditions['propertyOptions'] ?? [];

        if ([] !== $conditions['products'] && !\in_array($mainProductId, $conditions['products'], true)) {
            return false;
        }

        if ([] === $conditions['manufacturers'] && [] === $conditions['productTypes'] && [] === $properties) {
            return true;
        }

        $main = $this->attributesOf($mainProductId);

        if ([] !== $conditions['manufacturers'] && !\in_array($main['manufacturer'], $conditions['manufacturers'], true)) {
            return false;
        }

        if ([] !== $conditions['productTypes'] && !\in_array($main['productType'], $conditions['productTypes'], true)) {
            return false;
        }

        if ([] !== $properties) {
            $options = $articleId === $mainProductId ? $main['options'] : [...$main['options'], ...$this->attributesOf($articleId)['options']];

            if ([] === array_intersect($properties, $options)) {
                return false;
            }
        }

        return true;
    }

    public function reset(): void
    {
        $this->attributes = [];
    }

    /**
     * @return array{manufacturer: int, productType: int, options: list<int>}
     */
    private function attributesOf(int $productId): array
    {
        if (!isset($this->attributes[$productId])) {
            $row = $this->connection->fetchAssociative('SELECT manufacturer, productType FROM tl_consho_product WHERE id = ?', [$productId]);

            $this->attributes[$productId] = [
                'manufacturer' => (int) ($row['manufacturer'] ?? 0),
                'productType' => (int) ($row['productType'] ?? 0),
                'options' => array_map('intval', $this->connection->fetchFirstColumn('SELECT optionId FROM tl_consho_product_property WHERE pid = ?', [$productId])),
            ];
        }

        return $this->attributes[$productId];
    }
}

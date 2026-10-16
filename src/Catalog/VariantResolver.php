<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\CoreBundle\Framework\ContaoFramework;
use Webwerkwien\ContaoConshoBundle\Model\ProductModel;

/**
 * Tells main products and variants apart.
 *
 * Both live in tl_consho_product; a variant is a row whose `pid` points at its
 * main product. Variants are never nested — see docs/block-a-fundament.md,
 * section 3.1.
 */
final class VariantResolver
{
    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    public function isVariant(ProductModel $product): bool
    {
        return (int) $product->pid > 0;
    }

    public function isMainProduct(ProductModel $product): bool
    {
        return !$this->isVariant($product);
    }

    /**
     * @return list<ProductModel>
     */
    public function findVariants(ProductModel $product): array
    {
        // A variant never has variants of its own. Guarding here rather than
        // relying on an empty result keeps the rule visible and saves a query.
        if ($this->isVariant($product)) {
            return [];
        }

        $collection = $this->framework
            ->getAdapter(ProductModel::class)
            ->findByPid((int) $product->id)
        ;

        if ($collection === null) {
            return [];
        }

        return array_values($collection->getModels());
    }

    public function findMainProduct(ProductModel $variant): ?ProductModel
    {
        if (!$this->isVariant($variant)) {
            return null;
        }

        $main = $this->framework
            ->getAdapter(ProductModel::class)
            ->findByPk((int) $variant->pid)
        ;

        return $main instanceof ProductModel ? $main : null;
    }
}

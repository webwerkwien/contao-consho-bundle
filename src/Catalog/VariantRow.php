<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * A visible variant as the variant choice needs it: which one, and what makes
 * it that one.
 *
 * Nothing else. Price, images, units and safety texts come from their own
 * sources (ArticlePriceResolver, ProductDetailRepository, ProductSafetyResolver)
 * — this row only answers "which option in which group".
 */
final class VariantRow
{
    /**
     * @param array<int, int> $options groupId => optionId; a group the variant
     *                                 has no option in is simply missing
     */
    public function __construct(
        public readonly int $id,
        public readonly array $options,
    ) {
    }
}

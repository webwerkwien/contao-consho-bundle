<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * Where a basket line gets its price. ArticlePriceResolver implements it.
 *
 * An interface rather than the class itself so that the basket can be priced
 * without building the whole pricing stack — and so that C-4, which adds the
 * customer group, has one named place to look.
 *
 * It lives in Pricing, beside its implementation and beside the value it
 * returns, the way every other seam of this bundle does (PriceRepositoryInterface,
 * ArticleTreeInterface, FilterCountsInterface). Putting it in Cart would make
 * Pricing depend on Cart while Cart already depends on Pricing, and a cycle
 * between the two is a cost with nothing bought for it.
 */
interface CartLinePriceSource
{
    /**
     * @param string    $quantity       decimal string — the REAL quantity, so a scale price applies
     * @param list<int> $customerGroups   the visitor's groups — empty for a guest
     * @param int       $subscriptionRule 0 for the one-time sale, else the line's rule (Abo-2)
     *
     * @return CartLinePrice|null null when the article has no price in this currency
     */
    public function forCartLine(int $articleId, int $mainProductId, string $currency, string $quantity, int $now, array $customerGroups = [], int $subscriptionRule = 0): CartLinePrice|null;
}

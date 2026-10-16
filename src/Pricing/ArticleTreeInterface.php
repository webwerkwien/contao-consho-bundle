<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

interface ArticleTreeInterface
{
    /**
     * Every article whose list price a change to these products can move:
     * each product that still exists, and for a main product all its variants
     * — a variant without a price of its own inherits the main product's.
     *
     * @param list<int> $productIds
     *
     * @return array<int, int> article id => its main product id (its own id for a main product)
     */
    public function articlesOf(array $productIds): array;
}

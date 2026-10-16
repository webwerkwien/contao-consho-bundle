<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

interface ArticleTaxClassesInterface
{
    /**
     * The tax class of the article that is sold — a variant's own, never its
     * main product's: the field is mandatory on every row, and OrderFactory
     * taxes the line with it. 0 when the article is gone.
     */
    public function taxClassOf(int $articleId): int;
}

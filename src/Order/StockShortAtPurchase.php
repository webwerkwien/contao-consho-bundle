<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * An article was not there in the ordered quantity at the purchase click
 * (Block G, G4, G5). The basket holds nothing back, so the last piece goes to
 * whoever clicks first; the other purchase is rolled back and the buyer is
 * sent back with a notice, the basket unchanged.
 */
final class StockShortAtPurchase extends \RuntimeException
{
    public static function forArticle(int $articleId): self
    {
        return new self(\sprintf('Article ID %d is not in stock in the ordered quantity.', $articleId));
    }
}

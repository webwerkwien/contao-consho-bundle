<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

/**
 * A basket as it is shown — lines, totals, and the articles that could not be
 * priced.
 *
 * `unpriced` is not an error state: a product can lose its price while
 * something sits in a basket. The basket page names those lines and offers to
 * remove them; the checkout will refuse to go on while one is there (C-5).
 *
 * One list, not two: an article with no price row and an article whose tax
 * class has no rate in this country are the same answer to the visitor — not
 * sellable, no amount to show. ArticlePriceResolver::forArticle() already
 * folds both into one null for the product page; a basket that split them
 * would have to invent two sentences for one situation.
 */
final class CartView
{
    /**
     * `unpricedItems` names the same lines by their LINE id (Abo-2): one
     * article can now stand in two lines, one per plan, so an article id no
     * longer says which line is blocking.
     *
     * @param list<CartLine> $lines
     * @param list<int>      $unpriced      article ids without a price
     * @param list<int>      $unpricedItems line ids without a price, in the same order
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $unpriced,
        public readonly string $quantity,
        public readonly string $net,
        public readonly string $gross,
        public readonly string $currency,
        public readonly array $unpricedItems = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->lines && [] === $this->unpriced;
    }

    public function count(): int
    {
        return \count($this->lines) + \count($this->unpriced);
    }

    /**
     * The same basket under reverse charge: no tax, so every gross is its net.
     *
     * 🔴 Before C-12 the checkout priced the
     * lines with the delivery country's rate and took the tax off shipping
     * and payment amounts only. The review showed a German customer's
     * article at net + 19 %, and OrderFactory — which does apply reverse
     * charge per line — came to a different total, so every reverse charge
     * order was refused by TotalDisagreesWithCheckout since C-7.
     *
     * The unpriced list stays as it is: an article whose class has no rate in
     * the delivery country is still not sellable there, and the checkout does
     * not become ready while one is listed. OrderFactory never gets to see
     * such a line — under reverse charge it does not resolve a rate at all.
     */
    public function withoutTax(): self
    {
        return new self(
            array_map(
                static fn (CartLine $line): CartLine => new CartLine(
                    $line->itemId,
                    $line->articleId,
                    $line->mainProductId,
                    $line->quantity,
                    $line->unitNet,
                    $line->lineNet,
                    $line->lineNet,
                    $line->taxClassId,
                    $line->subscriptionRule,
                    $line->renews,
                    $line->taxCountry,
                    $line->source,
                    $line->kind,
                ),
                $this->lines,
            ),
            $this->unpriced,
            $this->quantity,
            $this->net,
            $this->net,
            $this->currency,
            $this->unpricedItems,
        );
    }
}

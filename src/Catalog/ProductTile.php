<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * What a listing shows about one product.
 *
 * Every field is filled — no nulls to distinguish in a template. A template
 * that has to tell null from empty gets it wrong once and prints "null" on a
 * live shop; the same rule as ProductSafetyInfo in A-11.
 */
final class ProductTile
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $teaser,
        public readonly string $image,
        public readonly string $price,
        /**
         * True when the price is the cheapest of several variants, so the
         * template can write "ab 12,60 €". Printing a plain price there would
         * be a statement the shop cannot keep for every variant.
         */
        public readonly bool $isFrom = false,
        /**
         * The product's detail page; empty when the shop has none, and the
         * listing stays a listing. Not built by the repository: it knows
         * neither the product page nor how Contao writes an address.
         */
        public readonly string $url = '',
        /**
         * The price as a buyer reads it ("13,00 €"), in the page's language.
         * $price stays the raw decimal — for structured data and for templates
         * that format it their own way.
         */
        public readonly string $formattedPrice = '',
        /**
         * Whether this product can be bought straight from the list — no button
         * otherwise, the tile links to the product.
         *
         * False for a main product WITH variants: there is no buyable article as
         * long as nobody has chosen a variant, and a fallback rule ("which one do we
         * take?") would one day surprise somebody with the wrong bottle. Not the same
         * as `isFrom` — that only says whether the variants differ in PRICE, and two
         * variants at the same price are still two variants.
         */
        public readonly bool $isBuyable = false,
        /**
         * The quantity the button puts into the basket: the product's minimum
         * quantity, otherwise 1. Without decimals where none are needed —
         * `minPurchase` is `decimal(10,4)`, and "1,0000" in a form is wrong.
         */
        public readonly string $purchaseQuantity = '1',
        /**
         * Abo-2b, P88: 0 when $price is the one-time price, else the plan it
         * is the amount per billing period of — the controller adds "/ Monat".
         */
        public readonly int $subscriptionRule = 0,
        /** Abo-2b, P89: a one-time price whose product is also sold as a plan. */
        public readonly bool $alsoSubscription = false,
        /**
         * Block G (G7): the StockState value where stock is kept, '' where it
         * is not. With variants the best of the visible ones — the product
         * is available as long as one of them is.
         */
        public readonly string $stock = '',
        /**
         * Block H (H-2): the raw price struck through next to a promotion —
         * the lowest of the 30 days before it began; '' for none.
         */
        public readonly string $compare = '',
        /** The same, formatted by the controller (TilePrices::compares()). */
        public readonly string $formattedCompare = '',
    ) {
    }

    public function withStock(string $stock): self
    {
        return new self(
            $this->id,
            $this->title,
            $this->teaser,
            $this->image,
            $this->price,
            $this->isFrom,
            $this->url,
            $this->formattedPrice,
            $this->isBuyable,
            $this->purchaseQuantity,
            $this->subscriptionRule,
            $this->alsoSubscription,
            $stock,
            $this->compare,
            $this->formattedCompare,
        );
    }

    /**
     * The same tile with what only the controller knows.
     */
    public function withLink(string $url, string $formattedPrice, string $formattedCompare = ''): self
    {
        return new self(
            $this->id,
            $this->title,
            $this->teaser,
            $this->image,
            $this->price,
            $this->isFrom,
            $url,
            $formattedPrice,
            $this->isBuyable,
            $this->purchaseQuantity,
            $this->subscriptionRule,
            $this->alsoSubscription,
            $this->stock,
            $this->compare,
            $formattedCompare,
        );
    }
}

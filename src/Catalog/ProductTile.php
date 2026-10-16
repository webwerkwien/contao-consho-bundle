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
         * Ob dieses Produkt aus der Liste heraus gekauft werden kann
         * (Michael, 2026-09-22: „kein Knopf, Kachel verlinkt").
         *
         * Falsch für ein Hauptprodukt MIT Varianten: Dort gibt es keinen
         * kaufbaren Artikel, solange niemand eine Variante gewählt hat, und
         * eine Ersatzregel („welche nehmen wir?") würde irgendwann jemanden
         * mit der falschen Flasche überraschen. Nicht dasselbe wie `isFrom` —
         * das sagt nur, ob die Varianten sich im PREIS unterscheiden, und
         * zwei gleich teure Varianten sind immer noch zwei Varianten.
         */
        public readonly bool $isBuyable = false,
        /**
         * Die Menge, die der Knopf in den Warenkorb legt: die Mindestmenge
         * des Produkts, sonst 1. Ohne Nachkommastellen, wo keine nötig sind —
         * `minPurchase` ist `decimal(10,4)`, und „1,0000" in einem Formular
         * war schon einmal ein Befund (2026-09-21).
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

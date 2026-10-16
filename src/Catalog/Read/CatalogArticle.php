<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog\Read;

use Brick\Math\BigDecimal;
use Webwerkwien\ContaoConshoBundle\Pricing\OneTimePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\PurchaseLine;
use Webwerkwien\ContaoConshoBundle\Stock\StockState;

/**
 * One article the shop offers — a product without variants, or one visible
 * variant — as data, unformatted (Connect-1, CC2).
 *
 * Texts are plain text in the start point's language. `title` is the MAIN
 * product's, as on the page; a variant tells itself apart through `options`
 * (axis label => option label), and an exporter puts them together the way
 * its channel wants.
 *
 * `gtin` only where it is a valid number of THIS article — never inherited
 * (B-5), never one that fails its check digit: a wrong GTIN names somebody
 * else's product to a price comparison site.
 *
 * `stock` is null for an article whose stock is not kept: it is always
 * there, which is what a channel should be told.
 */
final class CatalogArticle
{
    /**
     * @param array<string, string> $options axis label => option label, in display order
     * @param list<string>          $images  absolute addresses of public files
     */
    public function __construct(
        public readonly int $articleId,
        public readonly int $mainId,
        public readonly int|null $groupId,
        public readonly string $title,
        public readonly array $options,
        public readonly string $teaser,
        public readonly string $description,
        public readonly string $url,
        public readonly array $images,
        public readonly string $sku,
        public readonly string $gtin,
        public readonly string $mpn,
        public readonly string $brand,
        public readonly string $productType,
        public readonly bool $physical,
        public readonly string $weight,
        public readonly StockState|null $stock,
        public readonly int|null $availableFrom,
        /*
         * The one-time offer at the context's tax country (K1, K2) — null for
         * an article without a price there or not sold once. The article
         * stays in the list: whether a channel wants it then is the
         * exporter's call (Google: leave it out).
         */
        public readonly OneTimePrice|null $price = null,
        // From the main product or the variant, the higher (assembler) — a
        // shipping method that cannot check an age may refuse the parcel.
        public readonly int $minimumAge = 0,
        /*
         * The unit set the base price is worked out from (assembler: the
         * variant's own or the main product's, as a set) — "500 ml, per
         * 100 ml". A channel wants the content and the reference, not only
         * the resulting amount.
         *
         * @var array{unit: string, purchaseUnit: string, referenceUnit: string}
         */
        public readonly array $units = ['unit' => '', 'purchaseUnit' => '0', 'referenceUnit' => '0'],
        /*
         * The smallest quantity the shop sells, without trailing zeros — and,
         * where it is more than one and there is a price, what that many cost
         * together, as the basket would charge it (review Connect-1, H2). A
         * channel that lists the price of what can actually be bought needs
         * the line, not the unit price at quantity one.
         */
        public readonly string $minimumPurchase = '1',
        public readonly PurchaseLine|null $minimumLine = null,
    ) {
    }

    /**
     * Whether the article cannot be bought as a single piece.
     */
    public function hasMinimumPurchase(): bool
    {
        return BigDecimal::of($this->minimumPurchase)->isGreaterThan(BigDecimal::one());
    }

    public function isVariant(): bool
    {
        return null !== $this->groupId;
    }
}

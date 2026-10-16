<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog\Read;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Contao\CoreBundle\String\HtmlDecoder;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\Gtin;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailAssembler;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailRow;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSafetyResolver;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSort;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\OneTimeOffer;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;

/**
 * The catalogue as data, without a page or a request (Connect-1, CC2) —
 * what a feed, a JSON endpoint or a console command reads.
 *
 * It decides nothing of its own. Visibility is the projection's
 * (ProductQuery): the reader lists exactly the products a listing of the
 * same start point could show — no filter, no pins, oldest first. The
 * articles of a product are the variants its page offers (VariantRepository),
 * put together by the assembler the page uses, so inheritance — images, unit
 * set, manufacturer — follows the same rules.
 */
final class CatalogReader
{
    public function __construct(
        private readonly ProductQuery $products,
        private readonly ProductDetailRepository $details,
        private readonly VariantRepository $variants,
        private readonly ProductSafetyResolver $safety,
        private readonly Availability $availability,
        private readonly ArticleShippingDataInterface $shipping,
        private readonly CatalogAddressesInterface $addresses,
        private readonly HtmlDecoder $decoder,
        private readonly Connection $connection,
        private readonly OneTimeOffer $oneTime,
    ) {
    }

    /**
     * @return list<int> ids of the visible main products, by id
     */
    public function mainIds(CatalogContext $context, int $offset = 0, int $limit = 0): array
    {
        return $this->products->find(
            $context->shopId,
            [],
            $context->language,
            $context->currency,
            $context->fallback,
            ProductSort::ID,
            false,
            $offset,
            $limit,
        );
    }

    public function count(CatalogContext $context): int
    {
        return $this->products->count($context->shopId, [], $context->language, $context->currency);
    }

    /**
     * Whether a main product is in the catalogue of this start point — what a
     * caller holding an id from an address asks before articles().
     */
    public function isVisible(CatalogContext $context, int $mainId): bool
    {
        return [] !== $this->products->visibleAmong([$mainId], $context->shopId, [], $context->language, $context->currency);
    }

    /**
     * Every article of a main product that can be bought here: the product
     * itself without variants, otherwise each visible variant in display
     * order. Empty for a product with variants of which none is visible —
     * the main product of a variant product is never an article of its own.
     *
     * It does not ask whether the main product is visible: that is what
     * mainIds() is for, and a caller holding an id from elsewhere asks there.
     *
     * @return list<CatalogArticle>
     */
    public function articles(CatalogContext $context, int $mainId): array
    {
        $main = $this->details->row($mainId, $context->language, $context->fallback);

        if (null === $main || 0 !== $main->pid) {
            return [];
        }

        $visible = $this->variants->visibleVariants($mainId, $context->shopId, $context->now, $context->language, $context->fallback);
        $properties = $this->details->properties($mainId, $context->language, $context->fallback);

        if ([] === $visible) {
            return $this->hasVariants($mainId) ? [] : [$this->article($context, $main, null, [], $properties)];
        }

        $axes = $this->variants->axes($mainId, $context->language, $context->fallback);
        $articles = [];

        foreach ($visible as $variantRow) {
            $row = $this->details->row($variantRow->id, $context->language, $context->fallback);

            if (null === $row) {
                continue;
            }

            $options = [];

            // In the order of the axes, as the page lists them.
            foreach ($axes as $groupId => $axis) {
                $optionId = $variantRow->options[$groupId] ?? null;

                if (null !== $optionId && isset($axis['options'][$optionId])) {
                    $options[$axis['label']] = $axis['options'][$optionId];
                }
            }

            $articles[] = $this->article($context, $main, $row, $options, $properties);
        }

        return $articles;
    }

    /**
     * @param array<string, string> $options
     * @param array<mixed>          $properties
     */
    private function article(CatalogContext $context, ProductDetailRow $main, ProductDetailRow|null $variant, array $options, array $properties): CatalogArticle
    {
        $detail = ProductDetailAssembler::assemble($main, $variant, $properties);
        $articleId = $detail->articleId;

        $stock = $this->availability->of([$articleId])[$articleId] ?? null;
        $shipping = $this->shipping->of([$articleId])[$articleId] ?? null;
        $gtin = '' !== $detail->gtin && Gtin::isValid($detail->gtin) ? Gtin::normalise($detail->gtin) : '';
        $units = ['unit' => $detail->unit, 'purchaseUnit' => $detail->purchaseUnit, 'referenceUnit' => $detail->referenceUnit];

        // The product page's own rules (K2), at the delivery country's rate
        // where the checkout would charge it (K1).
        $price = $this->oneTime->of(
            $articleId,
            $main->id,
            $context->currency,
            $context->shopCountry,
            $context->taxCountry(),
            $context->now,
            $units,
            $context->groups,
        );

        $minimum = self::minimumOf($detail->minPurchase);
        $minimumLine = null !== $price && BigDecimal::of($minimum)->isGreaterThan(BigDecimal::one())
            ? $this->oneTime->lineAt($articleId, $main->id, $context->currency, $context->taxCountry(), $context->now, $minimum, $context->groups)
            : null;

        return new CatalogArticle(
            articleId: $articleId,
            mainId: $main->id,
            groupId: null === $variant ? null : $main->id,
            title: trim($this->decoder->inputEncodedToPlainText($detail->title)),
            options: $options,
            teaser: trim($this->decoder->inputEncodedToPlainText($detail->teaser)),
            description: trim($this->decoder->htmlToPlainText($detail->description)),
            url: $this->addresses->product($main->id, null === $variant ? 0 : $articleId, $context),
            images: $this->addresses->images($detail->images, $context),
            sku: $detail->sku,
            gtin: $gtin,
            mpn: $detail->mpn,
            brand: $this->safety->forProduct($articleId, $context->language, $context->fallback)->manufacturerName,
            productType: trim($this->decoder->inputEncodedToPlainText($detail->productTypeTitle)),
            physical: ProductKind::stored($shipping['kind'] ?? null)->isPhysical(),
            weight: $shipping['weight'] ?? '0.0000',
            // Unlimited: always there, and no state to report.
            stock: null === $stock?->available ? null : $stock->state,
            availableFrom: null === $stock?->available ? null : $stock->availableFrom,
            price: $price,
            minimumAge: $detail->minimumAge,
            units: $units,
            minimumPurchase: $minimum,
            minimumLine: $minimumLine,
        );
    }

    /**
     * The stored minimum without trailing zeros; an empty, broken or
     * non-positive one is the column's default, one.
     *
     * Public for its test alone: MySQL hands decimal(10,4) over as "3.0000",
     * SQLite as "3", so a test through the database never sees what this
     * method is for (the "2.0000 × Wanderstock" trap).
     */
    public static function minimumOf(string $stored): string
    {
        try {
            $minimum = BigDecimal::of(trim($stored));
        } catch (MathException) {
            return '1';
        }

        return $minimum->isPositive() ? (string) $minimum->strippedOfTrailingZeros() : '1';
    }

    private function hasVariants(int $mainId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_product WHERE pid = ? LIMIT 1', [$mainId]);
    }
}

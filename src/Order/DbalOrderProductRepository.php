<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Cart\CartTitles;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityGuarantees;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;

/**
 * What an order line freezes about the product it sells.
 *
 * ── The name comes from CartTitles, not from a second query ──────────────
 *
 * A variant's own `title` column is a BACK END label — "red / L", written for
 * whoever maintains the catalogue. The storefront always shows the main
 * product's title (ProductDetailAssembler), and the basket does too. An
 * invoice that said "red / L" would name an article the shop never advertised
 * under that name, and it would do so on the one document the customer keeps.
 *
 * Reading it through CartTitles keeps the rule — main product, translated,
 * article's own SKU — in ONE place. Writing the same join a second time here
 * would work until the day one of the two changes.
 *
 * The tax class and the main product are this class's own question: the tax
 * class belongs to the article that is SOLD and is never inherited, and the
 * main product is what lets the pricing inherit (ArticlePriceResolver).
 */
final class DbalOrderProductRepository implements OrderProductRepositoryInterface
{
    /** tl_consho_order_item.productName */
    public const NAME_LENGTH = 255;

    public function __construct(
        private readonly Connection $connection,
        private readonly CartTitles $titles,
        private readonly ArticleShippingDataInterface $articles,
        // Block GARAN (GA6): the same source the checkout showed. Optional for
        // the tests built by hand.
        private readonly DurabilityGuarantees|null $guarantees = null,
    ) {
    }

    public function snapshot(int $productId, string $language = '', string $fallback = ''): array|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT pid, taxClass, voucherValue FROM tl_consho_product WHERE id = ?',
            [$productId],
        );

        if (false === $row) {
            return null;
        }

        $title = $this->titles->of([$productId], $language, $fallback)[$productId] ?? null;
        $article = $this->articles->of([$productId])[$productId] ?? null;
        $guarantee = $this->guarantees?->of([$productId])[$productId] ?? null;

        return [
            // Block GARAN (GA6): what stood before the button.
            'durabilityYears' => $guarantee['years'] ?? '0',
            'durabilityLabel' => null === $guarantee ? null : StringUtil::uuidToBin($guarantee['label']),

            // Cut to the column (E2): title and values may each be
            // 255 long, and a name too long for productName would fail the
            // purchase click on a strict database.
            'name' => null !== $title ? mb_substr($title->title, 0, self::NAME_LENGTH) : '',
            'sku' => null !== $title ? $title->sku : '',
            'taxClass' => (int) $row['taxClass'],

            // 0 means "is its own main product", the same shape as the pid
            // column — COALESCE(NULLIF(pid, 0), id) is the rule everywhere
            // else, and the caller applies it rather than storing it twice.
            'mainProduct' => 0 !== (int) $row['pid'] ? (int) $row['pid'] : $productId,

            // Through the checkout's source, so the basket and the order it
            // becomes cannot disagree about what has to be shipped (F-1).
            'kind' => ProductKind::stored($article['kind'] ?? null)->value,

            // EZ-1: sold as a single-purpose voucher — the same source as the basket.
            'singlePurpose' => $article['singlePurpose'] ?? false,

            // What a voucher of this article is worth (WG-4) — frozen at the line.
            'voucherValue' => is_numeric($row['voucherValue'] ?? null) ? (string) $row['voucherValue'] : '0',
        ];
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

interface OrderProductRepositoryInterface
{
    /**
     * What has to be frozen onto the order line.
     *
     * The LANGUAGE is an argument because an order is placed in one (C-7) and
     * the invoice has to read the way the customer bought: a German order does
     * not get English product names because the shop's fallback is English.
     *
     * @param string $language the language of the order
     * @param string $fallback the shop's main language
     *
     * `kind` (a ProductKind value) is frozen because delivery, withdrawal and
     * the tax country depend on it (F-1, F13, DL1): a product type switched
     * later must not turn a parcel already sold into a download.
     *
     * `durabilityYears`/`durabilityLabel` (block GARAN, GA6): the
     * manufacturer's durability guarantee and its label (binary UUID), as the
     * customer saw them before the button. Absent or 0/null: none.
     *
     * @return array{name: string, sku: string, taxClass: int, mainProduct: int, kind: string, voucherValue?: string, durabilityYears?: string, durabilityLabel?: string|null}|null
     *                                                                               null when the product is gone
     */
    public function snapshot(int $productId, string $language = '', string $fallback = ''): array|null;
}

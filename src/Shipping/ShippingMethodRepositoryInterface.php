<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

/**
 * Where the checkout gets a shop's shipping methods from.
 *
 * A seam for the same reason PriceRepositoryInterface is one: the calculator
 * (7.2) is pure arithmetic over methods and lines, and a test of "an
 * unavailable method still appears, greyed out" should not have to build a
 * database to say so. The one implementation reads DBAL.
 */
interface ShippingMethodRepositoryInterface
{
    /**
     * Published, in the order the shop selected them — the first available one
     * is the default (C2).
     *
     * @return list<ShippingMethod>
     */
    public function forShop(int $shopId, string $currency, string $language = '', string $fallback = ''): array;
}

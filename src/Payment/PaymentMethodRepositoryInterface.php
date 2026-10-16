<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * Where the checkout gets a shop's payment methods from — the counterpart of
 * ShippingMethodRepositoryInterface, and a seam for the same reason.
 */
interface PaymentMethodRepositoryInterface
{
    /**
     * Published, in the order the shop selected them (C2).
     *
     * @return list<PaymentMethod>
     */
    public function forShop(int $shopId, string $language = '', string $fallback = ''): array;
}

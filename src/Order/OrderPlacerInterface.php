<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;

interface OrderPlacerInterface
{
    /**
     * @param list<OrderLineDraft> $lines
     * @param int                  $rootId the start point, which is where the legal texts hang
     *
     * @throws ProductGoneFromCatalogue
     * @throws StockShortAtPurchase
     * @throws TotalDisagreesWithCheckout
     * @throws BasketGoneAtPurchase
     */
    public function place(
        int $cartId,
        int $rootId,
        OrderDraft $draft,
        array $lines,
        PaymentMethod $method,
        int $now,
    ): PlacedOrder;
}

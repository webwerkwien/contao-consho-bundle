<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutState;

/**
 * What the checkout element asks for when the button is pressed (C-7): one
 * call, everything else behind it.
 */
interface PurchaseHandlerInterface
{
    /**
     * @param int       $rootId         the start point — the legal texts hang off it
     * @param string    $language       the language of the page the order was placed on
     * @param list<int> $customerGroups
     *
     * @throws PaymentMethodGoneAtPurchase
     * @throws ProductGoneFromCatalogue
     * @throws StockShortAtPurchase
     * @throws BasketGoneAtPurchase
     */
    public function place(
        int $cartId,
        int $rootId,
        int $shopId,
        string $shopCountry,
        string $language,
        string $fallbackLanguage,
        int $memberId,
        array $customerGroups,
        CheckoutState $state,
        int $now,
        string $shopVatId = '',
        // A net price group or a verified VAT ID (F-3): no consent asked, no period to wait for.
        bool $businessCustomer = false,
    ): PlacedOrder;
}

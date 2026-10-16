<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;

/**
 * One line of a calculated basket. Everything a template shows, already
 * decided — a template that has to choose between two amounts shows the wrong
 * one somewhere.
 */
final class CartLine
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $articleId,
        public readonly int $mainProductId,
        public readonly string $quantity,
        public readonly string $unitNet,
        public readonly string $lineNet,
        public readonly string $lineGross,
        /**
         * Carried, not recomputed: splitting shipping tax by goods value (C4)
         * needs to know which rate each line belongs to, and asking the
         * catalogue again at that point would be a second copy of the rule
         * that priced the line — free to drift from the first.
         */
        public readonly int $taxClassId = 0,
        // The plan the line was priced in (Abo-2): 0 = one-time sale.
        public readonly int $subscriptionRule = 0,
        // The subscription the line renews (Abo-7b): 0 = none.
        public readonly int $renews = 0,
        /*
         * The country the line was taxed in (DL4): the shop's for a service,
         * the customer's otherwise. Carried for the same reason as the tax
         * class — whatever else grosses this line (the subscription terms at
         * checkout) must use the country the pricer used, not decide again.
         * '' where nothing priced it (PaymentChange).
         */
        public readonly string $taxCountry = '',
        /*
         * Where the price came from (block H, H-4): a cart rule leaves
         * reduced goods out unless it says otherwise, and "reduced" is a
         * promotion — a price row or a catalogue rule (C1).
         */
        public readonly PriceSource $source = PriceSource::List,
        /*
         * The kind of the article (block Wertgutscheine, WG8): a voucher is
         * no goods a reduction, a payment discount or the tax of shipping
         * could follow — CartDiscounts and ShippingTaxSplitter leave it out.
         * Physical where nothing priced it, as everywhere (ProductKind).
         */
        public readonly ProductKind $kind = ProductKind::Physical,
    ) {
    }
}

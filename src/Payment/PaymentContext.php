<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities;

/**
 * What a payment handler may base availability and adjustment on. Built by
 * the checkout (C-5); a handler never reads the session (research, conclusion 2).
 *
 * ⚠️ `customerGroups` is a LIST — same reason as in ShippingContext, which
 * carries the long form of the note.
 */
final class PaymentContext
{
    /**
     * @param list<int>                 $customerGroups every group the visitor is in, empty for a guest
     * @param ShippingCapabilities|null $shipping       what the CHOSEN shipping method does at hand-over
     *                                                  (E-3: cash on delivery asks); null while none is
     *                                                  chosen, and after the purchase (PaymentChange)
     * @param bool                      $handedOutAtOnce whether a line is digital content or a voucher —
     *                                                  goods usable the moment they are released, which
     *                                                  "Auf Rechnung" may refuse (Nachlese E5b)
     */
    public function __construct(
        public readonly int $shopId,
        public readonly string $currency,
        public readonly string $billingCountry,
        public readonly string $deliveryCountry,
        public readonly array $customerGroups,
        public readonly string $netTotal,
        public readonly ShippingCapabilities|null $shipping = null,
        public readonly bool $handedOutAtOnce = false,
    ) {
    }
}

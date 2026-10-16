<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

/**
 * What one piece of an article costs to send to one country, by one method
 * (Connect-1, K3). Unformatted; `gross` at the rate the checkout would charge.
 */
final class ShippingRate
{
    public function __construct(
        public readonly int $methodId,
        public readonly string $title,
        public readonly string $country,
        public readonly string $net,
        public readonly string $gross,
    ) {
    }
}

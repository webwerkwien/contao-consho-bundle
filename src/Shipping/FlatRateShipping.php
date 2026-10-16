<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

/**
 * Versandpauschale (spec 6.1): one amount per currency, whatever the cart.
 *
 * Available wherever the shop delivers — the checkout offers only the shop's
 * delivery countries in the first place — as long as a saved amount exists in
 * the cart's currency. Without one it is unavailable rather than free; the start
 * page says so (DbalShippingPriceIssueSource).
 */
final class FlatRateShipping implements ShippingMethodTypeInterface
{
    public static function getType(): string
    {
        return 'flat_rate';
    }

    public function isAvailable(ShippingContext $context, array $config): bool
    {
        return \is_string($config['net'] ?? null);
    }

    public function calculate(ShippingContext $context, array $config): ShippingQuote
    {
        if (!\is_string($config['net'] ?? null)) {
            throw new \LogicException(\sprintf('The flat rate has no amount in %s; ask isAvailable() first.', $context->currency));
        }

        return new ShippingQuote($config['net']);
    }

    /**
     * Checks no age. Collects cash on delivery when the operator ticked it
     * (E-3, Z2): whether the parcel service collects is in the operator's
     * contract with it, not in this code.
     */
    public function capabilities(array $config): ShippingCapabilities
    {
        return new ShippingCapabilities(0, false, (bool) ($config['cashOnDelivery'] ?? false));
    }
}

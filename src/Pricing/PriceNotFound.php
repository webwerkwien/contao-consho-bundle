<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * No price is configured for this product, currency and customer group.
 *
 * Deliberately fatal, for the same reason TaxRateNotFound is: a silent
 * fallback to zero would put a free product in a cart, and nobody would notice
 * until the order arrived.
 */
final class PriceNotFound extends \RuntimeException
{
    /**
     * @param list<int> $customerGroupIds
     */
    public static function for(int $productId, string $currency, array $customerGroupIds = []): self
    {
        return new self(sprintf(
            'No price configured for product %d in %s%s.',
            $productId,
            $currency,
            [] !== $customerGroupIds ? sprintf(' (customer groups %s)', implode(', ', $customerGroupIds)) : '',
        ));
    }
}

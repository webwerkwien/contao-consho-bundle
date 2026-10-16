<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * What a price row stores for a typed amount: the net, the mode it was typed
 * in, and the home country a gross amount referred to (none for a net amount
 * without an unambiguous one).
 */
final class PriceInputResult
{
    public function __construct(
        public readonly string $net,
        public readonly PriceInputMode $mode,
        public readonly string|null $country,
    ) {
    }
}

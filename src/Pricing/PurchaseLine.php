<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * A one-time basket line priced ahead of the basket: this many pieces, and
 * what they cost together (OneTimeOffer::lineAt()).
 */
final class PurchaseLine
{
    /**
     * @param string $quantity decimal string, as the article's minimum purchase stores it
     * @param string $net      the line's net, four places
     * @param string $gross    the line's gross at the tax country's rate
     */
    public function __construct(
        public readonly string $quantity,
        public readonly string $net,
        public readonly string $gross,
    ) {
    }
}

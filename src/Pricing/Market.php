<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * A currency together with the home country its gross is calculated for.
 *
 * The list price projection is written once per market (C25): two euro shops
 * in Austria and Germany show two different gross amounts for the same net.
 */
final class Market
{
    public function __construct(
        public readonly string $currency,
        public readonly string $country,
    ) {
    }

    public function key(): string
    {
        return $this->currency.'|'.$this->country;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * What "Lizenz verlängern" needs to know about the page it is offered on
 * (Abo-7b, P67): the basket's currency and country, who the visitor is priced
 * as, and where the basket page is — the same answers the basket itself gets,
 * so the price next to the link is the price in the basket.
 */
final class RenewalContext
{
    /**
     * @param list<int> $groups the visitor's member groups, as the basket prices them
     */
    public function __construct(
        public readonly string $currency,
        public readonly string $country,
        public readonly array $groups,
        public readonly bool $showsNet,
        public readonly string $cartUrl,
    ) {
    }
}

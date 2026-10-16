<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * What the list price projection stores for one article in one market.
 *
 * Since Abo-2b the figure is the one the product page puts up top: the
 * one-time price, or — for an article not sold once — its first subscription
 * plan, per billing period (P88). `subscriptionRule` says which; 0 is the
 * one-time sale, as everywhere. `alsoSubscription` marks a one-time price
 * whose article can also be had as a subscription (P89).
 *
 * Block H (H-2): `promotion` says the figure is a promotion for everyone, and
 * `compareNet`/`compareGross` carry the price struck through next to it —
 * set by ListPriceProjector from the history, null where there is none.
 */
final class ListPrice
{
    public function __construct(
        public readonly string $net,
        public readonly string $gross,
        public readonly int $subscriptionRule = 0,
        public readonly bool $alsoSubscription = false,
        public readonly bool $promotion = false,
        public readonly string|null $compareNet = null,
        public readonly string|null $compareGross = null,
    ) {
    }

    public function withCompare(string $net, string $gross): self
    {
        return new self($this->net, $this->gross, $this->subscriptionRule, $this->alsoSubscription, $this->promotion, $net, $gross);
    }
}

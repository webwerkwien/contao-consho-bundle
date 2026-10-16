<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * What OneTimeOffer answers: the article's one-time price and, for a
 * promotion, the price struck through beside it — both or neither of the
 * compare amounts are set.
 */
final class OneTimePrice
{
    public function __construct(
        public readonly ArticlePrice $price,
        public readonly string|null $compareNet = null,
        public readonly string|null $compareGross = null,
    ) {
    }

    /**
     * The struck price as this visitor reads it, beside display().
     */
    public function compare(bool $showsNet): string|null
    {
        return $showsNet ? $this->compareNet : $this->compareGross;
    }
}

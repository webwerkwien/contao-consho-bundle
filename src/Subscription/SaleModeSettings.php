<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * What an article is sold as (Abo, A4): once, by subscription rules, or both.
 */
final class SaleModeSettings
{
    /**
     * @param list<int> $rules existing subscription rules, in the order chosen at the product
     */
    public function __construct(
        public readonly bool $once,
        public readonly array $rules,
    ) {
    }

    public function subscription(): bool
    {
        return [] !== $this->rules;
    }

    /**
     * The values of tl_consho_price.subscriptionRule this article may carry:
     * 0 for the one-time sale, then the rules.
     *
     * @return list<int>
     */
    public function priceRules(): array
    {
        return [...($this->once ? [0] : []), ...$this->rules];
    }
}

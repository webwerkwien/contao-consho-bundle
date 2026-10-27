<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The conditions of ONE line (Abo-2, P15): the rule and the amount the line
 * already shows.
 *
 * Product page, basket, review and order all come through here, so the
 * sentence is built one way. Until variant A it also looked up
 * the current monthly amount for an open-ended rule billed otherwise than
 * monthly (A8); there is no such rule any more — "läuft weiter, jederzeit
 * kündbar" is monthly only.
 */
class SubscriptionLineTerms
{
    public function __construct(
        private readonly SubscriptionOffers $offers,
        private readonly SubscriptionTerms $terms,
        private readonly MoneyFormatter $money,
    ) {
    }

    /**
     * @param string $lineAmount the amount the line shows per billing period, unformatted
     *
     * @return string|null null for a one-time sale or a rule that no longer exists
     */
    public function of(int $ruleId, string $lineAmount, string $currency, string $language): string|null
    {
        if ($ruleId <= 0) {
            return null;
        }

        $rule = $this->offers->rulesByIds([$ruleId])[$ruleId] ?? null;

        if (null === $rule) {
            return null;
        }

        return $this->terms->of($rule, $this->money->format($lineAmount, $currency, $language), $language);
    }
}

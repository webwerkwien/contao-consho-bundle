<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * What an article is offered as, and to whom (Abo-2, P10/P11) — the ONE place
 * that answers it. Product page, add-to-basket, basket, checkout and order all
 * ask here; a second copy of "sale modes + rule groups" is the copy that
 * forgets one of them.
 *
 * Until variant A (2026-10-08) a third rule stood here: "sameTerm" only for a
 * business. Michael: „nicht automatisch, der Betreiber muss diese Jahresabos
 * auf Geschäftskunden limitieren" — the rule's customer groups do that now,
 * and a note (SameTermOpenIssueSource) names a rule left open.
 *
 * 0 stands for the one-time sale, as in tl_consho_price.subscriptionRule.
 */
class SubscriptionOffers
{
    public function __construct(
        private readonly SaleModes $saleModes,
        private readonly SubscriptionRules $rules,
    ) {
    }

    /**
     * @param list<int> $groups the visitor's member groups
     *
     * @return array<int, SubscriptionRule|null> rule id => rule, 0 => null for the one-time sale; one-time first, then the operator's order
     */
    public function offered(int $articleId, array $groups): array
    {
        $settings = $this->saleModes->of($articleId);
        $offers = $settings->once ? [0 => null] : [];

        foreach ($this->rules->byIds($settings->rules) as $id => $rule) {
            if ($rule->isFor($groups)) {
                $offers[$id] = $rule;
            }
        }

        return $offers;
    }

    /**
     * @param list<int> $groups
     */
    public function allows(int $articleId, int $ruleId, array $groups): bool
    {
        return $ruleId >= 0 && \array_key_exists($ruleId, $this->offered($articleId, $groups));
    }

    /**
     * The rules themselves, for a line that already carries one.
     *
     * @param list<int> $ids
     *
     * @return array<int, SubscriptionRule>
     */
    public function rulesByIds(array $ids): array
    {
        return $this->rules->byIds(array_values(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

interface PriceRepositoryInterface
{
    /**
     * Every price row valid for this product, currency and moment — for the
     * given customer groups AND for everyone. Choosing between them is the
     * calculator's job, not the query's: a query that already chooses hides
     * the rule in SQL, where nobody can test it without a database.
     *
     * A LIST of groups, not one, since C-4: `tl_member.groups` is a multiple
     * selection, so "the visitor's group" is a set. An empty list is a guest,
     * or a member in no group with prices — exactly what `null` used to mean.
     *
     * The subscription rule since Abo-2: 0 is the one-time sale, any other id
     * the rows of exactly that contract — never the two mixed. A monthly
     * amount among one-time rows would win as the cheapest (Block Abo, A2).
     *
     * @param list<int> $customerGroupIds
     *
     * @return list<PriceRow>
     */
    public function findCandidates(int $productId, string $currency, array $customerGroupIds, int $timestamp, int $subscriptionRule = 0): array;

    /**
     * @return list<int> ids of all products that have at least one price
     */
    public function findProductIdsWithPrices(): array;

    /**
     * @return list<string> every currency any price row uses
     */
    public function findCurrencies(): array;
}

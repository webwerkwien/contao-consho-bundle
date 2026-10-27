<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;

/**
 * Everything a product page says about the price of one article.
 *
 * Every amount — the price, each scale step — comes out of PriceCalculator, so
 * the calculator stays the one place a price comes into being. This class only
 * decides which article's rows apply and which quantities to ask for, and turns
 * each net amount into the display gross of one country (GrossCalculator, C25).
 *
 * Inheritance: a variant without a single price row of its own takes the whole
 * price set of its main product, scale included ("empty = like the main
 * product", the same form as shops, manufacturer and safety texts). The rule
 * lives here rather than in a controller so that ordering can use it too.
 *
 * The TAX class is never inherited: it belongs to the article that is sold.
 *
 * No rate for the class in that country means no price — null, exactly as if
 * no price row existed. A gross calculated with a guessed rate would be a
 * price the order later contradicts.
 */
final class ArticlePriceResolver implements CartLinePriceSource
{
    public function __construct(
        private readonly PriceCalculator $calculator,
        private readonly PriceRepositoryInterface $repository,
        private readonly BasePriceCalculator $basePrices,
        private readonly GrossCalculator $grossCalculator,
        private readonly ArticleTaxClassesInterface $taxClasses,
        private readonly SubscriptionOffers|null $offers = null,
        // Block H (H-3): catalogue price rules on the price the rows decided.
        private readonly CatalogPriceRules|null $rules = null,
    ) {
    }

    /**
     * The calculator, then the catalogue price rules (H-3) — the one way
     * every amount of this class comes about, so page, scale table, basket,
     * order and projection cannot disagree about a rule.
     *
     * Rules only on the one-time sale: a subscription's price is frozen at
     * the purchase, and H8 keeps reductions off its lines.
     *
     * @param list<int> $customerGroups
     *
     * @throws PriceNotFound
     */
    private function priceOf(int $owner, int $articleId, int $mainProductId, string $currency, array $customerGroups, string $quantity, int $now, int $subscriptionRule): ResolvedPrice
    {
        $price = $this->calculator->calculate($owner, $currency, $customerGroups, $quantity, $now, $subscriptionRule);

        return null === $this->rules || 0 !== $subscriptionRule
            ? $price
            : $this->rules->apply($articleId, $mainProductId, $price, $customerGroups, $now);
    }

    /**
     * @param int                                                              $articleId      the product, or the variant
     * @param int                                                              $mainProductId  the same id for a product without variants
     * @param string                                                           $country        the country whose rate makes the gross — the shop's home country
     * @param array{unit: string, purchaseUnit: string, referenceUnit: string} $units          the set that applies to this article
     * @param list<int>                                                        $customerGroups   the visitor's groups — empty for a guest
     * @param int                                                              $subscriptionRule 0 for the one-time sale, else the rule whose contract is priced (Abo-2)
     *
     * @return ArticlePrice|null null when neither the article nor its main product has a price in this currency, or the country has no rate
     */
    public function forArticle(int $articleId, int $mainProductId, string $currency, string $country, int $now, array $units, array $customerGroups = [], int $subscriptionRule = 0): ArticlePrice|null
    {
        /*
         * Quantity one — the same question the listing asks (ListPriceProjector).
         * The groups are the one argument C-4 changed, exactly as the comment
         * that stood here since B-5 said it would.
         *
         * ⚠️ Unlike listPrice() below, this DOES take the groups: the detail
         * page prices the visitor in front of it, while the projection is one
         * row per article and market and can hold no audience at all ("kein
         * Preis je Kundengruppe in der Materialisierung", block A, 3.6).
         */
        [$owner, $candidates] = $this->ownerOf($articleId, $mainProductId, $currency, $customerGroups, $now, $subscriptionRule);

        try {
            $price = $this->priceOf($owner, $articleId, $mainProductId, $currency, $customerGroups, '1', $now, $subscriptionRule);
        } catch (PriceNotFound) {
            return null;
        }

        $taxClass = $this->taxClasses->taxClassOf($articleId);

        try {
            $gross = $this->grossCalculator->gross($price->net, $taxClass, $country, $now);
            $scale = $this->scaleOf($owner, $articleId, $mainProductId, $candidates, $currency, $customerGroups, $taxClass, $country, $now, $subscriptionRule);
        } catch (TaxRateNotFound) {
            return null;
        }

        /*
         * Both base prices, because the audience is not this class's business
         * (C-4): whether the page reads net is a shop setting the controller
         * knows, and a resolver that decided it here would have to be told
         * who is looking in order to answer what something costs.
         */
        [$basePrice, $referenceUnit, $unit] = $this->basePriceOf($gross, $units);
        [$basePriceNet] = $this->basePriceOf($price->net, $units);

        return new ArticlePrice($price, $gross, $owner, $scale, $basePrice, $referenceUnit, $unit, $basePriceNet);
    }

    /**
     * The price of one unit at a given quantity — what a basket line needs.
     *
     * Same inheritance as forArticle(): a variant without a price row of its own
     * takes its main product's set. Unlike forArticle() this asks for the REAL
     * quantity, so a scale price applies, and it returns no scale table, no units
     * and no gross — a basket shows a line, not a price list.
     *
     * @param list<int> $customerGroups   the visitor's groups — empty for a guest
     * @param int       $subscriptionRule 0 for the one-time sale, else the line's rule (Abo-2)
     */
    public function forCartLine(int $articleId, int $mainProductId, string $currency, string $quantity, int $now, array $customerGroups = [], int $subscriptionRule = 0): CartLinePrice|null
    {
        [$owner] = $this->ownerOf($articleId, $mainProductId, $currency, $customerGroups, $now, $subscriptionRule);

        try {
            $price = $this->priceOf($owner, $articleId, $mainProductId, $currency, $customerGroups, $quantity, $now, $subscriptionRule);
        } catch (PriceNotFound) {
            return null;
        }

        // The tax class is never inherited: it belongs to the article that is
        // sold, not to the one whose price applies.
        return new CartLinePrice(
            $price->net,
            $this->taxClasses->taxClassOf($articleId),
            $price->source,
            $price->customerGroup,
        );
    }

    /**
     * The list price of one article in one market — what the projection stores
     * for filtering and sorting.
     *
     * The same owner rule as forArticle(), deliberately in the same class: a
     * variant that inherits must cost the same in the listing as on its page,
     * and two copies of the rule would drift apart at the first change.
     *
     * Which offer (Abo-2b): the first of SubscriptionOffers::offered() with a
     * price — the one-time sale first, then the plans in the operator's order;
     * exactly what the product page puts up top (P88). Asked for a guest who
     * is no business, so only plans open to everyone (P91): the projection is
     * per market, not per visitor, and a tile must not show a plan the visitor
     * cannot book. An article not sold once ignores a one-time row left over
     * from before (Abo-2 — the page already does).
     *
     * ⚠️ So the tile and the page can differ for a visitor in a group on
     * purpose (Abo-2b): with a plan for that group first, the
     * page shows it to them, the tile the first plan open to everyone (P91).
     * Open to everyone means the rule's groups only — since variant A
     * (A3) no longer its renewal kind.
     */
    public function listPrice(int $articleId, int $mainProductId, string $currency, string $country, int $now): ListPrice|null
    {
        $offered = null === $this->offers ? [0 => null] : $this->offers->offered($articleId, []);
        $first = null;

        foreach (array_keys($offered) as $rule) {
            $price = $this->listPriceOf($articleId, $mainProductId, $currency, $country, $now, $rule);

            if (null === $price) {
                continue;
            }

            if (null === $first) {
                $first = $price;

                // A plan heads the tile: there is nothing to add.
                if (0 !== $rule) {
                    return $first;
                }

                continue;
            }

            // A one-time price heads it, and a plan open to everyone has a price too.
            return new ListPrice($first->net, $first->gross, 0, true, $first->promotion);
        }

        return $first;
    }

    private function listPriceOf(int $articleId, int $mainProductId, string $currency, string $country, int $now, int $rule): ListPrice|null
    {
        [$owner] = $this->ownerOf($articleId, $mainProductId, $currency, [], $now, $rule);

        try {
            $price = $this->priceOf($owner, $articleId, $mainProductId, $currency, [], '1', $now, $rule);

            return new ListPrice(
                $price->net,
                $this->grossCalculator->gross($price->net, $this->taxClasses->taxClassOf($articleId), $country, $now),
                $rule,
                promotion: PriceSource::Promotion === $price->source,
            );
        } catch (PriceNotFound|TaxRateNotFound) {
            return null;
        }
    }

    /**
     * Whose rows apply: the article's own — or, for a variant without a single
     * row valid at this moment in this currency, its main product's.
     *
     * Per contract since Abo-2: a variant with a one-time price of its own but
     * no subscription row takes the subscription rows of its main product —
     * "empty = like the main product" is asked of the rows of THIS rule.
     *
     * @param list<int> $customerGroups
     *
     * @return array{int, list<PriceRow>} the owner and its candidate rows
     */
    private function ownerOf(int $articleId, int $mainProductId, string $currency, array $customerGroups, int $now, int $subscriptionRule = 0): array
    {
        $candidates = $this->repository->findCandidates($articleId, $currency, $customerGroups, $now, $subscriptionRule);

        if ([] === $candidates && $articleId !== $mainProductId) {
            return [$mainProductId, $this->repository->findCandidates($mainProductId, $currency, $customerGroups, $now, $subscriptionRule)];
        }

        return [$articleId, $candidates];
    }

    /**
     * The "from" price over several articles: the cheapest, and whether any of
     * them costs something else. Compared by the DISPLAYED amount — "13" and
     * "13.0000" are the same price and no reason to say "from".
     *
     * ⚠️ Displayed, not always gross, since C-4 — and the two can disagree.
     * Variants may sit in different tax classes, so equal net amounts become
     * unequal gross ones and the other way round. A listing that says "from
     * 8.00" and a page that then shows 7.90 is the shape of bug nobody
     * reports, because each number is right on its own.
     *
     * @param list<ArticlePrice> $prices
     *
     * @return array{ArticlePrice|null, bool} the cheapest (the first on a tie) and whether the prices differ
     */
    public static function fromPrice(array $prices, bool $showsNet = false): array
    {
        $cheapest = null;
        $differs = false;

        foreach ($prices as $candidate) {
            if (null === $cheapest) {
                $cheapest = $candidate;
                continue;
            }

            $comparison = BigDecimal::of($candidate->display($showsNet)[0])->compareTo($cheapest->display($showsNet)[0]);

            if (0 !== $comparison) {
                $differs = true;
            }

            if ($comparison < 0) {
                $cheapest = $candidate;
            }
        }

        return [$cheapest, $differs];
    }

    /**
     * @param list<PriceRow> $candidates
     * @param list<int>      $customerGroups
     *
     * @return list<ScaleStep>
     *
     * @throws TaxRateNotFound
     */
    private function scaleOf(int $owner, int $articleId, int $mainProductId, array $candidates, string $currency, array $customerGroups, int $taxClass, string $country, int $now, int $subscriptionRule = 0): array
    {
        /*
         * Thresholds, not rows: two rows valid at the same threshold make one
         * step. "1" and "1.0000" are the same threshold.
         *
         * ⚠️ Every applicable row counts, group rows included — since C-4, and
         * this is a real change, not a tidy-up. The line that used to stand
         * here skipped rows with a customer group, and while every caller
         * passed no group that skip could never fire: findCandidates() never
         * returned one. Left in place it would now throw away exactly the
         * thresholds a trade customer buys at, and their scale table would
         * show the public steps priced at their rates — a table that is right
         * in neither column.
         *
         * The amounts still come from calculate(), once per threshold, so each
         * step is the best price for THIS visitor at that quantity.
         */
        $thresholds = [];

        foreach ($candidates as $row) {
            $key = (string) BigDecimal::of($row->minQuantity)->strippedOfTrailingZeros();
            $thresholds[$key] ??= $row->minQuantity;
        }

        // A table with one line is not a scale.
        if (\count($thresholds) < 2) {
            return [];
        }

        $thresholds = array_values($thresholds);
        usort($thresholds, static fn (string $a, string $b): int => BigDecimal::of($a)->compareTo($b));

        $steps = [];

        foreach ($thresholds as $threshold) {
            try {
                $resolved = $this->priceOf($owner, $articleId, $mainProductId, $currency, $customerGroups, $threshold, $now, $subscriptionRule);
            } catch (PriceNotFound) {
                // Cannot happen — the row defining the threshold applies at it.
                // Should the repository change under us, a missing line beats a broken page.
                continue;
            }

            $gross = $this->grossCalculator->gross($resolved->net, $taxClass, $country, $now);

            /*
             * A step that changes nothing is not a step — since C-4, and the
             * group prices are what made it necessary. Specificity beats the
             * smaller number (PriceCalculator), so a group price from 5 also
             * wins at 10, and the public step from 10 would then appear at the
             * same amount right below it: "from 5: 8.00 / from 10: 8.00".
             *
             * Compared on the displayed gross, as fromPrice() does — "8" and
             * "8.0000" are one price and no reason for a second line.
             */
            $previous = $steps[\count($steps) - 1] ?? null;

            if (null !== $previous && 0 === BigDecimal::of($gross)->compareTo($previous->gross)) {
                continue;
            }

            $steps[] = new ScaleStep($threshold, $resolved, $gross);
        }

        // Folding may have left a single step, and one line is still not a
        // scale — the same rule as above, applied to what is actually shown.
        return \count($steps) < 2 ? [] : $steps;
    }

    /**
     * The unit fields count as one set; the caller has already chosen the
     * variant's or the main product's.
     *
     * @param array{unit: string, purchaseUnit: string, referenceUnit: string} $units
     *
     * @return array{string, string, string} base price, reference quantity, unit — all empty when there is none
     */
    private function basePriceOf(string $gross, array $units): array
    {
        // Piece goods carry no content; "stk" says so explicitly.
        if ('' === $units['unit'] || 'stk' === $units['unit']) {
            return ['', '', ''];
        }

        $basePrice = $this->basePrices->calculate(
            $gross,
            '' !== $units['purchaseUnit'] ? $units['purchaseUnit'] : '0',
            '' !== $units['referenceUnit'] ? $units['referenceUnit'] : '0',
        );

        if (null === $basePrice) {
            return ['', '', ''];
        }

        return [$basePrice, $units['referenceUnit'], $units['unit']];
    }
}

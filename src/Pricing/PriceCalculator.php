<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;

/**
 * The one place a price comes into being. Never in a template.
 *
 * Resolution order, first match wins:
 *
 *     customer group + scale -> customer group -> scale -> list price
 *
 * **Specificity decides, not the smaller number.** A bulk discount must not
 * silently override a group price, or the notice shown to the customer would
 * name the wrong reason. See docs/block-a-fundament.md, section 5.1.
 *
 * Every comparison runs on decimals, never floats: goods sold by length come
 * in 0.1 steps, and a scale price from 5 m must not be reached at 4.9 m.
 *
 * Promotions (block H, docs/block-h-preise.md) live in the same rows: a row
 * with a validity window beats one of equal rank without (H2) and is a
 * promotion when it is the cheaper of the two (B). A promotion never makes
 * anyone pay more — it holds across higher thresholds of its relationship (A)
 * and competes with group prices on the amount (H3).
 *
 * Personalised prices (AI plugin) and reverse charge (A-5) plug in ahead of
 * this order later; their PriceSource values already exist.
 */
final class PriceCalculator
{
    public function __construct(private readonly PriceRepositoryInterface $repository)
    {
    }

    /**
     * @param list<int> $customerGroupIds the visitor's groups — empty for a guest
     * @param string    $quantity         decimal string — goods sold by length use fractions
     *
     * @throws PriceNotFound
     */
    public function calculate(
        int $productId,
        string $currency,
        array $customerGroupIds,
        string $quantity,
        int $timestamp,
        int $subscriptionRule = 0,
    ): ResolvedPrice {
        // The rule rides along (Abo-2): a scale step of a subscription asked
        // without it would come back priced as the one-time sale.
        $candidates = $this->repository->findCandidates($productId, $currency, $customerGroupIds, $timestamp, $subscriptionRule);

        $wanted = BigDecimal::of($quantity);

        // A threshold above the requested quantity does not apply — to any of
        // the offers below, so it is dropped once rather than per offer.
        $applicable = array_values(array_filter(
            $candidates,
            static fn (PriceRow $row): bool => !BigDecimal::of($row->minQuantity)->isGreaterThan($wanted),
        ));

        $best = null;

        foreach ($this->offers($applicable) as $offer) {
            /*
             * The cheapest of the offers, and the FIRST on a tie — which keeps
             * a tie between two rows of one group undecided, exactly as it was
             * before C-4. DbalPriceTieIssueSource reports that shape to the
             * operator; resolving it here would make the shop quietly pick one
             * and the finding would never be acted on.
             */
            if (null === $best || BigDecimal::of($offer->row->net)->isLessThan($best->row->net)) {
                $best = $offer;
            }
        }

        if (null === $best) {
            throw PriceNotFound::for($productId, $currency, $customerGroupIds);
        }

        return new ResolvedPrice(
            $best->row->net,
            $best->row->currency,
            $best->promotion ? PriceSource::Promotion : $this->sourceOf($best->row),
            $best->row->minQuantity,

            /*
             * WHICH group won, not just that one did (C-7). The source already
             * says "customerGroup"; an order has to be able to name it, because
             * a member holds a set and the cheapest offer across them wins —
             * so two lines of one order can come from two different groups.
             */
            $best->row->customerGroup,
        );
    }

    /**
     * One offer per customer group the visitor belongs to — plus the offer
     * that applies to everyone, when no group price exists.
     *
     * ⚠️ Why the resolution runs per group and only THEN compares amounts
     * (C-4, after the review of 2026-09-21): the order at the top of this class
     * describes ONE customer relationship. A member of two groups stands in
     * two of them and takes the better, which is what "der günstigste Preis
     * über alle Gruppen" (decision E2) means.
     *
     * Mixing both into one comparison looked shorter and was wrong twice over.
     * It made joining a second group EXPENSIVE — group A flat 8.00 from 1,
     * group B 9.00 from 10: a member of both paid 8.00 at nine pieces and 9.00
     * at ten, because B's higher threshold beat A's lower amount. And the
     * mixed rule is not transitive, so the answer depended on the order the
     * database returned the rows in.
     *
     * Block H (H3): a promotion for everyone is an offer to a member too, and
     * competes with the group prices on the amount — a member never pays more
     * than a guest because of a sale. A standing general price still does not
     * enter: within a relationship specificity decides, as before.
     *
     * @param list<PriceRow> $applicable rows whose threshold the quantity reaches
     *
     * @return list<PriceOffer> the winning row of each group, in group order, then a general promotion
     */
    private function offers(array $applicable): array
    {
        /** @var array<int, list<PriceRow>> $byGroup */
        $byGroup = [];

        foreach ($applicable as $row) {
            if (null !== $row->customerGroup) {
                $byGroup[$row->customerGroup][] = $row;
            }
        }

        $general = $this->bestOf(array_values(array_filter($applicable, static fn (PriceRow $row): bool => null === $row->customerGroup)));

        // No group price applies: the general rows are the only offer there is
        // — for a guest, and for a member whose groups have nothing here.
        if ([] === $byGroup) {
            return null === $general ? [] : [$general];
        }

        $offers = [];

        foreach ($byGroup as $rows) {
            /*
             * The group's own rows ALONE, and the general ones are not missing
             * here (noticed in the second review round): within one
             * relationship a group price beats a list price whatever the
             * amounts — "specificity decides" — so a general row could never
             * win this comparison anyway. Passing them in looked careful and
             * said something the code does not do.
             *
             * A group with no applicable row is not in $byGroup at all, so
             * such a member falls through to the general offer below.
             */
            if (null !== ($best = $this->bestOf($rows))) {
                $offers[] = $best;
            }
        }

        if (null !== $general && $general->promotion) {
            $offers[] = $general;
        }

        return $offers;
    }

    /**
     * The price of ONE customer relationship — the rows of one group, or the
     * rows for everyone.
     *
     * Per threshold the row of equal rank that wins (H2); of those, the highest
     * threshold — specificity, as before block H. Then A: a promotion at a
     * lower threshold that is cheaper still wins, or buying ten would cost
     * more per piece than buying one during the sale.
     *
     * @param list<PriceRow> $rows
     */
    private function bestOf(array $rows): PriceOffer|null
    {
        /** @var array<string, list<PriceRow>> $byThreshold */
        $byThreshold = [];

        foreach ($rows as $row) {
            $byThreshold[(string) BigDecimal::of($row->minQuantity)->strippedOfTrailingZeros()][] = $row;
        }

        $best = null;
        $promotion = null;

        foreach ($byThreshold as $tied) {
            $offer = $this->winnerOf($tied);

            if (null === $best || BigDecimal::of($offer->row->minQuantity)->isGreaterThan($best->row->minQuantity)) {
                $best = $offer;
            }

            if ($offer->promotion && (null === $promotion || BigDecimal::of($offer->row->net)->isLessThan($promotion->row->net))) {
                $promotion = $offer;
            }
        }

        if (null !== $best && null !== $promotion && BigDecimal::of($promotion->row->net)->isLessThan($best->row->net)) {
            return $promotion;
        }

        return $best;
    }

    /**
     * Rows of one relationship at one threshold.
     *
     * H2: a row with a validity window beats one without — a promotion or a
     * planned change of the price; of two with a window the later start wins.
     * Anything else is a tie that stays one: the first row, and
     * DbalPriceTieIssueSource reports it, because a finding silently repaired
     * is a finding lost.
     *
     * B: the winner is a promotion only when it has a window AND is cheaper
     * than the standing row it beats. More expensive, it is the new price;
     * alone, it is just a price with an end — without a comparison there is
     * no reduction to show.
     *
     * @param non-empty-list<PriceRow> $rows
     */
    private function winnerOf(array $rows): PriceOffer
    {
        $winner = $rows[0];
        $standing = null;

        foreach ($rows as $row) {
            if (!$row->isWindowed()) {
                $standing ??= $row;
            }

            if ($row !== $winner && $this->beats($row, $winner)) {
                $winner = $row;
            }
        }

        $promotion = $winner->isWindowed()
            && null !== $standing
            && BigDecimal::of($winner->net)->isLessThan($standing->net);

        return new PriceOffer($winner, $promotion);
    }

    private function beats(PriceRow $candidate, PriceRow $current): bool
    {
        if ($candidate->isWindowed() !== $current->isWindowed()) {
            return $candidate->isWindowed();
        }

        // Both standing, or both with a window and the same start: a tie.
        return ($candidate->validFrom ?? \PHP_INT_MIN) > ($current->validFrom ?? \PHP_INT_MIN);
    }

    private function sourceOf(PriceRow $row): PriceSource
    {
        if (null !== $row->customerGroup) {
            return PriceSource::CustomerGroup;
        }

        return BigDecimal::of($row->minQuantity)->isGreaterThan(BigDecimal::one())
            ? PriceSource::Scale
            : PriceSource::List;
    }
}

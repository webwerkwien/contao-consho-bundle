<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;

/**
 * Turns stored lines into a basket with amounts (7.2, first step).
 *
 * Net is the anchor (C22): the line net is unit net × quantity, and the line
 * gross comes out of THAT, never out of a rounded unit gross multiplied by the
 * quantity. At 8.3333 net and three pieces that is 29.9999 against 30.00.
 *
 * The totals add up the lines — net total from the line nets, gross total from
 * the line grosses. Grossing the net total in one go is not an option and not
 * merely a second-best one: two lines can sit in different tax classes, and
 * there is no single rate to apply to their sum. It is also what the order
 * does (OrderFactory adds line net and line tax per line and sums both), and
 * the basket must not contradict the order it becomes.
 *
 * What goes into those sums is the line rounded to the currency's own place —
 * the amount the visitor actually reads — while the line itself keeps four
 * places and its gross still comes out of its own unrounded net. Summing the
 * raw lines and rounding once at the end produces a total that the visible
 * lines do not add up to, which from C-7 becomes an invoice whose positions
 * do not add up to its total.
 *
 * ── The tax country is per line (Dienstleistung, DL4) ───────────────────
 *
 * A service to a consumer is taxed where the shop is (§ 3a Abs. 1 UStG,
 * § 3a Abs. 7 UStG 1994 for Austria): the gym in Vienna charges Austrian VAT
 * whoever signs up. Everything else here is taxed in the customer's country —
 * the goods by their delivery, electronically supplied services by § 3a
 * Abs. 5 UStG (§ 3a Abs. 13 lit. a UStG 1994). So one basket can hold lines
 * of two countries, and the country is chosen per line from the kind of the
 * product type (Block DL, section 3). A business customer
 * with a verified VAT ID gets reverse charge on the whole basket, as before.
 *
 * This is deliberately NOT the checkout state (C-5). It knows nothing about
 * delivery countries, shipping, reverse charge or readiness — it prices what
 * is in the basket, and C-5 builds the rest on top of it.
 */
final class CartPricer
{
    public function __construct(
        private readonly ArticleTreeInterface $articles,
        private readonly CartLinePriceSource $prices,
        private readonly GrossCalculator $grossCalculator,
        /*
         * Whether a line's plan is still offered to THIS visitor (Abo-2, P11):
         * a rule for certain groups is priced like a group price, so a member
         * who left the group gets "not sellable", not the old amount. Optional
         * for the tests that build a pricer by hand; the container always
         * wires it.
         */
        private readonly SubscriptionOffers|null $offers = null,
        /*
         * The kind of each article, for the tax country (DL4). Optional for
         * the tests built by hand: without it every line is taxed as goods.
         * The container always wires it.
         */
        private readonly ArticleShippingDataInterface|null $kinds = null,
    ) {
    }

    /**
     * @param list<CartItemRow> $items
     * @param string            $customerCountry where a good or a digital service is taxed
     * @param string            $shopCountry     where a service is taxed (DL4)
     * @param list<int>         $customerGroups  the visitor's groups — empty for a guest
     * @param bool              $taxAtDestination the shop's switch "Steuer im Bestimmungsland" (block R, O1)
     */
    public function price(array $items, string $currency, string $customerCountry, string $shopCountry, int $now, array $customerGroups = [], bool $taxAtDestination = false): CartView
    {
        /*
         * article id => main product id. ArticleTreeInterface already holds
         * that rule ("a variant's parent is always a main product"); a second
         * SELECT on tl_consho_product here would be a copy of it that drifts.
         */
        $mainProducts = $this->articles->articlesOf(array_map(static fn (CartItemRow $item) => $item->articleId, $items));
        $kinds = null === $this->kinds || [] === $items ? [] : $this->kinds->of(array_map(static fn (CartItemRow $item) => $item->articleId, $items));

        // The same source MoneyFormatter reads, so that the amount a line
        // shows and the amount it contributes cannot drift apart.
        $digits = Currencies::getFractionDigits($currency);

        // article id => what it is offered as to this visitor, asked once per article.
        $offered = [];

        $lines = [];
        $unpriced = [];
        $unpricedItems = [];
        $quantity = BigDecimal::zero();
        $net = BigDecimal::zero();
        $gross = BigDecimal::zero();

        foreach ($items as $item) {
            // An article that is gone from the catalogue keeps its own id here
            // and loses its price a line below — the same outcome either way.
            $mainProductId = $mainProducts[$item->articleId] ?? $item->articleId;

            /*
             * A plan the product no longer offers to this visitor is the same
             * answer as a missing price: not sellable, shown and removable.
             * Asked of SubscriptionOffers, the one place (Abo-2, P11) — a rule
             * taken off the product, "one-time" unticked, a type switched to
             * physical, a group left: the price row may still be there, the
             * offer is not (Abo-2).
             */
            if (null !== $this->offers) {
                $offered[$item->articleId] ??= $this->offers->offered($item->articleId, $customerGroups);
            }

            if (null !== $this->offers && !\array_key_exists($item->subscriptionRule, $offered[$item->articleId])) {
                $unpriced[] = $item->articleId;
                $unpricedItems[] = $item->id;

                continue;
            }

            $price = $this->prices->forCartLine($item->articleId, $mainProductId, $currency, $item->quantity, $now, $customerGroups, $item->subscriptionRule);

            if (null === $price) {
                $unpriced[] = $item->articleId;
                $unpricedItems[] = $item->id;

                continue;
            }

            $lineNet = BigDecimal::of($price->unitNet)->multipliedBy($item->quantity)->toScale(4, RoundingMode::HalfUp);
            $kind = ProductKind::stored($kinds[$item->articleId]['kind'] ?? null);
            $taxCountry = $kind->taxCountry($customerCountry, $shopCountry, $taxAtDestination, $kinds[$item->articleId]['singlePurpose'] ?? false);

            try {
                $lineGross = $this->grossCalculator->gross((string) $lineNet, $price->taxClassId, $taxCountry, $now);
            } catch (TaxRateNotFound) {
                // No rate, no gross — the same answer as no price at all
                // (ArticlePriceResolver says so for the product page).
                $unpriced[] = $item->articleId;
                $unpricedItems[] = $item->id;

                continue;
            }

            $lines[] = new CartLine(
                $item->id,
                $item->articleId,
                $mainProductId,
                $item->quantity,
                $price->unitNet,
                (string) $lineNet,
                $lineGross,
                $price->taxClassId,
                $item->subscriptionRule,
                $item->renews,
                $taxCountry,
                $price->source,
                $kind,
            );

            $quantity = $quantity->plus($item->quantity);

            /*
             * Rounded to the currency's own place BEFORE it goes into the
             * total, not after. What the visitor reads on a line is the
             * rounded amount, and they add those up — so the total has to be
             * built from the same numbers, or the column does not produce the
             * figure underneath it. It takes no tie to go wrong, just enough
             * collected remainders: 10.0040 twice shows as 10.00 and 10.00 and
             * summed raw reads 20.01.
             *
             * This does NOT touch the net anchor (C22). $lineNet is still unit
             * net × quantity, $lineGross still comes out of THAT ungrounded
             * line net, and no summed net is ever grossed up. The line keeps
             * its four places on CartLine for exactly that reason; what is
             * rounded is the amount as it enters the sum, not the arithmetic
             * behind it.
             */
            $net = $net->plus($lineNet->toScale($digits, RoundingMode::HalfUp));
            $gross = $gross->plus(BigDecimal::of($lineGross)->toScale($digits, RoundingMode::HalfUp));
        }

        /*
         * The net total is rounded from rounded lines for the same reason as
         * the gross one, and not merely for symmetry: the basket page and the
         * order both show a net column, and a net total that does not add up
         * its own lines is the identical complaint one column to the left.
         *
         * The totals therefore carry the currency's places, not four. Nothing
         * downstream rounds them differently: MoneyFormatter::format() asks
         * Currencies::getFractionDigits() for the same number and rounds half
         * up as well, so a second rounding of an already rounded amount cannot
         * move it. That agreement is the whole mechanism — the displayed line
         * is the summed line only while both sides keep the same source and
         * the same rounding mode.
         */
        return new CartView(
            $lines,
            $unpriced,
            (string) $quantity->toScale(4, RoundingMode::HalfUp),
            (string) $net->toScale($digits, RoundingMode::HalfUp),
            (string) $gross->toScale($digits, RoundingMode::HalfUp),
            $currency,
            $unpricedItems,
        );
    }
}

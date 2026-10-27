<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRule;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;

/**
 * The one-time offer of an article: its price at quantity one, the price
 * struck through beside it, and the base price — or nothing, when the article
 * is not sold once (Connect-1, K2).
 *
 * The product page and the catalogue reader both ask here. The rules stood
 * privately in ProductDetailController before, and a feed that
 * worked them out a second time would be the copy that forgets one of them —
 * so that no building block exists twice, this class exists:
 *
 * - Not sold once, no offer: a one-time price row left over from before is
 *   no price (Abo-2), and a monthly amount is none either.
 * - Struck through only for a guest's one-time promotion whose projected
 *   figure is the very price shown (ComparePrices).
 *
 * ── Two countries ────────────────────────────────────────────────────────
 *
 * `$market` is the shop's home country — the list price rows, and with them
 * the struck price, belong to its market (C25). `$taxCountry` is the country
 * whose rate makes the gross: the same for the product page, the delivery
 * country's under OSS for a feed (K1). Where they differ, the struck gross is
 * recalculated from its net at the tax country's rate, so the two figures
 * beside each other compare like with like.
 */
final class OneTimeOffer
{
    public function __construct(
        private readonly ArticlePriceResolver $prices,
        private readonly SubscriptionOffers $offers,
        private readonly ComparePrices $compares,
        private readonly GrossCalculator $gross,
        private readonly ArticleTaxClassesInterface $taxClasses,
    ) {
    }

    /**
     * @param array{unit: string, purchaseUnit: string, referenceUnit: string} $units  the set that applies to this article (ProductDetailAssembler)
     * @param list<int>                                                        $groups member groups; empty for a guest
     * @param array<int, SubscriptionRule|null>|null                           $offered what SubscriptionOffers::offered() already answered for this article and these groups — a caller that needs it too passes it rather than asking twice (Connect-1)
     */
    public function of(int $articleId, int $mainId, string $currency, string $market, string $taxCountry, int $now, array $units, array $groups, array|null $offered = null): OneTimePrice|null
    {
        if (!\array_key_exists(0, $offered ?? $this->offers->offered($articleId, $groups))) {
            return null;
        }

        $price = $this->prices->forArticle($articleId, $mainId, $currency, $taxCountry, $now, $units, $groups);

        if (null === $price) {
            return null;
        }

        $compare = $this->compares->for($articleId, $currency, $market, $price->price);

        if (null === $compare) {
            return new OneTimePrice($price);
        }

        [$compareNet, $compareGross] = $compare;

        if ($taxCountry !== $market) {
            try {
                $compareGross = $this->gross->gross($compareNet, $this->taxClasses->taxClassOf($articleId), $taxCountry, $now);
            } catch (TaxRateNotFound) {
                // The price itself found a rate a moment ago; should the
                // compare not, no struck price rather than one at another rate.
                return new OneTimePrice($price);
            }
        }

        return new OneTimePrice($price, $compareNet, $compareGross);
    }

    /**
     * What a basket line of `$quantity` pieces costs, bought once — the
     * amount a channel wants for an article with a minimum purchase (idealo:
     * "der Gesamtpreis für die Mindestabnahmemenge"; Connect-1).
     *
     * Worked out as CartPricer does it, so the feed and the basket cannot
     * name two amounts: the unit net at THIS quantity (a scale price
     * applies), times the quantity, four places, and the gross of that line
     * — not a rounded unit gross multiplied (C22).
     *
     * @param list<int> $groups member groups; empty for a guest
     */
    public function lineAt(int $articleId, int $mainId, string $currency, string $taxCountry, int $now, string $quantity, array $groups): PurchaseLine|null
    {
        if (!\array_key_exists(0, $this->offers->offered($articleId, $groups))) {
            return null;
        }

        $price = $this->prices->forCartLine($articleId, $mainId, $currency, $quantity, $now, $groups);

        if (null === $price) {
            return null;
        }

        $net = (string) BigDecimal::of($price->unitNet)->multipliedBy($quantity)->toScale(4, RoundingMode::HalfUp);

        try {
            $gross = $this->gross->gross($net, $price->taxClassId, $taxCountry, $now);
        } catch (TaxRateNotFound) {
            return null;
        }

        return new PurchaseLine($quantity, $net, $gross);
    }
}

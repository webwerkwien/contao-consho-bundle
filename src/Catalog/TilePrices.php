<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRules;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionTerms;

/**
 * The price of each tile as a buyer reads it (Abo-2b, P88): "13,00 €", or
 * for a product listed with a plan "1,20 € / Monat" — the same figure the
 * product page puts up top.
 *
 * A class of its own rather than a few lines in ProductListController (Fable
 * review Abo-2b, S3): there the "/ Monat" hung on two optional dependencies,
 * and a listing without them would have printed a monthly amount as if it
 * were a price — silently, and no test built the controller. Required here,
 * and the rules of a whole page are read at once.
 */
final class TilePrices
{
    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly SubscriptionRules $rules,
        private readonly SubscriptionTerms $terms,
    ) {
    }

    /**
     * @param list<ProductTile> $tiles
     *
     * @return list<string> one per tile, in the same order; '' for a tile without a price
     */
    public function formatted(array $tiles, string $currency, string $language): array
    {
        $ruleIds = array_values(array_unique(array_filter(
            array_map(static fn (ProductTile $tile): int => '' === $tile->price ? 0 : $tile->subscriptionRule, $tiles),
            static fn (int $id): bool => $id > 0,
        )));
        $rules = $this->rules->byIds($ruleIds);
        $prices = [];

        foreach ($tiles as $tile) {
            if ('' === $tile->price) {
                $prices[] = '';

                continue;
            }

            $price = $this->money->format($tile->price, $currency, $language);

            if (0 === $tile->subscriptionRule) {
                $prices[] = $price;

                continue;
            }

            // A rule gone since the projection ran: an amount per period
            // without its period would read as a price — so none, until the
            // next refresh (SubscriptionRuleRefreshTriggerListener) catches up.
            $rule = $rules[$tile->subscriptionRule] ?? null;
            $prices[] = null === $rule ? '' : $this->terms->perPeriod($rule, $price, $language);
        }

        return $prices;
    }

    /**
     * The price struck through next to each tile's figure (block H, H-2) —
     * only where the figure is one article's one-time price: a "from" over
     * variants that differ names no single article to compare, and a plan's
     * amount per period is no reduced one-time price.
     *
     * @param list<ProductTile> $tiles
     *
     * @return list<string> one per tile, in the same order; '' for none
     */
    public function compares(array $tiles, string $currency, string $language): array
    {
        return array_map(
            fn (ProductTile $tile): string => '' === $tile->compare || '' === $tile->price || $tile->isFrom || 0 !== $tile->subscriptionRule
                ? ''
                : $this->money->format($tile->compare, $currency, $language),
            $tiles,
        );
    }
}

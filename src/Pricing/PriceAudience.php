<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * Who a page is pricing for (C-4).
 *
 * Three answers that look like one question and are not:
 *
 *   - WHICH PRICE  — `groups`, handed to PriceCalculator. A set, because
 *                    `tl_member.groups` is a multiple selection.
 *   - HOW IT READS — `showsNet`, from the shop's `netPriceGroups`. A wholesale
 *                    group may pay the list price and still want net amounts.
 *   - WHAT TO SAY  — `groupLabel`, for the listing's hint (E1). Empty when
 *                    there is nothing to explain.
 *
 * Keeping them in one value keeps them from drifting: every controller that
 * prices asks once and passes this on, rather than each deciding for itself
 * what "logged in" means.
 */
final class PriceAudience
{
    /**
     * @param list<int> $groups     the visitor's member groups — empty for a guest
     * @param string    $groupLabel the groups that actually carry prices, for the hint
     */
    public function __construct(
        public readonly array $groups = [],
        public readonly bool $showsNet = false,
        public readonly string $groupLabel = '',
    ) {
    }

    /**
     * Nobody logged in: no group, gross, nothing to explain.
     *
     * Named rather than assembled at each call site, because it is a rule and
     * not a default. Before a display can be net, someone has to be liable for
     * the VAT they then add themselves — a guest never is.
     */
    public static function guest(): self
    {
        return new self();
    }

    /**
     * Whether the listing should say where this visitor's own price is.
     *
     * Not the same as being logged in, and not the same as `showsNet`: it is
     * true only when one of the visitor's groups carries prices somewhere in
     * the catalogue. Otherwise the hint would promise a personal price to
     * every member of every newsletter group.
     */
    public function hasOwnPrices(): bool
    {
        return '' !== $this->groupLabel;
    }
}

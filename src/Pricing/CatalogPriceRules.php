<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;

/**
 * Catalogue price rules (block H, H-3; tl_consho_price_rule) on the price
 * PriceCalculator found — the stage "Aktion" of block A's order, after the
 * rows have decided.
 *
 * - A rule matches when every condition it states matches (empty = any): the
 *   main product, its manufacturer, its product type, one of its filter
 *   properties (the article's own or its main product's), one of the
 *   visitor's groups.
 * - Not on a price that is already a promotion, unless the rule says so
 *   (`reduced`). Then it works on the promotion price.
 * - Several rules: the cheapest result, no sum (C2).
 * - Only ever cheaper: a result not below the price leaves it as it is.
 *
 * The result is a promotion, so the strike-through works as for a price row
 * (H-2). The group that won stays on the price: a member's group price taken
 * down by a rule is still that group's, and gets no strike-through.
 *
 * Rules and product attributes are read once per request; ArticlePriceResolver
 * asks per article, per threshold and per market.
 */
class CatalogPriceRules implements ResetInterface
{
    /** @var list<array{id: int, percent: string, reduced: bool, products: list<int>, manufacturers: list<int>, productTypes: list<int>, propertyOptions: list<int>, memberGroups: list<int>, validFrom: int|null, validTo: int|null}>|null */
    private array|null $rules = null;

    private readonly ProductConditions $conditions;

    public function __construct(
        private readonly Connection $connection,
        // Shared with the cart rules (H-4); built here when not injected.
        ProductConditions|null $conditions = null,
    ) {
        $this->conditions = $conditions ?? new ProductConditions($connection);
    }

    /** @var array<int, bool> main product id => sold as a voucher (WG8) */
    private array $vouchers = [];

    /**
     * @param list<int> $customerGroups the visitor's groups — empty for a guest
     */
    public function apply(int $articleId, int $mainProductId, ResolvedPrice $price, array $customerGroups, int $now): ResolvedPrice
    {
        $best = BigDecimal::of($price->net);
        $applied = false;
        $group = $price->customerGroup;

        foreach ($this->rules() as $rule) {
            if (!self::validAt($rule, $now)
                || (PriceSource::Promotion === $price->source && !$rule['reduced'])
                || !$this->matches($rule, $articleId, $mainProductId, $customerGroups)) {
                continue;
            }

            $net = BigDecimal::of($price->net)
                ->multipliedBy(BigDecimal::of('100')->minus($rule['percent']))
                ->dividedBy('100', 4, RoundingMode::HalfUp);

            if ($net->isLessThan($best)) {
                $best = $net;
                $applied = true;

                /*
                 * Block H, P4: a rule for some groups is a group price, even
                 * on the guests' list price — it carries the group, so no
                 * strike-through price reaches it (§ 11 Abs. 4 Nr. 1 PAngV:
                 * individual reductions; ComparePrices asks for no group).
                 * Until then only the net comparison in ComparePrices kept it
                 * away, by chance of the projection being the guests'.
                 */
                $group = [] === $rule['memberGroups'] ? $price->customerGroup : ($price->customerGroup ?? min(array_intersect($rule['memberGroups'], $customerGroups)));
            }
        }

        /*
         * A voucher is never reduced by a rule (WG8): its price is what it
         * costs, its value what it is worth, and a rule between the two would
         * sell money below its value without anyone having said so. Asked
         * only once a rule applies — most articles meet none.
         */
        if (!$applied || $this->isVoucher($mainProductId)) {
            return $price;
        }

        return new ResolvedPrice((string) $best->toScale(4), $price->currency, PriceSource::Promotion, $price->minQuantity, $group);
    }

    /**
     * Main products a published rule matches whose start or end lies in
     * (last, now] — the boundary cron refreshes them (H-3).
     *
     * @return list<int>
     */
    public function productIdsWithBoundary(int $last, int $now): array
    {
        $ids = [];

        foreach ($this->rules() as $rule) {
            $from = $rule['validFrom'];
            $to = $rule['validTo'];

            if ((null !== $from && $from > $last && $from <= $now) || (null !== $to && $to > $last && $to <= $now)) {
                $ids = [...$ids, ...$this->productIdsOf($rule)];
            }
        }

        return array_values(array_unique($ids));
    }

    public function reset(): void
    {
        $this->rules = null;
        $this->vouchers = [];
        $this->conditions->reset();
    }

    /**
     * Whether the main product's type is of the kind "Wertgutschein" — the
     * kind of a variant is its main product's (DbalArticleShippingData).
     */
    private function isVoucher(int $mainProductId): bool
    {
        return $this->vouchers[$mainProductId] ??= ProductKind::Voucher->value === $this->connection->fetchOne(
            'SELECT t.kind FROM tl_consho_product p JOIN tl_consho_product_type t ON t.id = p.productType WHERE p.id = ?',
            [$mainProductId],
        );
    }

    /**
     * Main products the rule's product conditions match — groups aside, they
     * are a visitor's, not a product's.
     *
     * @param array{products: list<int>, manufacturers: list<int>, productTypes: list<int>, propertyOptions: list<int>} $rule
     *
     * @return list<int>
     */
    private function productIdsOf(array $rule): array
    {
        $ids = [];

        foreach ($this->connection->fetchFirstColumn('SELECT id FROM tl_consho_product WHERE pid = 0 ORDER BY id') as $id) {
            if ($this->matchesProduct($rule, (int) $id, (int) $id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @param array{products: list<int>, manufacturers: list<int>, productTypes: list<int>, propertyOptions: list<int>, memberGroups: list<int>} $rule
     * @param list<int>                                                                                                                         $customerGroups
     */
    private function matches(array $rule, int $articleId, int $mainProductId, array $customerGroups): bool
    {
        if ([] !== $rule['memberGroups'] && [] === array_intersect($rule['memberGroups'], $customerGroups)) {
            return false;
        }

        return $this->matchesProduct($rule, $articleId, $mainProductId);
    }

    /**
     * @param array{products: list<int>, manufacturers: list<int>, productTypes: list<int>, propertyOptions: list<int>} $rule
     */
    private function matchesProduct(array $rule, int $articleId, int $mainProductId): bool
    {
        return $this->conditions->matches($rule, $articleId, $mainProductId);
    }

    /**
     * @param array{validFrom: int|null, validTo: int|null} $rule
     */
    private static function validAt(array $rule, int $now): bool
    {
        // From inclusive, to exclusive — as DbalPriceRepository.
        return (null === $rule['validFrom'] || $rule['validFrom'] <= $now) && (null === $rule['validTo'] || $rule['validTo'] > $now);
    }

    /**
     * Published rules, all of them — validity is asked per moment, the
     * boundary cron needs the ones not valid now as well.
     *
     * @return list<array{id: int, percent: string, reduced: bool, products: list<int>, manufacturers: list<int>, productTypes: list<int>, propertyOptions: list<int>, memberGroups: list<int>, validFrom: int|null, validTo: int|null}>
     */
    private function rules(): array
    {
        if (null !== $this->rules) {
            return $this->rules;
        }

        $this->rules = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_consho_price_rule WHERE published = 1 AND tstamp > 0 AND percent > 0 ORDER BY id') as $row) {
            $this->rules[] = [
                'id' => (int) $row['id'],
                // Never more than everything. BigDecimal::min() is static — on an
                // instance it ignores the instance and returned 100 for every rule.
                'percent' => (string) BigDecimal::min((string) $row['percent'], '100'),
                'reduced' => (bool) $row['reduced'],
                'products' => self::ids($row['products']),
                'manufacturers' => self::ids($row['manufacturers']),
                'productTypes' => self::ids($row['productTypes']),
                'propertyOptions' => self::ids($row['propertyOptions']),
                'memberGroups' => self::ids($row['memberGroups']),
                'validFrom' => null === $row['validFrom'] ? null : (int) $row['validFrom'],
                'validTo' => null === $row['validTo'] ? null : (int) $row['validTo'],
            ];
        }

        return $this->rules;
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $serialized): array
    {
        return array_values(array_filter(array_map('intval', StringUtil::deserialize($serialized, true)), static fn (int $id): bool => $id > 0));
    }
}

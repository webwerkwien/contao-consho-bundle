<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart\Discount;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Currencies;
use Symfony\Contracts\Service\ResetInterface;
use Webwerkwien\ContaoConshoBundle\Cart\CartLine;
use Webwerkwien\ContaoConshoBundle\Cart\CartView;
use Webwerkwien\ContaoConshoBundle\Checkout\ShippingShare;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\ProductConditions;

/**
 * Which cart rule applies to a basket, and what it takes off (block H, H-4;
 * Block H, H6, C3, W1–W6).
 *
 * The amounts are worked out in the customer's own reading (W2): per tax
 * class, the class's goods as the customer sees them (gross, or net for a net
 * audience) give the amount off, rounded to the currency, and its net is that
 * amount at the class's own net-to-gross ratio. So the shares add up to
 * exactly what the customer reads — "−10,00 €" and not "−9,99 €" — and each
 * stays at its class's rate, as the payment discount does (C-7).
 *
 * Several rules: the cheapest for the customer, no sum (C3); on a tie the code
 * the customer typed. Free shipping is worth the shipping it saves, which is
 * only known in the checkout — in the basket it counts as nothing.
 */
class CartDiscounts implements ResetInterface
{
    /** @var list<array<string, mixed>>|null */
    private array|null $rules = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ProductConditions $conditions,
    ) {
    }

    /**
     * @param list<int> $customerGroups
     * @param string    $shipping       the chosen shipping as the customer reads it — '0' in the basket
     */
    public function evaluate(
        CartView $cart,
        int $shopId,
        string $code,
        array $customerGroups,
        bool $showsNet,
        int $memberId,
        string $email,
        int $now,
        string $shipping = '0',
    ): CartDiscountResult {
        $code = trim($code);
        $best = null;
        $bestValue = null;
        $codeOutcome = null;
        $codeKnown = false;

        foreach ($this->rules() as $rule) {
            $isCodeRule = '' !== $rule['code'];

            // W6: exactly as typed, case included — the column's collation would not.
            if ($isCodeRule && ('' === $code || $rule['code'] !== $code)) {
                continue;
            }

            $codeKnown = $codeKnown || $isCodeRule;
            $outcome = $this->outcomeOf($rule, $cart, $shopId, $customerGroups, $showsNet, $memberId, $email, $now, $shipping);

            if ($isCodeRule && (null === $codeOutcome || $outcome instanceof CartDiscount)) {
                $codeOutcome = $outcome;
            }

            if (!$outcome instanceof CartDiscount) {
                continue;
            }

            $value = $outcome->freeShipping ? BigDecimal::of($shipping) : BigDecimal::of($outcome->displayed());

            // The cheapest for the customer; on a tie the typed code.
            if (null === $bestValue || $value->isGreaterThan($bestValue) || ($value->isEqualTo($bestValue) && $isCodeRule && '' === $best?->code)) {
                $best = $outcome;
                $bestValue = $value;
            }
        }

        if ('' === $code) {
            return new CartDiscountResult($best);
        }

        if (!$codeKnown) {
            return new CartDiscountResult($best, CodeStatus::Unknown);
        }

        if ($codeOutcome instanceof CartDiscount) {
            return new CartDiscountResult($best, null !== $best && '' !== $best->code ? CodeStatus::Applied : CodeStatus::Superseded);
        }

        [$status, $missing] = $codeOutcome ?? [CodeStatus::Unknown, ''];

        return new CartDiscountResult($best, $status, $missing);
    }

    /**
     * Whether a published rule carries exactly this code (W6: case included)
     * — whether it applies here and now is evaluate()'s question. What the
     * basket asks before it counts a failed attempt (WG5): a code that exists
     * but does not fit this basket is not a guess.
     */
    public function knows(string $code): bool
    {
        $code = trim($code);

        if ('' === $code) {
            return false;
        }

        foreach ($this->rules() as $rule) {
            if ($rule['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts the use at the purchase click, in the order's transaction: locks
     * the rule row, counts again, then records — so the last use goes to one
     * order only. Throws when it is gone meanwhile.
     *
     * ⚠️ Correct only under READ COMMITTED, which OrderPlacer sets for the
     * purchase (H): the second buyer waits for the lock, then has
     * to SEE the first one's redemption when counting. Under REPEATABLE READ
     * the count would read the snapshot taken before the lock, and both
     * would get the last use — the trap the last key of block G fell into.
     *
     * @throws CartRuleExhaustedAtPurchase
     */
    public function redeem(int $ruleId, string $code, int $orderId, int $memberId, string $email, int $now): void
    {
        $row = $this->connection->fetchAssociative('SELECT maxUses, maxUsesPerCustomer FROM tl_consho_cart_rule WHERE id = ? FOR UPDATE', [$ruleId]);

        if (false === $row) {
            throw CartRuleExhaustedAtPurchase::for($ruleId);
        }

        if ($this->exhausted((int) $row['maxUses'], (int) $row['maxUsesPerCustomer'], $ruleId, $memberId, $email)) {
            throw CartRuleExhaustedAtPurchase::for($ruleId);
        }

        $this->connection->insert('tl_consho_cart_rule_redemption', [
            'tstamp' => $now,
            'rule' => $ruleId,
            'orderId' => $orderId,
            'member' => $memberId,
            'email' => mb_strtolower(trim($email)),
            'code' => $code,
        ]);
    }

    /**
     * A cancelled order gives its uses back (H-5): the rows stay, for the
     * order's history, and no longer count — a voucher for "first order
     * only" works again after the first order was cancelled. Called in the
     * cancellation's transaction, once (guarded by `cancelledAt`).
     */
    public function afterCancellation(int $orderId, int $now): void
    {
        $this->connection->executeStatement(
            'UPDATE tl_consho_cart_rule_redemption SET cancelled = 1, tstamp = ? WHERE orderId = ? AND cancelled = 0',
            [$now, $orderId],
        );
    }

    public function reset(): void
    {
        $this->rules = null;
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<int>            $customerGroups
     *
     * @return CartDiscount|array{CodeStatus, string}
     */
    private function outcomeOf(array $rule, CartView $cart, int $shopId, array $customerGroups, bool $showsNet, int $memberId, string $email, int $now, string $shipping): CartDiscount|array
    {
        if ((null !== $rule['validFrom'] && $rule['validFrom'] > $now) || (null !== $rule['validTo'] && $rule['validTo'] <= $now)) {
            return [CodeStatus::NotActive, ''];
        }

        $statesAmount = 'amount' === $rule['effect'] || BigDecimal::of($rule['minimumOrder'])->isPositive();

        if (([] !== $rule['shops'] && !\in_array($shopId, $rule['shops'], true))
            || ([] !== $rule['memberGroups'] && [] === array_intersect($rule['memberGroups'], $customerGroups))
            || ($statesAmount && $rule['currency'] !== $cart->currency)) {
            return [CodeStatus::NotForYou, ''];
        }

        // W3: the goods after catalogue rules, no subscriptions, as the customer reads them.
        // No vouchers either (WG8): buying money is no order value to reward.
        $goods = BigDecimal::zero();

        foreach ($cart->lines as $line) {
            if (0 === $line->subscriptionRule && !$line->kind->isVoucher()) {
                $goods = $goods->plus($showsNet ? $line->lineNet : $line->lineGross);
            }
        }

        $minimum = BigDecimal::of($rule['minimumOrder']);

        if ($goods->isLessThan($minimum)) {
            return [CodeStatus::MinimumOrder, (string) $minimum->minus($goods)->toScale(Currencies::getFractionDigits($cart->currency), RoundingMode::HalfUp)];
        }

        // Keyed by the position in the basket, which the purchase reads the split by (H-5).
        $base = array_filter($cart->lines, fn (CartLine $line): bool => 0 === $line->subscriptionRule
            && !$line->kind->isVoucher()
            && ($rule['reduced'] || PriceSource::Promotion !== $line->source)
            && $this->conditions->matches($rule, $line->articleId, $line->mainProductId));

        if ([] === $base) {
            return [CodeStatus::NoMatchingGoods, ''];
        }

        if ($this->exhausted($rule['maxUses'], $rule['maxUsesPerCustomer'], $rule['id'], $memberId, $email)) {
            return [CodeStatus::Exhausted, ''];
        }

        if ('freeShipping' === $rule['effect']) {
            return new CartDiscount($rule['id'], $rule['title'], $rule['code'], [], true);
        }

        [$shares, $lines] = $this->sharesOf($rule, $base, $showsNet, $cart->currency);

        return new CartDiscount($rule['id'], $rule['title'], $rule['code'], $shares, false, $lines);
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<int, CartLine> $base position in the basket => line
     *
     * @return array{list<ShippingShare>, array<int, string>} the shares per class, and each
     *         class's net once more per line of the base, by what the customer reads of it —
     *         the remainder on the class's last line, so they add up to the share
     */
    private function sharesOf(array $rule, array $base, bool $showsNet, string $currency): array
    {
        $digits = Currencies::getFractionDigits($currency);

        /*
         * Per class AND country (H): a service and a good may
         * share a class and still be taxed in different countries (DL4);
         * the amount off each is at that supply's own rate.
         *
         * @var array<string, array{net: BigDecimal, shown: BigDecimal}> $classes
         */
        $classes = [];

        foreach ($base as $line) {
            $key = $line->taxClassId.'|'.strtoupper($line->taxCountry);
            $class = $classes[$key] ?? ['net' => BigDecimal::zero(), 'shown' => BigDecimal::zero()];
            $classes[$key] = [
                'net' => $class['net']->plus($line->lineNet),
                'shown' => $class['shown']->plus($showsNet ? $line->lineNet : $line->lineGross),
            ];
        }

        $total = BigDecimal::zero();

        foreach ($classes as $class) {
            $total = $total->plus($class['shown']);
        }

        if (!$total->isPositive()) {
            return [[], []];
        }

        /** @var array<string, BigDecimal> $off */
        $off = [];

        if ('percent' === $rule['effect']) {
            foreach ($classes as $id => $class) {
                $off[$id] = $class['shown']->multipliedBy($rule['percent'])->dividedBy('100', $digits, RoundingMode::HalfUp);
            }
        } else {
            // W2: a fixed amount, never more than the base — split by the
            // classes' share of it, the remainder on the biggest.
            $amount = BigDecimal::min(BigDecimal::of($rule['amount'])->toScale($digits, RoundingMode::HalfUp), $total->toScale($digits, RoundingMode::HalfUp));
            $biggest = null;
            $given = BigDecimal::zero();

            foreach ($classes as $id => $class) {
                $off[$id] = $amount->multipliedBy($class['shown'])->dividedBy($total, $digits, RoundingMode::HalfUp);
                $given = $given->plus($off[$id]);

                if (null === $biggest || $class['shown']->isGreaterThan($classes[$biggest]['shown'])) {
                    $biggest = $id;
                }
            }

            $off[(string) $biggest] = $off[(string) $biggest]->plus($amount->minus($given));
        }

        $shares = [];
        $lines = [];

        foreach ($off as $id => $amountOff) {
            if (!$amountOff->isPositive() || !$classes[$id]['shown']->isPositive()) {
                continue;
            }

            $net = $amountOff->multipliedBy($classes[$id]['net'])->dividedBy($classes[$id]['shown'], 4, RoundingMode::HalfUp);
            [$classId, $country] = explode('|', (string) $id);
            $shares[] = new ShippingShare((int) $classId, (string) $net, (string) $amountOff, $country);

            $inClass = array_filter($base, static fn (CartLine $line): bool => $line->taxClassId.'|'.strtoupper($line->taxCountry) === (string) $id);
            $last = array_key_last($inClass);
            $left = $net;

            foreach ($inClass as $position => $line) {
                $part = $position === $last
                    ? $left
                    : $net->multipliedBy($showsNet ? $line->lineNet : $line->lineGross)->dividedBy($classes[$id]['shown'], 4, RoundingMode::HalfUp);
                $left = $left->minus($part);
                $lines[$position] = (string) $part;
            }
        }

        return [$shares, $lines];
    }

    private function exhausted(int $maxUses, int $perCustomer, int $ruleId, int $memberId, string $email): bool
    {
        if ($maxUses > 0 && $this->usesOf($ruleId) >= $maxUses) {
            return true;
        }

        if ($perCustomer < 1) {
            return false;
        }

        // W5: a member by account, a guest by address — unknown in the basket
        // until the checkout asks for it.
        if ($memberId > 0) {
            return $this->usesOf($ruleId, 'member = ?', [$memberId]) >= $perCustomer;
        }

        $email = mb_strtolower(trim($email));

        return '' !== $email && $this->usesOf($ruleId, 'email = ?', [$email]) >= $perCustomer;
    }

    /**
     * @param list<int|string> $params
     */
    private function usesOf(int $ruleId, string $where = '1 = 1', array $params = []): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_consho_cart_rule_redemption WHERE rule = ? AND cancelled = 0 AND '.$where,
            [$ruleId, ...$params],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rules(): array
    {
        if (null !== $this->rules) {
            return $this->rules;
        }

        $this->rules = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_consho_cart_rule WHERE published = 1 AND tstamp > 0 ORDER BY id') as $row) {
            $this->rules[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'code' => trim((string) $row['code']),
                'effect' => (string) $row['effect'],
                'percent' => (string) BigDecimal::min((string) $row['percent'], '100'),
                'amount' => (string) $row['amount'],
                'reduced' => (bool) $row['reduced'],
                'shops' => self::ids($row['shops']),
                'currency' => (string) $row['currency'],
                'minimumOrder' => (string) $row['minimumOrder'],
                'products' => self::ids($row['products']),
                'manufacturers' => self::ids($row['manufacturers']),
                'productTypes' => self::ids($row['productTypes']),
                'memberGroups' => self::ids($row['memberGroups']),
                'maxUses' => (int) $row['maxUses'],
                'maxUsesPerCustomer' => (int) $row['maxUsesPerCustomer'],
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

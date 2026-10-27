<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * One line to be. Two shapes, one type — because an order line is one row
 * whatever put it there.
 *
 * **A product line** names an article and a quantity, and nothing else: price,
 * tax class, name and item number are LOOKED UP at the click, so that the order
 * records what was true then rather than what a form said.
 *
 * **A charge line** — shipping, a payment fee or discount — arrives with its
 * amount already decided. The checkout worked it out (CheckoutCalculator), and
 * proportional shipping tax has already split it per tax class, so one method
 * can become several lines. Looking it up again here would mean a second
 * calculator, and the two would eventually disagree about the same basket.
 *
 * **A frozen line** (Abo-4) is a product line of a subscription's follow-up
 * order: amount, name, item number, terms and kind as the purchase
 * froze them (A9). Nothing is looked up but the tax rate of its class.
 */
final class OrderLineDraft
{
    /**
     * @param int    $productId  the article, 0 on a charge line
     * @param string $net        the fixed net of a charge line, empty on a product line
     * @param int    $taxClassId the class of a charge line, 0 on a product line
     * @param int    $source     the shipping or payment method behind a charge line
     * @param int    $subscriptionRule the plan of a product line (Abo-2): 0 = one-time sale
     */
    private function __construct(
        public readonly int $productId,
        public readonly string $quantity,
        public readonly OrderLineType $type,
        public readonly string $title,
        public readonly string $net,
        public readonly int $taxClassId,
        public readonly int $source,
        public readonly int $subscriptionRule = 0,
        public readonly bool $frozen = false,
        public readonly string $sku = '',
        public readonly string $terms = '',
        // The frozen ProductKind value of a follow-up line (DL1).
        public readonly string $kind = '',
        // The subscription a product line renews (Abo-7b, P69): 0 = none.
        public readonly int $renews = 0,
        // The net the cart rule gave this product line (H-5), positive; frozen on the order line.
        public readonly string $discountNet = '0',
        // A reduction's country (H): '' = the delivery's, as for every other charge.
        public readonly string $taxCountry = '',
    ) {
    }

    /**
     * A subscription's follow-up line (Abo-4, P25): the frozen net per licence,
     * the licences, the class whose CURRENT rate applies, and the texts of the
     * first order line — so a product renamed or deleted since does not stop
     * the billing.
     */
    public static function frozen(int $productId, string $quantity, string $net, int $taxClassId, int $subscriptionRule, string $terms, string $name, string $sku, string $kind): self
    {
        if (!is_numeric($quantity) || (float) $quantity <= 0.0) {
            throw new \InvalidArgumentException(\sprintf('A follow-up line needs a positive quantity, got "%s".', $quantity));
        }

        if (!is_numeric($net) || (float) $net < 0.0) {
            throw new \InvalidArgumentException(\sprintf('A follow-up line needs an amount of at least zero, got "%s".', $net));
        }

        return new self($productId, $quantity, OrderLineType::Product, $name, $net, $taxClassId, 0, $subscriptionRule, true, $sku, $terms, $kind);
    }

    /**
     * The plan is the one thing besides article and quantity a product line
     * names (Abo-2, P13): it is which contract's price gets looked up. A
     * renewal (Abo-7b) names the subscription it continues as well. And the
     * part of the cart rule's discount the checkout gave it (H-5), which no
     * later lookup could tell.
     */
    public static function product(int $productId, string $quantity = '1', int $subscriptionRule = 0, int $renews = 0, string $discountNet = '0'): self
    {
        return new self($productId, $quantity, OrderLineType::Product, '', '', 0, 0, $subscriptionRule, renews: $renews, discountNet: $discountNet);
    }

    /**
     * Shipping, a payment fee or a payment discount.
     *
     * The net is SIGNED: a discount arrives negative, the way CheckoutCalculator
     * already hands it out. Negating it here as well would turn a reduction back
     * into a surcharge, and the total would be right while the invoice line was
     * not.
     */
    public static function charge(OrderLineType $type, string $title, string $net, int $taxClassId, int $source = 0, string $taxCountry = ''): self
    {
        if (OrderLineType::Product === $type) {
            throw new \InvalidArgumentException('A product line is created with product(), which looks its price up.');
        }

        return new self(0, '1', $type, $title, $net, $taxClassId, $source, taxCountry: $taxCountry);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * What a line of an order is (spec 5.2, C-7).
 *
 * Until C-7 an order held product lines only, and shipping lived as a figure on
 * the order head. An invoice has to show shipping as a line with its own tax
 * rate, so it becomes one — and then the line needs to say what it is, because
 * "Paket" is not a product and must not be counted as one.
 *
 * **Shipping can occur more than once.** With proportional shipping tax a
 * basket spanning two rates produces one shipping line per rate
 * (ShippingTaxSplitter, C-5), each carrying the share that belongs to it.
 *
 * **A fee and a discount are two cases, not one signed figure.** They differ in
 * law before they differ in arithmetic: a discount is a reduction of the
 * consideration for the goods, while a fee is a charge in its own right —
 * unlawful in Austria for payment instruments (§ 56 Abs 3 ZaDiG 2018) and in
 * Germany restricted to what § 270a BGB leaves over. The invoice has to label
 * them differently, so the order stores them differently.
 */
enum OrderLineType: string
{
    case Product = 'product';
    case Shipping = 'shipping';

    /**
     * A charge for using a payment method.
     *
     * Taxed the way the operator chose at the method (E-1, Z3): split over
     * the goods like shipping, or at one fixed class — Consho does not decide
     * which (docs/block-e-versand-zahlung.md, section 3). Until E-1 a fee was
     * refused.
     */
    case PaymentFee = 'paymentFee';

    /**
     * A reduction for using a payment method — a prepayment discount, say.
     *
     * Taxed proportionally across the rates in the basket: a reduction of the
     * consideration reduces it per rate, which is the one case here that is not
     * open to interpretation.
     */
    case PaymentDiscount = 'paymentDiscount';

    /**
     * A cart rule's discount (block H, H-4) — percent or a fixed amount.
     *
     * Like the payment discount a reduction of the consideration, so one
     * line per rate of the goods it reduces (W2). Not goods: nothing is
     * delivered, and shipping tax must not split across it.
     */
    case Discount = 'discount';

    public static function stored(string|null $value): self
    {
        return self::tryFrom((string) $value) ?? self::Product;
    }

    /**
     * Whether this line is a product the customer chose.
     *
     * Used wherever "the goods" are meant rather than "everything on the
     * invoice" — the proportional split of shipping tax, for one, which must
     * not distribute itself across itself.
     */
    public function isGoods(): bool
    {
        return self::Product === $this;
    }
}

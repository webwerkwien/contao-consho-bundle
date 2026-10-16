<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Brick\Math\BigDecimal;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscount;
use Webwerkwien\ContaoConshoBundle\Voucher\AppliedVoucher;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CodeStatus;

use Webwerkwien\ContaoConshoBundle\Cart\CartView;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustmentOrigin;

/**
 * The whole checkout as one calculated answer (7.2). Built fresh on every
 * request by CheckoutCalculator and stored nowhere — the basket keeps the
 * INPUT (CheckoutData), never the result.
 *
 * That is the point of the design: prices, tax rates, availability and the
 * shop's configuration can all change between two page views, and an order
 * must reflect what is true when the button is pressed, not what was true
 * when the address was typed. A stored total would be a promise nobody
 * checked again.
 *
 * Everything a template needs is decided here. A template that picks between
 * two amounts shows the wrong one somewhere.
 */
final class CheckoutState
{
    /**
     * @param list<MethodOption>   $shippingOptions in the shop's order; the first available one is the default (C2)
     * @param list<MethodOption>   $paymentOptions  likewise
     * @param list<ShippingShare>  $shippingShares  the shipping charge per tax class (C4), empty without shipping
     * @param list<ShippingShare>  $paymentShares   the payment method's amount per tax class, signed (C4, C5)
     * @param list<CheckoutProblem> $problems       in the order they were found, following the six stages of 7.2
     */
    public function __construct(
        public readonly CartView $cart,
        public readonly CheckoutData $data,
        public readonly bool $needsShipping,
        public readonly array $shippingOptions,
        public readonly array $paymentOptions,
        public readonly MethodOption|null $shipping,
        public readonly MethodOption|null $payment,
        public readonly array $shippingShares,
        public readonly array $paymentShares,
        public readonly PaymentAdjustmentOrigin|null $paymentAdjustment,
        public readonly string $netTotal,
        public readonly string $grossTotal,
        public readonly string $currency,
        public readonly bool $reverseCharge,
        public readonly int $minimumAge,
        public readonly array $problems,
        /** The chosen shipping method's carrier needs the recipient's phone (E-6, V6). */
        public readonly bool $needsPhone = false,
        /**
         * Only digital lines: the delivery step offers the electronic delivery
         * alone (F-2, F14). `needsShipping` stays true — there is a delivery,
         * just not a parcel.
         */
        public readonly bool $electronicDelivery = false,
        /**
         * At least one digital line (F-3): the consent to an early start is
         * asked for, of consumers (§ 18 Abs. 1 Z 11 FAGG, § 356 Abs. 6 BGB).
         */
        public readonly bool $hasDigitalLines = false,
        /**
         * The withdrawal kinds of the digital lines, sorted and unique —
         * "content", "service" or both (Abo-2, P14, A12). The consent box
         * words itself after them.
         *
         * @var list<string>
         */
        public readonly array $withdrawalKinds = [],
        /*
         * Block H (H-4): the cart rule that applies and its shares —
         * NEGATIVE, one per tax class, like the payment discount's — and
         * what became of the typed code, for the review page.
         */
        public readonly CartDiscount|null $discount = null,
        /** @var list<ShippingShare> */
        public readonly array $discountShares = [],
        public readonly CodeStatus $codeStatus = CodeStatus::None,
        // What the order still lacks for the code's minimum (review H, S2), as a number; '' otherwise.
        public readonly string $codeMissing = '',
        /*
         * Block Wertgutscheine (WG-5): the basket's vouchers in the order
         * they pay in, each with what it takes off this order; what is left
         * for the payment method to collect; and whether that is nothing at
         * all, so the method is "by voucher" (E1).
         */
        /** @var list<AppliedVoucher> */
        public readonly array $vouchers = [],
        public readonly string $payable = '',
        public readonly bool $coveredByVouchers = false,
        /*
         * The switch "Steuer im Bestimmungsland" this state was priced with
         * (block R, O1). The order is written with the same one, never a rate
         * nobody was quoted. A switch turned between the review and the click
         * changes the amounts, so the click's CheckoutFingerprint no longer
         * matches the review page's (review R, F12).
         */
        public readonly bool $taxAtDestination = false,
    ) {
    }

    /**
     * What the payment method collects: the total less the vouchers. A
     * state built without vouchers owes its total.
     */
    public function toPay(): string
    {
        return '' === $this->payable ? $this->grossTotal : $this->payable;
    }

    /**
     * The vouchers that pay something of this order.
     *
     * @return list<AppliedVoucher>
     */
    public function payingVouchers(): array
    {
        return array_values(array_filter($this->vouchers, static fn (AppliedVoucher $voucher): bool => BigDecimal::of($voucher->applied)->isPositive()));
    }

    /**
     * Whether the order may be placed (11.2). Notices never block — only a
     * problem that says so does.
     */
    public function isReady(): bool
    {
        foreach ($this->problems as $problem) {
            if ($problem->blocks()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The block the visitor has to go back to, or null when nothing is in the
     * way. The FIRST blocking problem decides: the stages of 7.2 run in
     * dependency order, so an address that is missing makes every later
     * complaint about shipping meaningless.
     */
    public function blockedAt(): CheckoutBlock|null
    {
        foreach ($this->problems as $problem) {
            if ($problem->blocks()) {
                return $problem->step();
            }
        }

        return null;
    }

    public function has(CheckoutProblem $problem): bool
    {
        return \in_array($problem, $this->problems, true);
    }

    /**
     * The same state with the buyer's input replaced (C-7).
     *
     * Used at exactly one point: the purchase click writes the moment the
     * terms were ticked and has to hand that moment on to the order, without
     * recalculating a state whose checksum has just been verified.
     *
     * ⚠️ Safe only because nothing recalculated here depends on it. That is
     * not an accident either — `termsAcceptedAt` is deliberately NOT part of
     * the fingerprint (C-6), because it is written by the very form the
     * fingerprint travels on.
     */
    public function withData(CheckoutData $data): self
    {
        return new self(
            $this->cart,
            $data,
            $this->needsShipping,
            $this->shippingOptions,
            $this->paymentOptions,
            $this->shipping,
            $this->payment,
            $this->shippingShares,
            $this->paymentShares,
            $this->paymentAdjustment,
            $this->netTotal,
            $this->grossTotal,
            $this->currency,
            $this->reverseCharge,
            $this->minimumAge,
            $this->problems,
            $this->needsPhone,
            $this->electronicDelivery,
            $this->hasDigitalLines,
            $this->withdrawalKinds,
            $this->discount,
            $this->discountShares,
            $this->codeStatus,
            $this->codeMissing,
            $this->vouchers,
            $this->payable,
            $this->coveredByVouchers,
            // Block R: missing here, the click wrote the order without the
            // switch and the total check refused it (acceptance R-5).
            $this->taxAtDestination,
        );
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutCalculator;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutState;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustmentOrigin;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodRepositoryInterface;

/**
 * Turns a calculated checkout into a placed order (7.3).
 *
 * A translation, not a decision: everything here was worked out by
 * CheckoutCalculator moments ago, and this reads it off. Deciding anything a
 * second time would mean two answers to the same question — and the checksum
 * the click carries is only worth something because the calculation is
 * repeatable and side-effect free (the assurance C-5 made and C-6 cashed in).
 *
 * It sits between the controller and OrderPlacer so that the controller does
 * not grow a fourth set of dependencies for what is one step of one branch,
 * and so that this translation can be tested without a request.
 */
final class PurchaseHandler implements PurchaseHandlerInterface
{
    public function __construct(
        private readonly OrderPlacerInterface $placer,
        private readonly PaymentMethodRepositoryInterface $methods,
    ) {
    }

    /**
     * @param int    $rootId   the start point — the legal texts hang off it
     * @param string $language the language of the page the order was placed on
     *
     * @param list<int> $customerGroups
     *
     * @throws PaymentMethodGoneAtPurchase
     * @throws ProductGoneFromCatalogue
     * @throws BasketGoneAtPurchase
     */
    public function place(
        int $cartId,
        int $rootId,
        int $shopId,
        string $shopCountry,
        string $language,
        string $fallbackLanguage,
        int $memberId,
        array $customerGroups,
        CheckoutState $state,
        int $now,
        string $shopVatId = '',
        bool $businessCustomer = false,
    ): PlacedOrder {
        if (null === $state->payment) {
            throw PaymentMethodGoneAtPurchase::withId($state->data->paymentMethod);
        }

        /*
         * "By voucher" (E1) is no record: a method in code, without handler
         * or config, like the electronic delivery. Only where the state says
         * the vouchers cover it — a hand-made form naming method 0 gets the
         * same answer as any method that is gone.
         */
        $method = CheckoutCalculator::PAYMENT_BY_VOUCHER === $state->payment->id && $state->coveredByVouchers
            ? new PaymentMethod(CheckoutCalculator::PAYMENT_BY_VOUCHER, 'voucher', $state->payment->title, [])
            : $this->methodOf($shopId, $state->payment->id, $language, $fallbackLanguage);

        $draft = new OrderDraft(
            shopId: $shopId,
            currency: $state->currency,
            billing: $state->data->billing,
            shipping: $state->data->deliveryAddress(),
            shopCountry: $shopCountry,
            shopVatId: $shopVatId,
            memberId: $memberId,
            customerGroupIds: $customerGroups,
            vatCheck: $state->data->vatCheck,
            customerVatId: $state->data->vatId,
            language: $language,
            fallbackLanguage: $fallbackLanguage,
            shippingMethod: $state->shipping->id ?? 0,
            shippingMethodTitle: $state->shipping->title ?? '',
            paymentMethod: $state->payment->id,
            paymentMethodTitle: $state->payment->title,

            // Frozen like the title (C-9): the notice is frozen on the
            // order at the purchase. The confirmation
            // of an order without an invoice at the purchase has nothing else
            // to read it from — and today's method may say something else.
            paymentInstructions: (string) ($method->config['instructions'] ?? ''),
            minimumAge: $state->minimumAge,
            // Only where it was asked: a prefilled date nobody was asked for
            // is not the order's business.
            dateOfBirth: $state->needsDateOfBirth ? $state->data->dateOfBirth : '',

            // 0 means "this shop does not ask", and the column is NULL-able so
            // that it does not read as 01.01.1970.
            termsAcceptedAt: 0 !== $state->data->termsAcceptedAt ? $state->data->termsAcceptedAt : null,

            // The figure above the button. OrderFactory refuses to write an
            // order that does not come to it (C-7), and it is the amount a payment will later be for —
            // so a disagreement cannot reach either.
            expectedGrandTotal: $state->grossTotal,

            // Kept only where the carrier needs it (E-6, V6).
            shippingPhone: $state->needsPhone ? trim($state->data->shippingPhone) : '',

            // Only where it was asked: digital lines and a consumer (F-3).
            digitalConsentAt: $state->hasDigitalLines && !$businessCustomer && 0 !== $state->data->digitalConsentAt ? $state->data->digitalConsentAt : null,
            businessCustomer: $businessCustomer,
            discountRule: $state->discount->ruleId ?? 0,
            discountCode: $state->discount->code ?? '',
            // WG-5: what the review page showed each voucher paying (E4).
            vouchers: array_map(static fn ($voucher): array => ['id' => $voucher->id, 'code' => $voucher->code, 'amount' => $voucher->applied], $state->payingVouchers()),
            // Block R (O1): as the review page was priced.
            taxAtDestination: $state->taxAtDestination,
        );

        return $this->placer->place($cartId, $rootId, $draft, $this->linesOf($state), $method, $now);
    }

    /**
     * The method as the order's language sees it.
     *
     * Read again rather than taken from the state, because the handler needs
     * its `config` — and `instructions` in there is the payment notice IN THE
     * LANGUAGE OF THE ORDER (PaymentHandlerInterface). A notice frozen in the
     * shop's fallback would reach a German customer in English.
     *
     * @throws PaymentMethodGoneAtPurchase
     */
    private function methodOf(int $shopId, int $methodId, string $language, string $fallback): PaymentMethod
    {
        foreach ($this->methods->forShop($shopId, $language, $fallback) as $method) {
            if ($method->id === $methodId) {
                return $method;
            }
        }

        throw PaymentMethodGoneAtPurchase::withId($methodId);
    }

    /**
     * @return list<OrderLineDraft>
     */
    private function linesOf(CheckoutState $state): array
    {
        $lines = [];

        // H-5: each line's part of the cart rule's discount, by its position in the basket.
        foreach ($state->cart->lines as $position => $line) {
            $lines[] = OrderLineDraft::product($line->articleId, $line->quantity, $line->subscriptionRule, $line->renews, $state->discount->lines[$position] ?? '0');
        }

        /*
         * One line per tax class, which is what the shares already are:
         * proportional shipping tax splits a single charge across the rates in
         * the basket (ShippingTaxSplitter, C-5), and an invoice has to show
         * each part with the rate it carries.
         *
         * The title is the same for all of them. A customer who sees "Paket"
         * twice on an invoice is reading two tax rates, not two parcels, and
         * that is what the rate column beside it says.
         */
        foreach ($state->shippingShares as $share) {
            $lines[] = OrderLineDraft::charge(
                OrderLineType::Shipping,
                $state->shipping->title ?? '',
                $share->net,
                $share->taxClassId,
                $state->shipping->id ?? 0,
            );
        }

        /*
         * The payment amount, already signed and split into tax shares by the
         * calculator — for a fee the way the operator chose at the method
         * (E-1, Z3).
         */
        $type = PaymentAdjustmentOrigin::Fee === $state->paymentAdjustment
            ? OrderLineType::PaymentFee
            : OrderLineType::PaymentDiscount;

        foreach ($state->paymentShares as $share) {
            $lines[] = OrderLineDraft::charge(
                $type,
                $state->payment->title ?? '',
                $share->net,
                $share->taxClassId,
                $state->payment->id ?? 0,
                $share->taxCountry,
            );
        }

        /*
         * Block H (H-4): the cart rule's discount, one line per rate,
         * negative — the shares the review page showed. Named after the
         * rule, with the code where one was typed: that is what the
         * customer recognises on the invoice. `source` is the rule.
         */
        if (null !== $state->discount) {
            $title = '' === $state->discount->code ? $state->discount->title : \sprintf('%s (%s)', $state->discount->title, $state->discount->code);

            foreach ($state->discountShares as $share) {
                $lines[] = OrderLineDraft::charge(OrderLineType::Discount, $title, $share->net, $share->taxClassId, $state->discount->ruleId, $share->taxCountry);
            }
        }

        return $lines;
    }
}

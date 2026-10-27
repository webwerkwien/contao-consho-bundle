<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Symfony\Component\Intl\Currencies;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Cart\CartItemRow;
use Webwerkwien\ContaoConshoBundle\Cart\CartLine;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;
use Webwerkwien\ContaoConshoBundle\Cart\CartView;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CodeStatus;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustmentOrigin;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentContext;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingContext;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingLine;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethod;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypes;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Tax\TaxPlace;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateNotFound;
use Webwerkwien\ContaoConshoBundle\Voucher\AppliedVoucher;
use Webwerkwien\ContaoConshoBundle\Voucher\CoverablePots;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherWallet;

/**
 * Builds the checkout state, in the fixed order of spec 7.2, WITHOUT side
 * effects: nothing here writes, sends, or remembers.
 *
 * ── Why no side effects ───────────────────────────────────────────────────
 *
 * The basket page, every checkout step and the buy click all run this. It has
 * to be safe to run again at any moment, and it has to give the same answer
 * for the same inputs — that is what makes it possible to compare the state
 * behind the "buy" form against a freshly calculated one (7.3). A calculation
 * that saved something on the way could not be repeated for comparison.
 *
 * The one thing that DOES have a side effect, the VIES call, is therefore not
 * here: the address block checks a VAT ID when one is entered, stores the
 * verdict in CheckoutData, and this reads the stored verdict. Otherwise every
 * page view would cost a request to the EU and the checkout would be as slow
 * and as fragile as the slowest member state.
 *
 * ── The six stages ────────────────────────────────────────────────────────
 *
 *   1 positions with price   2 country and VAT ID   3 shipping methods
 *   4 payment methods        5 totals               6 readiness
 *
 * They run in this order because each one needs the one before: availability
 * depends on the country, the payment adjustment depends on the net total,
 * and the net total depends on the shipping charge. Problems are collected in
 * the same order, so the FIRST one is the one worth showing.
 */
final class CheckoutCalculator
{
    /**
     * The id of the electronic delivery (F-2): 0, which is also what an order
     * without a shipping method stores — so the order needs no new column and
     * no record to point at, and the frozen title says what it was.
     */
    public const ELECTRONIC_DELIVERY = 0;

    /**
     * The payment "by voucher" (block Wertgutscheine, E1): offered alone when
     * the vouchers cover the whole order — no method has anything to collect.
     * 0 for the same reasons as the electronic delivery: no column, no record
     * an operator could delete, and the frozen title says what it was.
     */
    public const PAYMENT_BY_VOUCHER = 0;

    public function __construct(
        private readonly CartPricer $pricer,
        private readonly ShippingMethodRepositoryInterface $shippingMethods,
        private readonly ShippingMethodTypes $shippingTypes,
        private readonly PaymentMethodRepositoryInterface $paymentMethods,
        private readonly PaymentHandlers $paymentHandlers,
        private readonly ShippingTaxSplitter $splitter,
        private readonly GrossCalculator $gross,
        private readonly AgeGate $ages,
        /*
         * Weight and measurements for the shipping types (E-4, V1). Optional
         * for the tests that build a calculator by hand; the container always
         * wires the database reader. Without it every line weighs nothing.
         */
        private readonly ArticleShippingDataInterface|null $articles = null,
        // The title of the electronic delivery in the order's language (F-2).
        private readonly TranslatorInterface|null $translator = null,
        // Block G (G5): whether the stock covers the basket. Optional like
        // the two above; the purchase click checks again regardless
        // (StockAllocation, required in OrderPlacer).
        private readonly Availability|null $availability = null,
        // Block H (H-4): cart rules and codes. Optional like the ones above.
        private readonly CartDiscounts|null $discounts = null,
        // Block Wertgutscheine (WG-5): what the typed vouchers pay. Optional
        // like the ones above; the purchase click books under a lock.
        private readonly VoucherWallet|null $vouchers = null,
        // EZ-1: what single-purpose vouchers may pay. Without it they pay nothing.
        private readonly CoverablePots|null $pots = null,
    ) {
    }

    /**
     * @param list<CartItemRow> $items
     * @param list<string>      $deliveryCountries upper-case ISO codes the shop delivers to; empty means no restriction
     * @param list<int>         $customerGroups    the visitor's groups, empty for a guest
     * @param list<int>         $ageVerifiedGroups the shop's `ageVerifiedGroups` (C19)
     * @param int               $minimumAge        highest age restriction in the basket (C19), 0 for none
     * @param string            $language          the site's language, for the method titles
     * @param string            $fallback          the shop's main language
     * @param list<string>      $voucherCodes      normalised, in the order they were typed
     */
    public function calculate(
        int $shopId,
        array $items,
        string $currency,
        string $shopCountry,
        array $deliveryCountries,
        array $customerGroups,
        CheckoutData $data,
        int $now,
        int $minimumAge = 0,
        array $ageVerifiedGroups = [],
        string $language = '',
        string $fallback = '',
        string $shopVatId = '',
        bool $netAudience = false,
        // Block H (H-4): the code typed in the basket, and who buys (W5).
        string $discountCode = '',
        int $memberId = 0,
        // Block Wertgutscheine (E3): the voucher codes typed in the basket,
        // in the order they pay in.
        array $voucherCodes = [],
        // Block R (O1): the shop's switch "Steuer im Bestimmungsland".
        bool $taxAtDestination = false,
    ): CheckoutState {
        $problems = [];

        /*
         * The currency's own places, needed as early as stage 3: the shipping
         * split has to land on them, or the invoice lines do not add up to the
         * amount the visitor was quoted (4,95 split 50/50 became 2,4750 + 2,4750 → 2,48 + 2,48 → 4,96).
         */
        $digits = Currencies::getFractionDigits($currency);

        // ── 1 ── positions with price, customer group and all (C20) ────────
        $country = $this->countryFor($data, $shopCountry);
        $cart = $this->pricer->price($items, $currency, $country, $shopCountry, $now, $customerGroups, $taxAtDestination);

        /*
         * Where shipping, a payment fee and a reduction without a country of
         * its own are taxed (block R, R2): with the goods they come with. The
         * delivery country stays what decides which countries are served and
         * which methods carry there — only the RATE follows the switch.
         */
        $taxCountry = TaxPlace::forDistanceSale($country, $shopCountry, $taxAtDestination);

        if ([] === $cart->lines) {
            $problems[] = CheckoutProblem::BasketEmpty;
        }

        if ([] !== $cart->unpriced) {
            // CartView's own note says it: the basket page offers to remove
            // them, the checkout refuses to go on while one is there.
            $problems[] = CheckoutProblem::UnpricedLine;
        }

        // The basket holds nothing back (G5): asked here so the buy button is
        // not offered for what is gone, decided again at the click.
        // "Lizenz verlängern" lines take no code (Abo-7b), so they ask nothing.
        if (null !== $this->availability && [] !== $this->availability->short(array_map(static fn ($line): array => [$line->articleId, $line->quantity], array_values(array_filter($cart->lines, static fn ($line): bool => 0 === $line->renews))))) {
            $problems[] = CheckoutProblem::StockShort;
        }

        // ── 2 ── delivery country and VAT ID ───────────────────────────────
        if (!$this->addressIsComplete($data->billing, true)
            || ($data->separateShipping && !$this->addressIsComplete($data->shipping, false))
        ) {
            $problems[] = CheckoutProblem::AddressIncomplete;
        }

        if ('' !== $country && [] !== $deliveryCountries && !\in_array($country, $deliveryCountries, true)) {
            $problems[] = CheckoutProblem::DeliveryCountryNotServed;
        }

        if ('' !== $data->vatId && VatIdStatus::Invalid === $data->vatCheck?->status) {
            $problems[] = CheckoutProblem::VatIdInvalid;
        }

        if ('' !== $data->vatId && VatIdStatus::Unavailable === $data->vatCheck?->status) {
            // Does not block (C15) — order with tax and offer "check again".
            $problems[] = CheckoutProblem::VatServiceUnavailable;
        }

        $reverseCharge = $this->appliesReverseCharge($data, $shopCountry, $country, $shopVatId);

        // The goods as well, not only shipping and payment amounts below —
        // see CartView::withoutTax() for what went wrong without it.
        if ($reverseCharge) {
            $cart = $cart->withoutTax();
        }

        // ── 3 ── shipping methods ──────────────────────────────────────────
        /*
         * Only digital lines: the ELECTRONIC DELIVERY (F-2, F14): no shipping does
         * not mean no shipping step. One option, in code, free, not a
         * record the operator could delete or forget. The step keeps its
         * place, so the step labels need no layout without shipping, and the
         * age gate below runs as for any method that does not check at the
         * door: only a verified group gets through (AgeGate, C19).
         *
         * Chosen whatever the stored choice says: a method picked while a
         * parcel was in the basket is not "gone" (C2) — it simply does not
         * apply to a basket that is not shipped.
         */
        $electronic = [] !== $cart->lines && !$this->basketNeedsShipping($cart);
        $needsShipping = [] !== $cart->lines;
        $shippingOptions = [];
        $shipping = null;
        $shares = [];
        $capabilities = null;

        if ($electronic) {
            $shipping = new MethodOption(self::ELECTRONIC_DELIVERY, $this->electronicTitle($language), true, '0.0000', '0.0000');
            $shippingOptions = [$shipping];
            $capabilities = new ShippingCapabilities(0, false);

            $age = $this->ages->check($minimumAge, $customerGroups, $ageVerifiedGroups, $capabilities, $data->dateOfBirth, $now);

            if (null !== $age) {
                $problems[] = $age;
            }
        } elseif ($needsShipping) {
            $context = new ShippingContext(
                $this->shippingLinesOf($cart),
                $country,
                $shopId,
                $currency,
                $customerGroups,
                $minimumAge,
            );

            // Read once, in the shop's order (C2: the topmost is the default).
            $methods = [];

            foreach ($this->shippingMethods->forShop($shopId, $currency, $language, $fallback) as $method) {
                $option = $this->shippingOption($method, $context, $country, $taxCountry, $now, $reverseCharge);

                if (null !== $option) {
                    $shippingOptions[] = $option;
                    $methods[$method->id] = $method;
                }
            }

            $shipping = $this->chosen($shippingOptions, $data->shippingMethod);

            if ([] === array_filter($shippingOptions, static fn (MethodOption $o): bool => $o->available)) {
                $problems[] = CheckoutProblem::NoShippingMethod;
            } elseif (0 !== $data->shippingMethod && null === $shipping) {
                // Chosen, then it went away: never substituted silently (C2).
                $problems[] = CheckoutProblem::ShippingMethodGone;
            }

            if (null !== $shipping && isset($methods[$shipping->id])) {
                $method = $methods[$shipping->id];
                $shares = $this->grossed(
                    $this->splitter->split($shipping->net, $method->taxMode, $method->taxClassId, $cart->lines, $digits),
                    $taxCountry,
                    $now,
                    $reverseCharge,
                );

                /*
                 * ⚠️ The option's gross comes from the SHARES, not from the shipping
                 * method's tax class. With `proportional` the class only converts a gross
                 * input (5.1) — taken as the rate, it would give a different figure than
                 * the sum of the invoice lines: 4,95 net split 50/50 into 20 % and 10 % gave
                 * 5,94 at the option and 5,6925 in the lines.
                 */
                $corrected = new MethodOption(
                    $shipping->id,
                    $shipping->title,
                    true,
                    $shipping->net,
                    (string) $this->sumOf($shares),
                );

                /*
                 * Replaced in the LIST too, not only in the choice: otherwise the same
                 * entry would carry two different gross amounts, depending on where a
                 * template reads it.
                 */
                foreach ($shippingOptions as $index => $option) {
                    if ($option->id === $corrected->id) {
                        $shippingOptions[$index] = $corrected;
                    }
                }

                $shipping = $corrected;
            }

            /*
             * The age gate sits in the shipping stage because one of its two
             * answers IS the shipping method (C19). Asked even without a
             * chosen method, so that a basket nobody can be sold says so
             * before the visitor picks one — and with no method chosen the
             * only way through is a verified group.
             */
            $capabilities = null === $shipping || !isset($methods[$shipping->id])
                ? null
                : $this->shippingTypes->get($methods[$shipping->id]->type)->capabilities($methods[$shipping->id]->config);

            $age = $this->ages->check(
                $minimumAge,
                $customerGroups,
                $ageVerifiedGroups,
                $capabilities,
                $data->dateOfBirth,
                $now,
            );

            if (null !== $age) {
                $problems[] = $age;
            }

            // The carrier needs the recipient's phone (E-6, V6).
            if (true === $capabilities?->needsPhone && '' === trim($data->shippingPhone)) {
                $problems[] = CheckoutProblem::PhoneMissing;
            }
        }

        // ── 3b ── cart rule (block H, H-4) ─────────────────────────────────
        /*
         * After the shipping, because free shipping is worth what it saves
         * (C3: the cheapest for the customer); before the payment, because a
         * payment handler judges its limit on what is left to pay.
         *
         * W4: free shipping makes the chosen method cost 0 — every option,
         * as any one chosen would. W2: amounts as the customer reads them.
         * The shares come net and are grossed here like every other share,
         * at the delivery country's rates, so the review page and the order
         * lines (OrderFactory) cannot disagree.
         */
        $discount = null;
        $discountShares = [];
        $codeStatus = CodeStatus::None;
        $codeMissing = '';

        if (null !== $this->discounts && [] !== $cart->lines) {
            $shownNet = $netAudience || $reverseCharge;
            $shippingShown = null === $shipping ? '0' : ($shownNet ? $shipping->net : $shipping->gross);
            $result = $this->discounts->evaluate($cart, $shopId, $discountCode, $customerGroups, $shownNet, $memberId, $data->billing->email, $now, $shippingShown);
            $discount = $result->discount;
            $codeStatus = $result->codeStatus;
            $codeMissing = $result->minimumOrder;

            if (null !== $discount && $discount->freeShipping) {
                /*
                 * Every option at 0, also where the chosen one costs nothing
                 * anyway (H): another one picked would be
                 * free as well, and the list has to say so.
                 *
                 * But free shipping where there is none to save — an
                 * electronic delivery, a method that costs nothing — takes
                 * nothing off and must not use the code up (H;
                 * OrderPlacer redeems what the state carries).
                 */
                $shippingOptions = array_map(static fn (MethodOption $o): MethodOption => new MethodOption($o->id, $o->title, $o->available, '0.0000', '0.0000'), $shippingOptions);
                $shipping = null === $shipping ? null : new MethodOption($shipping->id, $shipping->title, $shipping->available, '0.0000', '0.0000');
                $shares = [];

                if (!BigDecimal::of($shippingShown)->isPositive()) {
                    $codeStatus = '' !== $discount->code ? CodeStatus::NothingToSave : $codeStatus;
                    $discount = null;
                }
            } elseif (null !== $discount) {
                $discountShares = $this->negated($this->grossed(
                    array_map(static fn (ShippingShare $share): ShippingShare => new ShippingShare($share->taxClassId, $share->net, '0.0000', $share->taxCountry), $discount->shares),
                    $taxCountry,
                    $now,
                    $reverseCharge,
                ));
            }
        }

        // ── 3c ── vouchers (block Wertgutscheine, WG-5) ───────────────────
        /*
         * E1: vouchers that cover the whole order before any payment method
         * leave nothing for one to collect — the method is "by voucher",
         * alone, and no fee or discount arises. Asked before the methods,
         * on the total without their amount: a fee only exists where a
         * method collects.
         *
         * Not with a subscription line: its method carries the follow-up
         * payments, so it is chosen even where the first one is covered.
         * And never over a voucher line (WG8): money is not bought with a
         * voucher, so such a basket always leaves something to pay.
         */
        $voucherLines = $this->voucherLinesGross($cart);
        // EZ-1, F2: the articles a single-purpose voucher may pay, after the discount.
        $pots = $this->pots?->of($cart->lines, $discountShares, $now, $reverseCharge, $taxCountry) ?? [];
        $coveredByVouchers = false;
        $vouchersPay = false;

        if (null !== $this->vouchers && [] !== $voucherCodes && [] !== $cart->lines) {
            $before = $this->grossBeforePayment($cart->gross, [...$shares, ...$discountShares], $digits);
            $coverable = $before->minus($voucherLines);
            $paid = $coverable->isPositive() ? $this->appliedSum($this->vouchers->apply($voucherCodes, $shopId, $currency, (string) $coverable, $digits, $now, $pots)) : BigDecimal::zero();

            // WG-7: no cash discount on vouchers.
            $vouchersPay = $paid->isPositive();
            $coveredByVouchers = $voucherLines->isZero() && !$this->hasSubscriptionLine($cart) && $before->isPositive() && $paid->isEqualTo($before);
        }

        // ── 4 ── payment methods ───────────────────────────────────────────
        // The goods plus shipping, which is what a payment handler judges a
        // limit against ("no cash on delivery above 200 euro"). The whole
        // order, also where vouchers pay part of it (E2).
        $runningNet = BigDecimal::of($cart->net);

        // Less the cart rule (H-4): the limit is judged on what is paid.
        foreach ($discountShares as $share) {
            $runningNet = $runningNet->plus($share->net);
        }

        if (null !== $shipping) {
            $runningNet = $runningNet->plus($shipping->net);
        }

        $paymentContext = new PaymentContext(
            $shopId,
            $currency,
            strtoupper(trim($data->billing->country)),
            $country,
            $customerGroups,
            (string) $runningNet->toScale(4, RoundingMode::HalfUp),
            // What the chosen shipping method does at the door — cash on
            // delivery is offered only where it collects (E-3, Z2).
            $capabilities,
            $this->handedOutAtOnce($cart),
        );

        $paymentOptions = [];
        $origins = [];
        $adjustmentShares = [];

        foreach ($coveredByVouchers ? [] : $this->paymentMethods->forShop($shopId, $language, $fallback) as $method) {
            if (!$this->paymentHandlers->has($method->type)) {
                // A module was uninstalled while its methods stayed behind.
                continue;
            }

            $handler = $this->paymentHandlers->get($method->type);

            if (!$handler->isAvailable($paymentContext, $method->config)) {
                $paymentOptions[] = new MethodOption($method->id, $method->title, false);

                continue;
            }

            $adjustment = $handler->adjustment($paymentContext, $method->config);

            if (null === $adjustment) {
                $paymentOptions[] = new MethodOption($method->id, $method->title, true);

                continue;
            }

            /*
             * A FEE is taxed the way the operator chose at the method (E-1,
             * Z3) — split over the goods like shipping, or at one fixed class.
             * Whether it is an ancillary supply or one of its own is not
             * settled, so Consho does not choose; without a choice the method
             * is not offered (spec E, section 3). Until E-1 the purchase
             * refused the order at the click (the former PaymentFeeTaxUndecided) — here
             * the visitor sees it before choosing, not after.
             */
            if (PaymentAdjustmentOrigin::Fee === $adjustment->origin && !$method->feeTaxDecided()) {
                $paymentOptions[] = new MethodOption($method->id, $method->title, false);

                continue;
            }

            /*
             * A discount needs goods to reduce (WG8). A basket of vouchers
             * only has none — a discount there would sell money below its
             * value. The method stays on offer, without it.
             */
            /*
             * And no discount where a voucher pays (WG-7): the discount would
             * lower a total the voucher then covers — a reward for a method
             * that collects little or nothing. A fee stays (E2).
             */
            if (PaymentAdjustmentOrigin::Discount === $adjustment->origin && ($vouchersPay || [] === array_filter($cart->lines, static fn (CartLine $line): bool => !$line->kind->isVoucher()))) {
                $paymentOptions[] = new MethodOption($method->id, $method->title, true);

                continue;
            }

            [$mode, $class] = PaymentAdjustmentOrigin::Fee === $adjustment->origin
                ? [$method->feeTaxMode ?? ShippingTaxMode::Proportional, $method->feeTaxClassId]
                : [ShippingTaxMode::Proportional, 0];

            /*
             * A DISCOUNT follows the goods, exactly like shipping does —
             * C4 says so in one line: "gilt ebenso für einen Betrag der
             * Zahlungsart". A reduction of the consideration reduces it per
             * rate, so it is split over the rates in the basket by the same
             * splitter. A 2 euro discount on a basket of books and wine is
             * 1 euro off each at its own rate.
             *
             * ⚠️ PaymentAdjustment is ALWAYS POSITIVE and its origin gives the
             * direction — the class refuses a negative amount. The sign is put
             * on here, once, and MethodOption carries a signed amount from
             * then on. Adding `$adjustment->net` straight in would turn every
             * discount into a surcharge, and the arithmetic would look
             * perfectly reasonable while doing it.
             *
             * Asked once and remembered: adjustment() is a module's code and
             * may cost whatever it costs.
             */
            $parts = $this->grossed(
                // A discount per class and country (H); a fee follows the delivery.
                $this->splitter->split($adjustment->net, $mode, $class, $cart->lines, $digits, PaymentAdjustmentOrigin::Discount === $adjustment->origin),
                $taxCountry,
                $now,
                $reverseCharge,
            );

            $sign = PaymentAdjustmentOrigin::Discount === $adjustment->origin ? -1 : 1;

            $paymentOptions[] = new MethodOption(
                $method->id,
                $method->title,
                true,
                (string) BigDecimal::of($adjustment->net)->multipliedBy($sign),
                (string) $this->sumOf($parts)->multipliedBy($sign),
            );
            $origins[$method->id] = $adjustment->origin;
            $adjustmentShares[$method->id] = $sign < 0 ? $this->negated($parts) : $parts;
        }

        $payment = $this->chosen($paymentOptions, $data->paymentMethod);

        /*
         * Chosen whatever the stored choice says, like the electronic
         * delivery: a method picked before the vouchers were typed is not
         * "gone" — there is simply nothing left for it to collect.
         */
        if ($coveredByVouchers) {
            $payment = new MethodOption(self::PAYMENT_BY_VOUCHER, $this->voucherPaymentTitle($language), true);
            $paymentOptions = [$payment];
        }

        if ([] === array_filter($paymentOptions, static fn (MethodOption $o): bool => $o->available)) {
            $problems[] = CheckoutProblem::NoPaymentMethod;
        } elseif (0 !== $data->paymentMethod && null === $payment) {
            $problems[] = CheckoutProblem::PaymentMethodGone;
        }

        $adjustmentOrigin = null === $payment ? null : ($origins[$payment->id] ?? null);
        $paymentShares = null === $payment ? [] : ($adjustmentShares[$payment->id] ?? []);

        // ── 5 ── totals ────────────────────────────────────────────────────
        $net = BigDecimal::of($cart->net);
        $gross = BigDecimal::of($cart->gross);

        foreach ($shares as $share) {
            $net = $net->plus(BigDecimal::of($share->net)->toScale($digits, RoundingMode::HalfUp));
            $gross = $gross->plus(BigDecimal::of($share->gross)->toScale($digits, RoundingMode::HalfUp));
        }

        foreach ([...$paymentShares, ...$discountShares] as $share) {
            $net = $net->plus(BigDecimal::of($share->net)->toScale($digits, RoundingMode::HalfUp));
            $gross = $gross->plus(BigDecimal::of($share->gross)->toScale($digits, RoundingMode::HalfUp));
        }

        /*
         * What the vouchers pay of the total, fee included (E2), in the order
         * they were typed — the voucher lines excepted (WG8). The rest is
         * what the method collects.
         */
        $grossTotal = $gross->toScale($digits, RoundingMode::HalfUp);
        $vouchers = [];
        $payable = $grossTotal;

        if (null !== $this->vouchers && [] !== $voucherCodes && [] !== $cart->lines) {
            $coverable = $grossTotal->minus($voucherLines);
            $vouchers = $this->vouchers->apply($voucherCodes, $shopId, $currency, (string) ($coverable->isPositive() ? $coverable : BigDecimal::zero()), $digits, $now, $pots);
            $payable = $grossTotal->minus($this->appliedSum($vouchers));
        }

        // ── 6 ── readiness is CheckoutState::isReady(), over these problems ─
        return new CheckoutState(
            $cart,
            $data,
            $needsShipping,
            $shippingOptions,
            $paymentOptions,
            $shipping,
            $payment,
            $shares,
            $paymentShares,
            $adjustmentOrigin,
            (string) $net->toScale($digits, RoundingMode::HalfUp),
            (string) $grossTotal,
            $currency,
            $reverseCharge,
            $minimumAge,
            $problems,
            true === $capabilities?->needsPhone,
            $electronic,
            $this->hasDigitalLines($cart),
            $this->withdrawalKindsOf($cart),
            $discount,
            $discountShares,
            $codeStatus,
            $codeMissing,
            $vouchers,
            (string) $payable->toScale($digits),
            $coveredByVouchers,
            $taxAtDestination,
        );
    }

    /**
     * The voucher lines (WG8): what no voucher may pay.
     */
    private function voucherLinesGross(CartView $cart): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ($cart->lines as $line) {
            if ($line->kind->isVoucher()) {
                $sum = $sum->plus($line->lineGross);
            }
        }

        return $sum;
    }

    private function hasSubscriptionLine(CartView $cart): bool
    {
        return [] !== array_filter($cart->lines, static fn (CartLine $line): bool => $line->subscriptionRule > 0);
    }

    /**
     * The gross total of the goods and the given shares, each share on the
     * currency's places — the sum stage 5 forms, without the payment method.
     *
     * @param list<ShippingShare> $shares
     */
    private function grossBeforePayment(string $goods, array $shares, int $digits): BigDecimal
    {
        $gross = BigDecimal::of($goods);

        foreach ($shares as $share) {
            $gross = $gross->plus(BigDecimal::of($share->gross)->toScale($digits, RoundingMode::HalfUp));
        }

        return $gross->toScale($digits, RoundingMode::HalfUp);
    }

    /**
     * @param list<AppliedVoucher> $vouchers
     */
    private function appliedSum(array $vouchers): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ($vouchers as $voucher) {
            $sum = $sum->plus($voucher->applied);
        }

        return $sum;
    }

    /**
     * In the order's language, like the electronic delivery: PurchaseHandler
     * freezes it as the method's title.
     */
    private function voucherPaymentTitle(string $language): string
    {
        return $this->translator?->trans('CONSHO.cart.voucher.payment', [], 'contao_default', '' !== $language ? $language : null)
            ?? 'Gift voucher';
    }

    /**
     * The withdrawal kinds of the digital and service lines (Abo-2, P14,
     * DL1), sorted and unique — from the kind of the product type, the same
     * reading as hasDigitalLines(). Unknown articles are physical and have
     * none.
     *
     * @return list<string>
     */
    private function withdrawalKindsOf(CartView $cart): array
    {
        if ([] === $cart->lines || null === $this->articles) {
            return [];
        }

        $data = $this->articles->of(array_map(static fn (CartLine $line): int => $line->articleId, $cart->lines));
        $kinds = [];

        foreach ($cart->lines as $line) {
            $kind = ProductKind::stored($data[$line->articleId]['kind'] ?? null)->withdrawalKind();

            // A voucher has none (WG3): nothing is performed early.
            if ('' !== $kind) {
                $kinds[$kind] = true;
            }
        }

        $kinds = array_keys($kinds);
        sort($kinds);

        return $kinds;
    }

    /**
     * Whether any line is digital content or a voucher (Nachlese E5b) — usable
     * the moment it is released. Services are not: they are performed, and a
     * service on account is the ordinary case. Unknown articles are physical.
     */
    private function handedOutAtOnce(CartView $cart): bool
    {
        if ([] === $cart->lines || null === $this->articles) {
            return false;
        }

        $data = $this->articles->of(array_map(static fn (CartLine $line): int => $line->articleId, $cart->lines));

        foreach ($cart->lines as $line) {
            if (\in_array(ProductKind::stored($data[$line->articleId]['kind'] ?? null), [ProductKind::Digital, ProductKind::Voucher], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any line is digital (F-3). Unknown articles count as physical,
     * as in basketNeedsShipping() — a consent box for a parcel would ask the
     * buyer to give up a right that does not lapse.
     */
    private function hasDigitalLines(CartView $cart): bool
    {
        if ([] === $cart->lines || null === $this->articles) {
            return false;
        }

        $data = $this->articles->of(array_map(static fn (CartLine $line): int => $line->articleId, $cart->lines));

        foreach ($cart->lines as $line) {
            // Content and services, not vouchers (WG3): the consent to an
            // early start has nothing to consent to for a code.
            if ('' !== ProductKind::stored($data[$line->articleId]['kind'] ?? null)->withdrawalKind()) {
                return true;
            }
        }

        return false;
    }

    /**
     * In the order's language, because PurchaseHandler freezes it onto the
     * order like any method title. English where no translator is wired —
     * only the hand-built calculators of the tests.
     */
    private function electronicTitle(string $language): string
    {
        return $this->translator?->trans('CONSHO.checkout.electronicDelivery', [], 'contao_default', '' !== $language ? $language : null)
            ?? 'Electronic delivery';
    }

    /**
     * The delivery country, falling back to the shop's own. Without a country
     * there is no tax rate and therefore no basket at all — and an empty
     * basket page is the wrong answer to "I have not typed my address yet".
     */
    private function countryFor(CheckoutData $data, string $shopCountry): string
    {
        $country = strtoupper(trim($data->deliveryCountry()));

        return '' !== $country ? $country : strtoupper(trim($shopCountry));
    }

    /**
     * Same rule as OrderFactory::appliesReverseCharge(), asked one step
     * earlier: a valid VAT ID and a delivery country other than the shop's.
     * The order recalculates it rather than trusting this — what the checkout
     * shows and what the invoice says have to agree, and the way to make sure
     * is for both to derive it, not for one to copy the other.
     */
    private function appliesReverseCharge(CheckoutData $data, string $shopCountry, string $deliveryCountry, string $shopVatId): bool
    {
        if (!$data->hasVerifiedVatId()) {
            return false;
        }

        /*
         * And the shop's own VAT ID (C-12): reverse charge only with
         * the shop's own VAT ID. An invoice over a tax-free intra-community supply
         * has to state the supplier's VAT ID as well as the recipient's —
         * Art. 11 Abs. 2 UStG 1994 Anhang (Binnenmarkt), read at the RIS on
         * 2026-09-26. A shop without one charges the tax as for anyone else.
         * Without this an invoice could go out tax-free with neither the
         * shop's VAT ID nor a reverse charge note.
         */
        if ('' === trim($shopVatId)) {
            return false;
        }

        $home = strtoupper(trim($shopCountry));

        return '' !== $home && $home !== $deliveryCountry;
    }

    /**
     * What every order needs to be deliverable and invoiceable. Company,
     * salutation, state and phone are not in here: a private customer has no
     * company, plenty of countries have no states, and a phone number is the
     * carrier's convenience, not a requirement.
     *
     * ⚠️ The mail address is required on the BILLING address and on nothing
     * else. Two mistakes were in here before:
     *
     *   - "e-mail OR company" let a business order through with no mail
     *     address at all. OrderFactory writes $billing->email onto the order,
     *     and without it there is nowhere to send the confirmation (7.6) and
     *     no way to reach the order page (C21). A company name is not a way
     *     of being reached.
     *   - the same rule applied to a separate DELIVERY address, so sending a
     *     present to a private person failed for want of their mail address —
     *     which the shop has no business asking for and the recipient no
     *     reason to give.
     */
    private function addressIsComplete(Address $address, bool $needsEmail): bool
    {
        foreach (['lastname', 'street', 'postal', 'city', 'country'] as $field) {
            if ('' === trim((string) $address->{$field})) {
                return false;
            }
        }

        return !$needsEmail || '' !== trim($address->email);
    }

    /**
     * Whether anything in the basket has to be shipped (4.3).
     *
     * One physical line is enough (F-1, F13): a book with its e-book goes by
     * parcel, and the e-book is released on its own way. An article the data
     * source does not know — or no data source at all — counts as physical,
     * the same default as a missing product type: skipping the shipping step
     * for a parcel is the costly mistake, not the other one.
     */
    private function basketNeedsShipping(CartView $cart): bool
    {
        if ([] === $cart->lines) {
            return false;
        }

        if (null === $this->articles) {
            return true;
        }

        $data = $this->articles->of(array_map(static fn (CartLine $line): int => $line->articleId, $cart->lines));

        foreach ($cart->lines as $line) {
            if (ProductKind::stored($data[$line->articleId]['kind'] ?? null)->isPhysical()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ShippingLine>
     */
    private function shippingLinesOf(CartView $cart): array
    {
        $data = null === $this->articles ? [] : $this->articles->of(array_map(static fn (CartLine $line): int => $line->articleId, $cart->lines));

        return array_map(
            static fn (CartLine $line): ShippingLine => new ShippingLine(
                $line->articleId,
                $line->quantity,
                $line->lineNet,
                $line->taxClassId,
                $data[$line->articleId]['weight'] ?? '0.0000',
                $data[$line->articleId]['length'] ?? '0.0000',
                $data[$line->articleId]['width'] ?? '0.0000',
                $data[$line->articleId]['height'] ?? '0.0000',
            ),
            $cart->lines,
        );
    }

    private function shippingOption(ShippingMethod $method, ShippingContext $context, string $country, string $taxCountry, int $now, bool $reverseCharge): MethodOption|null
    {
        if (!$this->shippingTypes->has($method->type)) {
            // A module was uninstalled while its methods stayed behind. Not
            // an error for the visitor — just one fewer way to send a parcel.
            return null;
        }

        $type = $this->shippingTypes->get($method->type);

        // The core's rules first (E-5): countries (V3) and bulky goods (V4).
        if (!$method->carries($country, $context->lines) || !$type->isAvailable($context, $method->config)) {
            return new MethodOption($method->id, $method->title, false);
        }

        $net = $type->calculate($context, $method->config)->net;

        return new MethodOption(
            $method->id,
            $method->title,
            true,
            $net,
            $this->grossOf($net, $method->taxClassId, $taxCountry, $now, $reverseCharge),
        );
    }

    /**
     * @param list<ShippingShare> $shares
     */
    private function sumOf(array $shares): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ($shares as $share) {
            $sum = $sum->plus($share->gross);
        }

        return $sum;
    }

    /**
     * @param list<ShippingShare> $shares
     *
     * @return list<ShippingShare>
     */
    private function negated(array $shares): array
    {
        return array_map(
            static fn (ShippingShare $share): ShippingShare => new ShippingShare(
                $share->taxClassId,
                (string) BigDecimal::of($share->net)->negated(),
                (string) BigDecimal::of($share->gross)->negated(),
                $share->taxCountry,
            ),
            $shares,
        );
    }

    /**
     * Puts a gross amount on each share. Separate from the splitting itself so
     * the arithmetic can be tested without tax rates — see the note on
     * ShippingShare::$gross.
     *
     * @param list<ShippingShare> $shares
     *
     * @return list<ShippingShare>
     */
    private function grossed(array $shares, string $country, int $now, bool $reverseCharge): array
    {
        return array_map(
            fn (ShippingShare $share): ShippingShare => $share->withGross(
                // A reduction at the country of what it reduces (H).
                $this->grossOf($share->net, $share->taxClassId, '' !== $share->taxCountry ? $share->taxCountry : $country, $now, $reverseCharge),
            ),
            $shares,
        );
    }

    /**
     * Reverse charge means no tax at all, so gross is net — the invoice then
     * carries the "reverse charge" note instead of a rate. Without a rate for
     * the country the amount stays as it is: the goods already refused to be
     * priced in that case, so nobody reaches the button.
     */
    private function grossOf(string $net, int $taxClassId, string $country, int $now, bool $reverseCharge): string
    {
        if ($reverseCharge || '' === $country) {
            return (string) BigDecimal::of($net)->toScale(4, RoundingMode::HalfUp);
        }

        try {
            return $this->gross->gross($net, $taxClassId, $country, $now);
        } catch (TaxRateNotFound) {
            return (string) BigDecimal::of($net)->toScale(4, RoundingMode::HalfUp);
        }
    }

    /**
     * The chosen option, or the first available one as the default (C2,
     * "the topmost is the default; if it is unavailable the next moves up").
     *
     * Returns null for a choice that is no longer available, so the caller can
     * tell "nothing chosen yet" from "what you chose is gone" — one of them
     * needs a sentence, the other does not.
     *
     * @param list<MethodOption> $options
     */
    private function chosen(array $options, int $chosenId): MethodOption|null
    {
        foreach ($options as $option) {
            if ($option->id === $chosenId) {
                return $option->available ? $option : null;
            }
        }

        if (0 !== $chosenId) {
            return null;
        }

        foreach ($options as $option) {
            if ($option->available) {
                return $option;
            }
        }

        return null;
    }

}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceNotFound;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLineTerms;
use Webwerkwien\ContaoConshoBundle\Tax\TaxPlace;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;

/**
 * Turns a draft into an order and freezes everything it will ever need.
 *
 * This is the only place an order comes into being. It resolves the price and
 * the tax rate once, writes them as values, and from then on the order is a
 * historical record rather than a query against the current catalogue.
 *
 * **Net is the anchor.** A price row stores net only; gross follows from the
 * rate of the delivery country, resolved here and frozen on the item. The gross
 * a visitor saw before checkout was calculated for the shop's home country
 * (C25) — a delivery elsewhere carries a different rate on purpose.
 *
 * **The price comes through the same inheritance the storefront uses** (C-7).
 * A variant without a price row of its own takes its main product's set, and
 * ArticlePriceResolver is where that rule lives. Asking PriceCalculator
 * directly, as this class did until C-7, meant such a variant could be shown,
 * added to a basket and priced through the whole checkout — and then failed at
 * the click with PriceNotFound.
 *
 * **The totals add up the lines as they are shown**, each amount rounded to the
 * currency's own place before it enters its sum, the same rule CartPricer
 * follows. The line fields keep four places; what is rounded is the amount as
 * it enters the sum, not the arithmetic behind it. Which of the three columns
 * is allowed not to reconcile, and how C-8 made the invoice's tax summary agree
 * with it, is set out at the summation itself.
 */
final class OrderFactory implements OrderFactoryInterface
{
    private const HUNDRED = '100';

    public function __construct(
        private readonly NumberRangeAllocator $numbers,
        private readonly CartLinePriceSource $prices,
        private readonly TaxResolver $taxes,
        private readonly OrderProductRepositoryInterface $products,
        private readonly OrderWriterInterface $writer,
        // Abo-2 (P13): a subscription line's conditions, frozen at the
        // click. Optional for the tests built by hand; the container wires it.
        private readonly SubscriptionLineTerms|null $terms = null,
    ) {
    }

    /**
     * @param list<OrderLineDraft> $lines
     *
     * @throws ProductGoneFromCatalogue
     * @throws PriceNotFound
     */
    public function create(OrderDraft $draft, array $lines, int $timestamp): int
    {
        if ([] === $lines) {
            throw new \InvalidArgumentException('An order needs at least one line.');
        }

        $deliveryCountry = '' !== $draft->shipping->country
            ? $draft->shipping->country
            : $draft->billing->country;

        $reverseCharge = $this->appliesReverseCharge($draft, $deliveryCountry);

        // The same source MoneyFormatter and CartPricer read, so that an
        // amount shown and the amount it contributes cannot drift apart.
        $digits = Currencies::getFractionDigits($draft->currency);

        $prepared = [];
        $totals = new OrderTotals($digits);

        foreach ($lines as $line) {
            $item = match (true) {
                $line->frozen => $this->frozenLine($line),
                OrderLineType::Product === $line->type => $this->productLine($line, $draft, $timestamp),
                default => $this->chargeLine($line),
            };

            /*
             * Per line (DL4): a service in the shop's country, everything
             * else where the switch "Steuer im Bestimmungsland" puts it
             * (block R, O1) — the rule CartPricer priced the basket by. Charge
             * lines follow the goods they come with, as the checkout taxed
             * them — except a reduction, which names the country of the
             * supplies it reduces (H).
             */
            $taxCountry = OrderLineType::Product === $line->type
                ? ProductKind::stored($item['kind'])->taxCountry($deliveryCountry, $draft->shopCountry, $draft->taxAtDestination, $item['singlePurpose'] ?? false)
                : ('' !== $line->taxCountry ? $line->taxCountry : TaxPlace::forDistanceSale($deliveryCountry, $draft->shopCountry, $draft->taxAtDestination));

            $rate = $reverseCharge
                ? BigDecimal::zero()
                : BigDecimal::of($this->taxes->resolveRate($item['taxClass'], $taxCountry, $timestamp));

            /*
             * A voucher at 0 (WG3): its class is "nicht steuerbar" (or, under
             * reverse charge, would be — the sale of a multi-purpose voucher
             * is no supply). Frozen as such, without a country: no country's
             * tax is claimed. A voucher at a rate above 0 is a class the
             * operator did not mark — sold as taxed, and the finding says so.
             */
            $notTaxable = OrderLineType::Product === $line->type && ProductKind::Voucher === ProductKind::stored($item['kind']) && $rate->isZero()
                // EZ-1: the sale of a single-purpose voucher IS a supply, so
                // under reverse charge it passes over like goods — unless its
                // class itself is "nicht steuerbar".
                && !(($item['singlePurpose'] ?? false) && $reverseCharge && !BigDecimal::of($this->taxes->resolveRate($item['taxClass'], $taxCountry, $timestamp))->isZero());

            if ($notTaxable) {
                $taxCountry = '';
            }

            /*
             * EZ-1, EZ-5: a single-purpose voucher is one whose sale was
             * taxed. Sold at 0 — a class marked "nicht steuerbar", reverse
             * charge — nothing was taxed that a later purchase could leave
             * out, so the voucher it becomes is a multi-purpose one.
             */
            $singlePurpose = !$notTaxable && !$rate->isZero() && ($item['singlePurpose'] ?? false);

            $lineNet = BigDecimal::of($item['net'])
                ->multipliedBy(BigDecimal::of($line->quantity))
                ->toScale(4, RoundingMode::HalfUp)
            ;

            $lineTax = $lineNet
                ->multipliedBy($rate)
                ->dividedBy(self::HUNDRED, 8, RoundingMode::HalfUp)
                ->toScale(4, RoundingMode::HalfUp)
            ;

            /*
             * Rounded to the currency's own place BEFORE it goes into the
             * total, the same rule CartPricer follows — the basket must not
             * contradict the order it becomes, and that holds for HOW the
             * figures are built, not only for the arithmetic. Summing the raw
             * lines and rounding once at the end produces columns that do not
             * add up to the figures underneath them, and here those columns
             * are an invoice.
             *
             * The LINE fields keep their four places. Net stays the anchor,
             * the tax still comes out of the unrounded line net, and
             * DocumentPayloadBuilder still derives a line's net as
             * lineTotal − taxAmount, which stays exact.
             *
             * ── Which column is allowed not to add up ────────────────────
             *
             * Three columns, two independent roundings: one of them cannot
             * reconcile. **The GROSS column is the one that has to**, because
             * it is the one the buyer read and agreed to under § 8 FAGG: the
             * basket shows a rounded gross per line and a total underneath,
             * and CartPricer builds that total by summing exactly those
             * rounded figures. grandTotal is therefore built the same way, and
             * taxTotal follows as the remainder.
             *
             * 🔴 This used to be the other way round — net and tax
             * rounded per line and summed, gross left to differ. It was a
             * defensible choice on its own and wrong in company: the checkout
             * already summed rounded grosses, so an order disagreed with the
             * page it came from in 16.7 % of gross prices between 1.00 and
             * 99.99 at 20 % — from 1.05 € upwards. The invoice is issued in
             * the same transaction, so the customer received a document over
             * an amount they had never seen.
             *
             * What settled it was not which column is more correct but how big
             * each error gets. The gross variant drifts **one cent per LINE**
             * and grows without bound: ten positions, ten cents. This one
             * drifts at most one cent **per TAX RATE**, whatever the basket
             * contains.
             *
             * ✅ **What this handed to C-8, and how it was settled:** the
             * per-rate tax summary summed rounded line taxes and could exceed
             * taxTotal by a cent per rate. Not by assigning the remainder to a
             * rate: DocumentPayloadBuilder::summariseTax() applies THIS rule
             * per rate — net and gross from the same rounded lines, tax as the
             * difference — so the rates add up to taxTotal exactly (per rate: gross − net).
             *
             * The summing itself lives in OrderTotals since C-10, which a
             * change of payment method uses as well.
             */
            $totals->add($lineNet, $lineNet->plus($lineTax));

            $prepared[] = [
                'lineType' => $line->type->value,
                'product' => $line->productId,
                'source' => $line->source,
                'productName' => $item['name'],
                'sku' => $item['sku'],
                'quantity' => (string) BigDecimal::of($line->quantity)->toScale(4, RoundingMode::HalfUp),
                'unitPrice' => (string) BigDecimal::of($item['net'])->toScale(4, RoundingMode::HalfUp),
                // The class next to the rate it resolved to (C-10). The rate
                // is what the invoice states; the class is what a later
                // payment amount has to be split by, like at the purchase —
                // and two classes may well share a rate today.
                'taxClass' => $item['taxClass'],
                'taxRate' => (string) $rate->toScale(4, RoundingMode::HalfUp),
                // Which country's rate that is (DL4) — the invoice and a later
                // correction must not have to work it out again.
                'taxCountry' => strtoupper($taxCountry),
                // Where the supply went (block R, R4): the country the rule
                // above was asked with. Not the same as taxCountry while the
                // switch is off — and the threshold counts by this one.
                'destinationCountry' => strtoupper($deliveryCountry),
                'taxAmount' => (string) $lineTax,
                'lineTotal' => (string) $lineNet->plus($lineTax),
                'priceSource' => match (true) {
                    $notTaxable => PriceSource::NotTaxable->value,
                    $reverseCharge => PriceSource::ReverseCharge->value,
                    default => $item['priceSource'],
                },
                'customerGroup' => $item['customerGroup'],
                'kind' => $item['kind'],
                // H-5: the line's part of the cart rule's discount, frozen.
                'discountNet' => (string) BigDecimal::of($line->discountNet)->toScale(4, RoundingMode::HalfUp),
                // WG-4: a voucher's value, frozen like its price.
                'voucherValue' => (string) BigDecimal::of($item['voucherValue'] ?? '0')->toScale(4, RoundingMode::HalfUp),
                // EZ-5: issued as single-purpose — at the class, rate and country above.
                'voucherSinglePurpose' => $singlePurpose ? 1 : 0,
                // Block GARAN (GA6): the guarantee and label shown before the button.
                'durabilityYears' => $item['durabilityYears'] ?? '0',
                'durabilityLabel' => $item['durabilityLabel'] ?? null,
                ...$this->subscriptionPart($line, $item, (string) ($reverseCharge ? $lineNet : $lineNet->plus($lineTax)), $reverseCharge, $draft, $taxCountry, $timestamp),
            ];
        }

        /*
         * Does the order come to what the buyer agreed to? Checked BEFORE the
         * number is allocated, so a disagreement costs no order number.
         *
         * The checksum (CheckoutFingerprint) proves the input has not changed;
         * this proves that two pieces of code agree on what that input costs.
         * Different questions — and the second one went unasked until the
         * two were found disagreeing in 16.7 % of cases.
         */
        if ('' !== $draft->expectedGrandTotal && 0 !== $totals->grossDecimal()->compareTo($draft->expectedGrandTotal)) {
            throw TotalDisagreesWithCheckout::between($draft->expectedGrandTotal, $totals->gross());
        }

        /*
         * The number is allocated last, after everything that can fail has
         * failed. An order number is allowed to have gaps, but there is no
         * reason to burn one on a draft that was never going to work.
         */
        $orderNumber = $this->numbers->next($draft->shopId, 'order');

        $orderId = $this->writer->createOrder([
            'tstamp' => $timestamp,
            'createdAt' => $timestamp,
            'shop' => $draft->shopId,
            'orderNumber' => $orderNumber,
            'member' => $draft->memberId,
            'language' => $draft->language,
            'currency' => $draft->currency,
            'netTotal' => $totals->net(),

            // The remainder, not a sum of its own: grandTotal is what the
            // buyer agreed to, netTotal is what the net column adds up to, and
            // the tax is the difference between them. Summing rounded line
            // taxes here would reintroduce the very disagreement this is
            // meant to remove.
            'taxTotal' => $totals->tax(),
            'grandTotal' => $totals->gross(),
            'vatId' => $draft->customerVatId,
            'vatIdStatus' => null !== $draft->vatCheck ? $draft->vatCheck->status->value : '',
            'vatIdRequestIdentifier' => null !== $draft->vatCheck ? ($draft->vatCheck->requestIdentifier ?? '') : '',
            'shippingMethod' => $draft->shippingMethod,
            'shippingMethodTitle' => $draft->shippingMethodTitle,
            'paymentMethod' => $draft->paymentMethod,
            'paymentMethodTitle' => $draft->paymentMethodTitle,
            'paymentInstructions' => $draft->paymentInstructions,
            'minimumAge' => $draft->minimumAge,
            'dateOfBirth' => $draft->dateOfBirth,
            'termsAcceptedAt' => $draft->termsAcceptedAt,
            'digitalConsentAt' => $draft->digitalConsentAt,
            'businessCustomer' => $draft->businessCustomer ? 1 : 0,
            'subscription' => $draft->subscriptionId,
            'servicePeriodFrom' => $draft->servicePeriodFrom,
            'servicePeriodUntil' => $draft->servicePeriodUntil,
            ...$this->frozenAddresses($draft),
        ]);

        foreach ($prepared as $sorting => $item) {
            $this->writer->createItem(['pid' => $orderId, 'sorting' => ($sorting + 1) * 128, 'tstamp' => $timestamp] + $item);
        }

        return $orderId;
    }

    /**
     * An article: looked up, never taken from the request.
     *
     * @return array{net: string, taxClass: int, name: string, sku: string, priceSource: string, customerGroup: int, kind: string, mainProduct: int, voucherValue: string, singlePurpose: bool, durabilityYears: string, durabilityLabel: string|null}
     *
     * @throws ProductGoneFromCatalogue
     * @throws PriceNotFound
     */
    private function productLine(OrderLineDraft $line, OrderDraft $draft, int $timestamp): array
    {
        $snapshot = $this->products->snapshot($line->productId, $draft->language, $draft->fallbackLanguage);

        if (null === $snapshot) {
            throw ProductGoneFromCatalogue::withId($line->productId);
        }

        $price = $this->prices->forCartLine(
            $line->productId,
            $snapshot['mainProduct'],
            $draft->currency,
            $line->quantity,
            $timestamp,
            $draft->customerGroupIds,
            // The line's contract (Abo-2): the same rows the basket priced.
            $line->subscriptionRule,
        );

        /*
         * The checkout priced this basket through the same source moments ago,
         * so a null here means the catalogue changed between the review step
         * and the click — exactly what the checksum exists to catch, and worth
         * an exception rather than a zero-priced line.
         */
        if (null === $price) {
            throw PriceNotFound::for($line->productId, $draft->currency, $draft->customerGroupIds);
        }

        return [
            'net' => $price->unitNet,
            'taxClass' => $price->taxClassId,
            'name' => $snapshot['name'],
            'sku' => $snapshot['sku'],
            'priceSource' => $price->source->value,
            'customerGroup' => $price->customerGroup ?? 0,
            'kind' => $snapshot['kind'],
            'mainProduct' => $snapshot['mainProduct'],
            'voucherValue' => ProductKind::Voucher->value === $snapshot['kind'] ? ($snapshot['voucherValue'] ?? '0') : '0',
            'singlePurpose' => ProductKind::Voucher->value === $snapshot['kind'] && ($snapshot['singlePurpose'] ?? false),
            'durabilityYears' => $snapshot['durabilityYears'] ?? '0',
            'durabilityLabel' => $snapshot['durabilityLabel'] ?? null,
        ];
    }

    /**
     * A subscription's follow-up line (Abo-4, P25): everything as frozen at
     * the purchase, nothing from the catalogue. The tax class goes on to
     * resolveRate() like any other line — the rate follows the law (A9).
     *
     * @return array{net: string, taxClass: int, name: string, sku: string, priceSource: string, customerGroup: int, kind: string, mainProduct: int}
     */
    private function frozenLine(OrderLineDraft $line): array
    {
        return [
            'net' => $line->net,
            'taxClass' => $line->taxClassId,
            'name' => $line->title,
            'sku' => $line->sku,
            'priceSource' => PriceSource::List->value,
            'customerGroup' => 0,
            // As frozen on the subscription: digital or service (A13, DL1).
            'kind' => $line->kind,
            'mainProduct' => 0,
        ];
    }

    /**
     * Shipping or a payment adjustment: the amount is already decided.
     *
     * @return array{net: string, taxClass: int, name: string, sku: string, priceSource: string, customerGroup: int, kind: string, mainProduct: int}
     */
    private function chargeLine(OrderLineDraft $line): array
    {
        /*
         * A fee arrives here already taxed the way the operator chose at the
         * method (E-1, Z3): the calculator split it into shares with their
         * tax class, and a method without that choice was never offered.
         * Until E-1 this refused every fee (by decision).
         */
        return [
            'net' => $line->net,
            'taxClass' => $line->taxClassId,
            'name' => $line->title,
            'sku' => '',

            // Not a price from the catalogue, so no provenance to state. It
            // came from a method's configuration, and `source` names which.
            'priceSource' => PriceSource::List->value,
            'customerGroup' => 0,

            // Only read together with lineType `product`: a charge is not a
            // good of any kind.
            'kind' => '',
            'mainProduct' => 0,
        ];
    }

    /**
     * What a line freezes about its contract (Abo-2, P13, A12):
     *
     * - the plan, 0 for a one-time sale and every charge;
     * - the conditions in the order's language, built from the amount the
     *   line comes to — gross, or net under reverse charge, as the invoice
     *   shows it. Frozen because the rule can change tomorrow and the invoice
     *   must still say what was agreed today;
     *
     * The line's kind (DL1) is frozen beside it, in the line itself.
     *
     * @param array{kind: string, mainProduct: int} $item
     *
     * @return array{subscriptionRule: int, subscriptionTerms: string, renews?: int}
     */
    private function subscriptionPart(OrderLineDraft $line, array $item, string $lineAmount, bool $reverseCharge, OrderDraft $draft, string $country, int $timestamp): array
    {
        $isProduct = OrderLineType::Product === $line->type;

        // Abo-4: the terms of the purchase, not rebuilt — the rule may have
        // changed since.
        if ($line->frozen) {
            return ['subscriptionRule' => $line->subscriptionRule, 'subscriptionTerms' => $line->terms];
        }

        return [
            'subscriptionRule' => $isProduct ? $line->subscriptionRule : 0,
            // Abo-7b: the renewed subscription, only on a subscription line.
            'renews' => $isProduct && $line->subscriptionRule > 0 ? $line->renews : 0,
            'subscriptionTerms' => $isProduct && $line->subscriptionRule > 0
                ? (string) $this->terms?->of($line->subscriptionRule, $lineAmount, $draft->currency, $draft->language)
                : '',
        ];
    }

    /**
     * Reverse charge needs a verified VAT ID AND a border.
     *
     * A valid Austrian VAT ID buying from an Austrian shop still pays Austrian
     * VAT — the rule is about cross-border B2B within the EU. The shop's own
     * country is its home country (C25); it used to be read from the prefix of
     * its VAT ID, which a small business without one does not have.
     */
    private function appliesReverseCharge(OrderDraft $draft, string $deliveryCountry): bool
    {
        // Abo-4, P26: a follow-up order carries the purchase's decision.
        if ($draft->frozenReverseCharge) {
            return true;
        }

        if (true !== $draft->vatCheck?->status->grantsReverseCharge()) {
            return false;
        }

        // The shop's own VAT ID as well — see OrderDraft::$shopVatId.
        if ('' === trim($draft->shopVatId)) {
            return false;
        }

        $shopCountry = strtoupper(trim($draft->shopCountry));

        return '' !== $shopCountry && $shopCountry !== strtoupper($deliveryCountry);
    }

    /**
     * @return array<string, string|int>
     */
    private function frozenAddresses(OrderDraft $draft): array
    {
        $billing = $draft->billing;
        $shipping = $draft->shipping;

        return [
            'salutation' => $billing->salutation,
            'firstname' => $billing->firstname,
            'lastname' => $billing->lastname,
            'email' => $billing->email,
            'phone' => $billing->phone,
            'company' => $billing->company,

            'billingStreet' => $billing->street,
            'billingPostal' => $billing->postal,
            'billingCity' => $billing->city,
            'billingState' => $billing->state,
            'billingCountry' => $billing->country,

            'shippingCompany' => $shipping->company,
            'shippingFirstname' => $shipping->firstname,
            'shippingLastname' => $shipping->lastname,
            'shippingStreet' => $shipping->street,
            'shippingPostal' => $shipping->postal,
            'shippingCity' => $shipping->city,
            'shippingState' => $shipping->state,
            'shippingCountry' => $shipping->country,
            'shippingPhone' => $draft->shippingPhone,
        ];
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;

/**
 * Everything an order needs that is not a line.
 *
 * `memberId` of 0 is a guest order — it has no account, not less data. Both
 * addresses are values and get frozen onto the order as they are.
 */
final class OrderDraft
{
    /**
     * @param string    $shopCountry     the shop's home country (tl_consho_shop.country) —
     *                                   it decides whether a sale is domestic or cross-border
     * @param list<int> $customerGroupIds the buyer's groups, empty for a guest. A LIST since
     *                                   C-4: tl_member.groups is a multiple choice, the
     *                                   resolution runs per group and the cheapest offer
     *                                   wins, so which one won is a question per LINE
     * @param string    $language        the language the order was placed in — it decides
     *                                   which product names get frozen onto the lines
     * @param int|null  $termsAcceptedAt null in a shop that does not require the tick
     * @param string    $dateOfBirth     `YYYY-MM-DD`, empty where no age limit applied
     * @param string    $expectedGrandTotal the amount the buyer pressed the button on.
     *                                   OrderFactory refuses to write an order that does
     *                                   not come to it. Empty skips the check — only for
     *                                   callers that have no such figure
     */
    public function __construct(
        public readonly int $shopId,
        public readonly string $currency,
        public readonly Address $billing,
        public readonly Address $shipping,
        public readonly string $shopCountry = '',
        public readonly int $memberId = 0,
        public readonly array $customerGroupIds = [],
        public readonly VatIdCheckResult|null $vatCheck = null,
        public readonly string $customerVatId = '',
        public readonly string $language = '',
        public readonly string $fallbackLanguage = '',
        public readonly int $shippingMethod = 0,
        public readonly string $shippingMethodTitle = '',
        public readonly int $paymentMethod = 0,
        public readonly string $paymentMethodTitle = '',
        public readonly string $paymentInstructions = '',
        public readonly int $minimumAge = 0,
        public readonly string $dateOfBirth = '',
        public readonly int|null $termsAcceptedAt = null,
        public readonly string $expectedGrandTotal = '',
        /**
         * The shop's own VAT ID (tl_consho_shop.vatId). Without it there is no
         * reverse charge: the invoice over a tax-free intra-community supply
         * has to state it (Art. 11 Abs. 2 UStG 1994 Anhang Binnenmarkt; C-12,
         * Michael 2026-09-26: "1a nur mit eigener UID").
         */
        public readonly string $shopVatId = '',
        /**
         * The recipient's phone for the carrier (E-6, V6), empty unless the
         * chosen shipping method needs it.
         */
        public readonly string $shippingPhone = '',
        /**
         * The consumer's consent to the early start of digital content (F-3,
         * F15), null where it was not given or not asked for.
         */
        public readonly int|null $digitalConsentAt = null,
        /**
         * The buyer counted as a business at the purchase (F-3): a net price
         * group or a verified VAT ID. No consent is asked of a business, and
         * its digital lines are released without waiting for a withdrawal
         * period it does not have.
         */
        public readonly bool $businessCustomer = false,
        /**
         * The subscription a follow-up order bills (Abo-4, P31), 0 for a
         * purchase.
         */
        public readonly int $subscriptionId = 0,
        /**
         * Reverse charge as frozen on the subscription (Abo-4, P26): a
         * follow-up order has no VAT check of its own. Never set at a
         * purchase, where the check decides.
         */
        public readonly bool $frozenReverseCharge = false,
        /**
         * The period a follow-up order bills (Abo-4, P31), frozen on the
         * order: the invoice states it, and the subscription moves on.
         */
        public readonly int|null $servicePeriodFrom = null,
        public readonly int|null $servicePeriodUntil = null,
        /*
         * Block H (H-4): the cart rule the order uses and the code typed —
         * OrderPlacer counts the use in the order's transaction (W5). 0 for
         * none; free shipping counts as a use too.
         */
        public readonly int $discountRule = 0,
        public readonly string $discountCode = '',
        /*
         * Block Wertgutscheine (WG-5): the vouchers that pay, in their
         * order, with what each takes — as the review page showed them.
         * OrderPlacer books them in the order's transaction (E4).
         */
        /** @var list<array{id: int, code: string, amount: string}> */
        public readonly array $vouchers = [],
        /*
         * Block R (O1, R3): the shop's switch "Steuer im Bestimmungsland" —
         * at a purchase as the checkout priced it, at a follow-up order as
         * the shop says it today, like the rate looked up anew.
         */
        public readonly bool $taxAtDestination = false,
    ) {
    }
}

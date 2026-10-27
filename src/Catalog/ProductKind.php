<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Webwerkwien\ContaoConshoBundle\Tax\TaxPlace;

/**
 * What a product is, set at its TYPE (Block DL, DL1, DL5).
 *
 * Until this block a type was physical or not, and a digital product said at
 * itself whether it was withdrawn like content or like a service. A gym
 * membership is neither a parcel nor a download, so the kind is a third
 * value at the type, and everything that depended on the two flags reads it.
 *
 *  - Physical: shipped, product safety duties, never a subscription.
 *  - Digital: delivered electronically (file, key, access) — taxed like
 *    goods (TaxPlace), withdrawn like content.
 *  - Service: nothing is delivered — taxed in the shop's country (DL4),
 *    withdrawn like a service.
 *  - Voucher (block Wertgutscheine, WG3): a gift voucher, issued as a code
 *    once paid (WG6) — no shipping, no subscription, no consent to an early
 *    start (nothing is performed early; the code is money). Its tax is that
 *    of its class, which the operator marks "nicht steuerbar" for a
 *    multi-purpose voucher (WG2, the findings watch the pair).
 *
 * Whether a service rendered online is "electronically supplied" (and so
 * digital) the operator decides when creating the type; Consho does not.
 *
 * The kind is frozen on the order line and the subscription at purchase:
 * changing a type later must not change what was sold.
 */
enum ProductKind: string
{
    case Physical = 'physical';
    case Digital = 'digital';
    case Service = 'service';
    case Voucher = 'voucher';

    /**
     * No or an unknown value counts as physical: it switches legal duties on
     * and keeps shipping, while wrongly calling a parcel digital would drop
     * both in silence.
     */
    public static function stored(string|null $value): self
    {
        return self::tryFrom((string) $value) ?? self::Physical;
    }

    public function isPhysical(): bool
    {
        return self::Physical === $this;
    }

    public function allowsSubscription(): bool
    {
        return self::Digital === $this || self::Service === $this;
    }

    public function isVoucher(): bool
    {
        return self::Voucher === $this;
    }

    /**
     * The country a line of this kind is taxed in (DL4): the shop's for a
     * service, the customer's otherwise. One rule for basket, order and
     * follow-up bill, so the three cannot disagree about the same line.
     * Without a shop country there is nothing else to go by than the
     * customer's.
     *
     * The shop's country is the rule for a service to a CONSUMER (§ 3a
     * Abs. 1 UStG, § 3a Abs. 7 UStG 1994). A business customer with a
     * verified VAT ID gets reverse charge on the whole basket before this
     * matters (OrderFactory, CheckoutCalculator). One without a verified ID
     * is treated here like a consumer — a simplification, as it is for
     * goods; § 3a Abs. 2 UStG would place the service where that business
     * is (DL).
     *
     * Everything else is a supply that travels to the customer and follows
     * the switch "Steuer im Bestimmungsland" (block R, O1): below the
     * threshold a consumer in another member state is taxed in the shop's
     * country (TaxPlace).
     *
     * A single-purpose voucher (spec Wertgutscheine, EZ-2, F5) is the
     * shop's country as well, whatever the switch says: it is only offered
     * for supplies taxed there, so its tax has to be that country's.
     */
    public function taxCountry(string $customerCountry, string $shopCountry, bool $taxAtDestination, bool $singlePurpose = false): string
    {
        if ((self::Service === $this || (self::Voucher === $this && $singlePurpose)) && '' !== trim($shopCountry)) {
            return strtoupper(trim($shopCountry));
        }

        return TaxPlace::forDistanceSale($customerCountry, $shopCountry, $taxAtDestination);
    }

    /**
     * The key of the withdrawal consent texts at checkout and in the
     * confirmation mail: `content` or `service`, empty for physical goods and
     * vouchers, which have no such consent.
     */
    public function withdrawalKind(): string
    {
        return match ($this) {
            self::Physical, self::Voucher => '',
            self::Digital => 'content',
            self::Service => 'service',
        };
    }
}

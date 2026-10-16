<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;

/**
 * What the visitor has entered so far, as it lives in
 * tl_consho_cart.checkoutData (spec 5.1, 7.2).
 *
 * In the basket row and NOT in the session: a session cookie would make every
 * further page private for this visitor (C9), which is the one thing the whole
 * cache design of block B is built to avoid. The clean-up cron deletes old
 * baskets, and these personal details go with them — no second retention rule
 * to forget about.
 *
 * Immutable. Every `with…()` returns a new instance, so the calculator (7.2)
 * can build a hypothetical state — "what if the country were DE" — without
 * anything reaching the database. Saving is a separate, deliberate step.
 *
 * ⚠️ Reading NEVER throws. A row can hold JSON from an older shape of this
 * class, from a half-finished write, or from a hand-edited column, and a
 * visitor who has put something in the basket must not meet a 500 because of
 * it. Every field falls back to empty, which is the same state as "not filled
 * in yet" — the checkout then asks again, which is the correct answer either
 * way. This is why there is no fromJson() that reports what it could not read:
 * nobody could act on the report.
 */
final class CheckoutData
{
    public function __construct(
        public readonly Address $billing = new Address(),
        public readonly Address $shipping = new Address(),
        /**
         * Whether the visitor asked for a delivery address of its own. Kept
         * apart from comparing the two addresses: someone may fill in a
         * separate address that happens to be identical, and unticking the box
         * has to clear it rather than silently keeping it.
         */
        public readonly bool $separateShipping = false,
        public readonly int $shippingMethod = 0,
        public readonly int $paymentMethod = 0,
        public readonly string $vatId = '',
        public readonly VatIdCheckResult|null $vatCheck = null,
        /**
         * ISO date, or empty. Only ever asked for when the chosen shipping
         * method says it needs one (C19) — in C nothing does.
         */
        public readonly string $dateOfBirth = '',
        public readonly int $termsAcceptedAt = 0,
        /**
         * The recipient's phone for the carrier (E-6, V6). Only asked for,
         * and only kept on the order, when the chosen shipping method says
         * its carrier needs it (ShippingCapabilities::$needsPhone).
         */
        public readonly string $shippingPhone = '',
        /**
         * When a consumer consented to the early start of digital content and
         * confirmed losing the right of withdrawal by it (F-3, F15). Written
         * at the purchase click, like termsAcceptedAt, never at the tick.
         */
        public readonly int $digitalConsentAt = 0,
    ) {
    }

    public static function fromJson(string $json): self
    {
        if ('' === trim($json)) {
            return new self();
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self();
        }

        if (!\is_array($decoded)) {
            return new self();
        }

        return new self(
            self::addressOf($decoded['billing'] ?? null),
            self::addressOf($decoded['shipping'] ?? null),
            true === ($decoded['separateShipping'] ?? null),
            self::intOf($decoded['shippingMethod'] ?? null),
            self::intOf($decoded['paymentMethod'] ?? null),
            self::stringOf($decoded['vatId'] ?? null),
            self::vatCheckOf($decoded['vatCheck'] ?? null),
            self::stringOf($decoded['dateOfBirth'] ?? null),
            self::intOf($decoded['termsAcceptedAt'] ?? null),
            self::stringOf($decoded['shippingPhone'] ?? null),
            self::intOf($decoded['digitalConsentAt'] ?? null),
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'billing' => $this->fieldsOf($this->billing),
            'shipping' => $this->fieldsOf($this->shipping),
            'separateShipping' => $this->separateShipping,
            'shippingMethod' => $this->shippingMethod,
            'paymentMethod' => $this->paymentMethod,
            'vatId' => $this->vatId,
            'vatCheck' => null === $this->vatCheck ? null : [
                'status' => $this->vatCheck->status->value,
                'checkedAt' => $this->vatCheck->checkedAt,
                'name' => $this->vatCheck->name,
                'requestIdentifier' => $this->vatCheck->requestIdentifier,
            ],
            'dateOfBirth' => $this->dateOfBirth,
            'termsAcceptedAt' => $this->termsAcceptedAt,
            'shippingPhone' => $this->shippingPhone,
            'digitalConsentAt' => $this->digitalConsentAt,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The address goods are delivered to — the billing address unless a
     * separate one was asked for. One place answers this, so no caller has to
     * remember the rule.
     */
    public function deliveryAddress(): Address
    {
        return $this->separateShipping ? $this->shipping : $this->billing;
    }

    public function deliveryCountry(): string
    {
        return $this->deliveryAddress()->country;
    }

    /**
     * A VAT ID grants reverse charge only while the check that approved it
     * still belongs to the ID that is entered now. Changing the ID after a
     * successful check must not keep the old verdict — that is how an unchecked
     * ID would end up on an invoice without tax.
     */
    public function hasVerifiedVatId(): bool
    {
        return '' !== $this->vatId
            && null !== $this->vatCheck
            && VatIdStatus::Valid === $this->vatCheck->status;
    }

    public function withBilling(Address $billing): self
    {
        return $this->with(['billing' => $billing]);
    }

    public function withShipping(Address $shipping, bool $separate = true): self
    {
        return $this->with(['shipping' => $separate ? $shipping : new Address(), 'separateShipping' => $separate]);
    }

    public function withShippingMethod(int $id): self
    {
        return $this->with(['shippingMethod' => $id]);
    }

    public function withPaymentMethod(int $id): self
    {
        return $this->with(['paymentMethod' => $id]);
    }

    /**
     * Entering a VAT ID drops any earlier verdict: it belonged to the old ID.
     * Passing the same ID again keeps the check, so re-submitting an unchanged
     * address does not cost a VIES request.
     */
    public function withVatId(string $vatId): self
    {
        return $vatId === $this->vatId
            ? $this
            : $this->with(['vatId' => $vatId, 'vatCheck' => null]);
    }

    public function withVatCheck(VatIdCheckResult|null $result): self
    {
        return $this->with(['vatCheck' => $result]);
    }

    public function withDateOfBirth(string $date): self
    {
        return $this->with(['dateOfBirth' => $date]);
    }

    public function withShippingPhone(string $phone): self
    {
        return $this->with(['shippingPhone' => $phone]);
    }

    public function withTermsAcceptedAt(int $timestamp): self
    {
        return $this->with(['termsAcceptedAt' => $timestamp]);
    }

    public function withDigitalConsentAt(int $timestamp): self
    {
        return $this->with(['digitalConsentAt' => $timestamp]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function with(array $values): self
    {
        return new self(
            $values['billing'] ?? $this->billing,
            $values['shipping'] ?? $this->shipping,
            \array_key_exists('separateShipping', $values) ? (bool) $values['separateShipping'] : $this->separateShipping,
            \array_key_exists('shippingMethod', $values) ? (int) $values['shippingMethod'] : $this->shippingMethod,
            \array_key_exists('paymentMethod', $values) ? (int) $values['paymentMethod'] : $this->paymentMethod,
            \array_key_exists('vatId', $values) ? (string) $values['vatId'] : $this->vatId,
            \array_key_exists('vatCheck', $values) ? $values['vatCheck'] : $this->vatCheck,
            \array_key_exists('dateOfBirth', $values) ? (string) $values['dateOfBirth'] : $this->dateOfBirth,
            \array_key_exists('termsAcceptedAt', $values) ? (int) $values['termsAcceptedAt'] : $this->termsAcceptedAt,
            \array_key_exists('shippingPhone', $values) ? (string) $values['shippingPhone'] : $this->shippingPhone,
            \array_key_exists('digitalConsentAt', $values) ? (int) $values['digitalConsentAt'] : $this->digitalConsentAt,
        );
    }

    /**
     * @return array<string, string>
     */
    private function fieldsOf(Address $address): array
    {
        return [
            'company' => $address->company,
            'salutation' => $address->salutation,
            'firstname' => $address->firstname,
            'lastname' => $address->lastname,
            'street' => $address->street,
            'postal' => $address->postal,
            'city' => $address->city,
            'state' => $address->state,
            'country' => $address->country,
            'phone' => $address->phone,
            'email' => $address->email,
        ];
    }

    private static function addressOf(mixed $value): Address
    {
        if (!\is_array($value)) {
            return new Address();
        }

        return new Address(
            self::stringOf($value['company'] ?? null),
            self::stringOf($value['salutation'] ?? null),
            self::stringOf($value['firstname'] ?? null),
            self::stringOf($value['lastname'] ?? null),
            self::stringOf($value['street'] ?? null),
            self::stringOf($value['postal'] ?? null),
            self::stringOf($value['city'] ?? null),
            self::stringOf($value['state'] ?? null),
            self::stringOf($value['country'] ?? null),
            self::stringOf($value['phone'] ?? null),
            self::stringOf($value['email'] ?? null),
        );
    }

    private static function vatCheckOf(mixed $value): VatIdCheckResult|null
    {
        if (!\is_array($value)) {
            return null;
        }

        $status = VatIdStatus::tryFrom(self::stringOf($value['status'] ?? null));

        if (null === $status) {
            return null;
        }

        $name = self::stringOf($value['name'] ?? null);
        $identifier = self::stringOf($value['requestIdentifier'] ?? null);

        return new VatIdCheckResult(
            $status,
            self::intOf($value['checkedAt'] ?? null),
            '' === $name ? null : $name,
            '' === $identifier ? null : $identifier,
        );
    }

    private static function stringOf(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function intOf(mixed $value): int
    {
        return \is_int($value) || \is_string($value) && is_numeric($value) ? (int) $value : 0;
    }
}

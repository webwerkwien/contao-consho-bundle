<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\AddressNormalizer;

/**
 * What the checkout already knows about a member, before they type anything.
 *
 * ── Why this did not exist until now ─────────────────────────────────────
 *
 * Block A 2.1 settled the question long ago: **Contao's own member fields ARE
 * the billing address**, `consho_shipping*` is the delivery address, no address
 * book and no choice. So nothing here is "suggested" — the fields are simply
 * filled with what the customer's record says.
 *
 * The seam has been there since A-5 and the form since C-5, and nobody
 * connected them: `checkoutDataOf()` reads what sits on the BASKET, and a
 * fresh basket carries empty JSON. In C-7 somebody bought while logged in
 * for the first time — and typed twelve fields, including the three that were in tl_member all along.
 *
 * ── Why filling in here and not when the basket is created ───────────────
 *
 * CheckoutController::stateOf() is the ONE place that turns a basket into a
 * CheckoutState, and it serves both the rendering and the buy click. Filling
 * in there means the click recalculates exactly what the form showed, so
 * CheckoutFingerprint agrees **without anything being written on a GET** —
 * which C-6 built the calculator to avoid.
 *
 * A member who edits their account address while the checkout is open makes
 * the checksum differ and the purchase is refused with "something has
 * changed". That is the same answer the legal texts get (F3), and it is the
 * right one: what stood above the button is what was agreed to.
 *
 * ── What is deliberately NOT taken over ──────────────────────────────────
 *
 * **`gender`**. Contao stores `male`/`female`; Consho's
 * `salutation` is free text that ends up on the invoice. Copied across, an
 * invoice would read "male". Translating it to "Herr"/"Frau" would be an
 * assumption about the form of address that the field does not carry —
 * companies, other languages, people the two words do not fit. The field is
 * not required, so it stays empty.
 *
 * **Guests** (same decision). A guest is identified by the basket cookie, and
 * a cookie is no proof of who is sitting there. On a shared machine the next
 * person would find the previous one's name and address in the form.
 */
final class MemberCheckoutDefaults
{
    /**
     * 🔴 The normalizer is here for the same reason it is on the typed
     * address (`CheckoutController::addressOf()`): what comes out of this
     * class is frozen on an invoice. Without it a prefilled address would be
     * the ONE route to the invoice that skips it — "Eva@Example.ORG ", a
     * postal code with a space in it, an unformatted phone number — and
     * precisely for the customer who never touched the form, so nobody
     * corrected it either.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly AddressNormalizer $normalizer,
    ) {
    }

    /**
     * @param int $memberId 0 for a guest, and then nothing happens
     */
    public function applyTo(CheckoutData $data, int $memberId): CheckoutData
    {
        if ($memberId < 1) {
            return $data;
        }

        return $this->withDateOfBirth($this->withAddress($data, $memberId), $memberId);
    }

    /**
     * The account's date of birth, for a checkout that has none yet. Filled
     * whether or not the shipping method asks: the field only shows, and the
     * order only keeps it, where it does (CheckoutState::$needsDateOfBirth).
     *
     * Contao keeps it as a timestamp of midnight in the server's time zone
     * (`rgxp => date`), so date() in that same zone gives the day back.
     */
    private function withDateOfBirth(CheckoutData $data, int $memberId): CheckoutData
    {
        if ('' !== $data->dateOfBirth) {
            return $data;
        }

        $stamp = $this->connection->fetchOne('SELECT dateOfBirth FROM tl_member WHERE id = ?', [$memberId]);

        $stamp = \is_scalar($stamp) ? trim((string) $stamp) : '';

        // '0' is an emptied field, not somebody born on 1 January 1970.
        if ('0' === $stamp || 1 !== preg_match('/^-?\d+$/', $stamp)) {
            return $data;
        }

        return $data->withDateOfBirth(date('Y-m-d', (int) $stamp));
    }

    private function withAddress(CheckoutData $data, int $memberId): CheckoutData
    {
        if (!$data->billing->isEmpty()) {
            return $data;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT company, firstname, lastname, street, postal, city, state, country, phone, email,
                    consho_shippingCompany, consho_shippingSalutation, consho_shippingFirstname,
                    consho_shippingLastname, consho_shippingStreet, consho_shippingPostal,
                    consho_shippingCity, consho_shippingState, consho_shippingCountry, consho_vatId
             FROM tl_member WHERE id = ?',
            [$memberId],
        );

        if (false === $row) {
            return $data;
        }

        $billing = new Address(
            company: self::stringOf($row['company']),
            firstname: self::stringOf($row['firstname']),
            lastname: self::stringOf($row['lastname']),
            street: self::stringOf($row['street']),
            postal: self::stringOf($row['postal']),
            city: self::stringOf($row['city']),
            state: self::stringOf($row['state']),
            country: self::countryOf($row['country']),
            phone: self::stringOf($row['phone']),
            email: self::stringOf($row['email']),
        );

        if ($billing->isEmpty()) {
            return $data;
        }

        $billing = $this->normalizer->normalize($billing);

        /*
         * No phone and no email: the delivery field set has neither, and
         * neither does the order's frozen copy. The billing address carries
         * them, and somebody sending a gift does not have the recipient's.
         */
        $shipping = new Address(
            company: self::stringOf($row['consho_shippingCompany']),
            salutation: self::stringOf($row['consho_shippingSalutation']),
            firstname: self::stringOf($row['consho_shippingFirstname']),
            lastname: self::stringOf($row['consho_shippingLastname']),
            street: self::stringOf($row['consho_shippingStreet']),
            postal: self::stringOf($row['consho_shippingPostal']),
            city: self::stringOf($row['consho_shippingCity']),
            state: self::stringOf($row['consho_shippingState']),
            country: self::countryOf($row['consho_shippingCountry']),
        );

        /*
         * A delivery address on the account is TICKED, not just filled in.
         * Left unticked it would sit in a collapsed part of the form that the
         * visitor has no reason to open — a stored address nobody sees is
         * worse than none, because the parcel goes to the billing address
         * while the account says otherwise.
         */
        // Check first, then normalise: `normalize()` would not fill an empty
        // address, but the order says what matters.
        $ticked = !$shipping->isEmpty();
        $filled = $data->withBilling($billing)->withShipping($ticked ? $this->normalizer->normalize($shipping) : $shipping, $ticked);

        return '' === $filled->vatId
            ? $filled->withVatId(self::stringOf($row['consho_vatId']))
            : $filled;
    }

    /**
     * The member's billing address as the account says it today, normalised
     * like the checkout's — for a subscription's follow-up invoice (Abo-
     * Nachlese, P74: every new invoice takes the
     * billing address entered now). Null where the
     * account lacks what an invoice needs (name, street, postcode, town,
     * country, email): the caller keeps the address it had.
     *
     * The email only where the member has confirmed it (Abo-Nachlese) — Contao's "Persönliche Daten" changes it
     * without any check, and the invoice goes there; MemberOrders trusts the
     * account's address on the same condition. Otherwise $fallbackEmail.
     */
    public function billingOf(int $memberId, string $fallbackEmail = ''): Address|null
    {
        if ($memberId < 1) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT company, firstname, lastname, street, postal, city, state, country, phone, email, consho_confirmedEmail FROM tl_member WHERE id = ?',
            [$memberId],
        );

        if (false === $row) {
            return null;
        }

        $email = mb_strtolower(self::stringOf($row['email']));
        $confirmed = '' !== $email && $email === self::stringOf($row['consho_confirmedEmail']);

        $billing = $this->normalizer->normalize(new Address(
            company: self::stringOf($row['company']),
            firstname: self::stringOf($row['firstname']),
            lastname: self::stringOf($row['lastname']),
            street: self::stringOf($row['street']),
            postal: self::stringOf($row['postal']),
            city: self::stringOf($row['city']),
            state: self::stringOf($row['state']),
            country: self::countryOf($row['country']),
            phone: self::stringOf($row['phone']),
            email: $confirmed ? self::stringOf($row['email']) : trim($fallbackEmail),
        ));

        foreach ([$billing->lastname, $billing->street, $billing->postal, $billing->city, $billing->country, $billing->email] as $required) {
            if ('' === $required) {
                return null;
            }
        }

        return $billing;
    }

    private static function stringOf(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * 🔴 **Contao 5 stores the code UPPERCASE** — `Countries::getCountryCodes()`
     * says so in as many words, and the options array is keyed by it. (Contao 4
     * stored it lowercase, which is the assumption worth not carrying over.)
     * So no case conversion, and a wrong one would be invisible: `at` simply
     * finds no tax rate.
     *
     * But the column is `varchar(6)`, because Contao allows subdivisions like
     * `GB-ENG`, while Consho's own country fields are two characters and every
     * tax rate, delivery country and shop whitelist is keyed by ISO 3166-1
     * alpha-2. Anything else is dropped rather than truncated: `GB` guessed
     * from `GB-ENG` would be a delivery country the customer never chose.
     */
    private static function countryOf(mixed $value): string
    {
        $code = self::stringOf($value);

        return 2 === \strlen($code) ? strtoupper($code) : '';
    }
}

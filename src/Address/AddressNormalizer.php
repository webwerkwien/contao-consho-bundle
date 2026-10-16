<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Address;

use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;

/**
 * Tidies address data — losslessly, and never the name.
 *
 * The GDPR principle of accuracy (Art. 5(1)(d)) means factual correctness, not
 * formatting: "hauptstr. 5" and "Hauptstraße 5" are both correct. This class
 * exists for deliverability, carrier interfaces that expect normalised fields,
 * and duplicate avoidance — see docs/block-a-fundament.md, section 5.7.
 *
 * The rule: what the customer typed is what gets stored. Only lossless changes
 * are applied. Everything else is offered as a suggestion.
 */
final class AddressNormalizer
{
    private const STREET_ABBREVIATIONS = [
        'str.' => 'straße',
        'Str.' => 'straße',
        'STR.' => 'straße',
        'g.' => 'gasse',
        'G.' => 'gasse',
        'pl.' => 'platz',
        'Pl.' => 'platz',
    ];

    public function __construct(
        private readonly CountryProvider $countries,
        private readonly PhoneNormalizer $phone,
    ) {
    }

    public function normalize(Address $address): Address
    {
        $country = $this->countries->normalise($address->country);

        return $address->with([
            /*
             * 🔴 Names are NOT touched. Not upper, not lower, not title case.
             *
             * "van der Berg", "O'Brien", "MacLeod", "de la Cruz", "ÖZDEMIR" —
             * a title case function ruins every one of them, and the result
             * ends up on an invoice, which is a legal document. The test data
             * for this is the point of the class, not a nicety.
             */
            'firstname' => $address->firstname,
            'lastname' => $address->lastname,

            'company' => $this->collapse($address->company),
            'salutation' => $this->collapse($address->salutation),
            'street' => $this->collapse($address->street),
            'postal' => (string) preg_replace('/\s+/u', '', trim($address->postal)),
            'city' => $this->collapse($address->city),
            'state' => $this->collapse($address->state),
            'country' => $country,
            'phone' => $this->phone->normalize($address->phone, $country),
            'email' => mb_strtolower(trim($address->email)),
        ]);
    }

    /**
     * Changes the customer may accept — never applied automatically.
     *
     * @return list<string>
     */
    public function suggestions(Address $address): array
    {
        $street = $this->collapse($address->street);

        if ('' === $street) {
            return [];
        }

        $suggested = strtr($street, self::STREET_ABBREVIATIONS);
        $suggested = mb_convert_case($suggested, MB_CASE_TITLE, 'UTF-8');

        // Only offer it when it actually differs, so a tidy street produces no
        // pointless "suggestion" the customer has to dismiss.
        return $suggested === $street ? [] : [$suggested];
    }

    private function collapse(string $value): string
    {
        /*
         * Unicode normalisation first: "ö" typed as o+combining-diaeresis and
         * "ö" as a single code point look identical and compare unequal, which
         * would defeat the duplicate avoidance this class is partly for.
         */
        $normalized = \Normalizer::isNormalized($value, \Normalizer::FORM_C)
            ? $value
            : (\Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value);

        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
    }
}

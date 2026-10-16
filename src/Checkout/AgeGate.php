<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities;

/**
 * Whether a basket with an age restriction may be ordered (C19).
 *
 * ── The three levels ──────────────────────────────────────────────────────
 *
 * 0            nothing to check
 * 6, 12, 14    a NOTICE, by definition. These exist because a toy or a film
 *              carries them, and nobody checks an ID for a board game.
 * 16, 18       checked, and blocked when it cannot be.
 *
 * ── The two ways through 16 and 18 ────────────────────────────────────────
 *
 * 1. The member is in a group the operator marked as age-verified
 *    (`ageVerifiedGroups`). The operator verified it somewhere else and
 *    answers for it; Consho verifies nothing itself.
 * 2. The chosen shipping method checks at hand-over, from that level up.
 *
 * **Either one opens the order** (Michael, 2026-09-22: "Einer genügt"). That
 * is how the trade works — an identified customer OR a checked hand-over, not
 * both. Requiring both would mean a verified member could no longer choose the
 * cheap shipping method, for a reason visible only in the shop configuration.
 *
 * With NEITHER configured the article cannot be ordered. That is not a gap,
 * it is the answer: an age nobody can establish has not been established, and
 * a shop that sells spirits without either mechanism is not allowed to.
 *
 * ── The date of birth ─────────────────────────────────────────────────────
 *
 * Asked for only when the shipping method says it needs one, and then checked
 * against the limit. In C nothing asks — the flat rate has no hand-over check.
 * The code is here because the rule belongs with the rest of the rule, not
 * because something uses it today.
 */
final class AgeGate
{
    /**
     * Below this an age limit is a notice and never blocks (C19).
     */
    private const CHECKED_FROM = 16;

    /**
     * @param list<int> $customerGroups    the visitor's groups
     * @param list<int> $ageVerifiedGroups the shop's `ageVerifiedGroups`
     *
     * @return CheckoutProblem|null null when the basket may be ordered
     */
    public function check(
        int $minimumAge,
        array $customerGroups,
        array $ageVerifiedGroups,
        ShippingCapabilities|null $shipping,
        string $dateOfBirth,
        int $now,
    ): CheckoutProblem|null {
        if ($minimumAge < self::CHECKED_FROM) {
            return null;
        }

        /*
         * The date of birth question comes FIRST, before the two ways below.
         * A method that asks for one asks in order to check it, and answering
         * "you are old enough because you are in a group" would make the
         * question decorative.
         */
        if (true === $shipping?->needsDateOfBirth) {
            if ('' === $dateOfBirth) {
                return CheckoutProblem::DateOfBirthMissing;
            }

            if (!$this->isOldEnough($dateOfBirth, $minimumAge, $now)) {
                return CheckoutProblem::TooYoung;
            }
        }

        /*
         * ⚠️ Das Geburtsdatum kommt ZUERST, ersetzt die zwei Wege aber NICHT.
         * Vor dem Review vom 2026-09-22 stand hier `return null` — damit hätte
         * eine Versandart, die ab 16 prüft und ein Geburtsdatum verlangt, eine
         * Lieferung ab 18 durchgelassen, sobald jemand ein passendes Datum
         * eintippt. Eine Selbstauskunft ist keiner der beiden Wege aus C19;
         * sie kann einen Weg nur ergänzen, nie ersetzen.
         */
        if ([] !== array_intersect($customerGroups, $ageVerifiedGroups)) {
            return null;
        }

        if (null !== $shipping && 0 !== $shipping->ageCheck && $shipping->ageCheck >= $minimumAge) {
            return null;
        }

        return CheckoutProblem::AgeNotVerifiable;
    }

    /**
     * Calendar years, not 365-day blocks: someone born on 29 February turns 18
     * on 1 March in a year without one, and DateTimeImmutable knows that.
     */
    private function isOldEnough(string $dateOfBirth, int $minimumAge, int $now): bool
    {
        try {
            $born = new \DateTimeImmutable($dateOfBirth, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            // Unreadable is not proof of age.
            return false;
        }

        $today = (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('UTC'));

        return $born->modify(\sprintf('+%d years', $minimumAge)) <= $today;
    }
}

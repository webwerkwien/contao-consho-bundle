<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

/**
 * What the buy button may say (§ 8 Abs. 2 FAGG, § 312j Abs. 3 BGB).
 *
 * The law wants a label that names the obligation to pay unmistakably, and it
 * is not a formality: without one no contract comes about at all. So the
 * choices are an enum in code, not free text in a column — Michael, 2026-09-22:
 * "feste Auswahl am Shop".
 *
 * Each case is a translation key, so the wording follows the language of the
 * page, not the language the operator happened to configure in.
 */
enum BuyButtonLabel: string
{
    /**
     * "Zahlungspflichtig bestellen" — the wording § 312j Abs. 3 BGB names
     * itself, and the safe default everywhere.
     */
    case PaymentRequired = 'paymentRequired';

    /**
     * "Kostenpflichtig bestellen" — the wording § 8 Abs. 2 FAGG offers as its
     * own alternative ("oder einer entsprechenden eindeutigen Formulierung").
     */
    case Chargeable = 'chargeable';

    /*
     * 🔴 There WAS a third case, "Kaufen", and the comment beside it cited
     * "BGH VIII ZR 244/16" as having accepted it. The review of 2026-09-22
     * looked the judgment up: it is about a stand at a trade fair and § 312b
     * BGB, and has nothing to do with the button solution. **I invented the
     * citation** — the same mistake as the Twig function of 2026-09-21, but in
     * a legal comment, where a fabricated source is worse: an operator picking
     * from a vetted list would have ended up with a button that concludes no
     * contract at all.
     *
     * On the substance the case is also weaker than the comment claimed. ECJ
     * C-249/21 (Fuhrmann-2) holds that the obligation to pay must follow from
     * the BUTTON'S WORDING ALONE, with the surrounding order process left out
     * of account — and a bare "Kaufen" names no obligation.
     *
     * The case is gone rather than merely re-commented. Whether a third
     * wording belongs here is a legal question for the operator to answer with
     * their own advice, not one to settle in a code comment. An old value in
     * the column reads back as PaymentRequired (see stored()).
     */

    /**
     * tryFrom(): an empty column on a shop configured before C-6, or a value
     * written by a later version and rolled back, must not throw — it reads
     * back as the safest of the three.
     */
    public static function stored(string|null $value): self
    {
        return self::tryFrom((string) $value) ?? self::PaymentRequired;
    }

    public function translationKey(): string
    {
        return 'CONSHO.checkout.buy.'.$this->value;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Confirmation;

/**
 * What an address is confirmed FOR (D-4, K3a).
 *
 * Michael, 2026-09-28: „baue die Funktion für die Bestätigungsmail so, dass
 * sie von mehreren Diensten in Anspruch genommen werden kann". A new use is a
 * new case here, with its texts under CONSHO.mail.confirmEmail.<value> — the
 * token, the mail and the check stay EmailConfirmation's.
 *
 * The prefix keeps the purposes apart: a token confirmed for one cannot be
 * presented for another. Contao's opt-in allows six characters; "co" marks
 * Consho, so its tokens are told apart from Contao's own (reg-, pw-, nl-) in
 * the back end's "Double-Opt-In" list.
 */
enum ConfirmationPurpose: string
{
    /** „Meine Bestellungen": guest orders under the account's address (K3). */
    case GuestOrders = 'guestOrders';

    /**
     * Block G (G15): the link in "wieder lieferbar" confirms the account's
     * address — Michael, 2026-10-05: "der Rücklink kann zur Verifizierung der
     * E-Mail-Adresse genutzt werden". No mail of EmailConfirmation's own: the
     * token rides in the notification (EmailConfirmation::issue()).
     */
    case StockNotice = 'stockNotice';

    public function prefix(): string
    {
        return match ($this) {
            self::GuestOrders => 'cogo',
            self::StockNotice => 'cosn',
        };
    }
}

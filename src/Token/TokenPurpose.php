<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Token;

/**
 * One mechanism, a separate token per purpose.
 *
 * A download link the customer forwards must not also open the order.
 *
 * ── No withdrawal token (D-2, decision E2) ────────────────────────────────
 *
 * Block A planned a third purpose for the withdrawal. It was never issued,
 * and D-2 removed it: the withdrawal runs over the ORDER PAGE's token, the link
 * the customer already has in every mail. Whoever forwards that link forwards
 * the withdrawal with it — as they already forwarded the payment change. A
 * second link per mail would protect nothing the first does not already give
 * away, and a purpose nobody issues suggests a protection that does not exist.
 */
enum TokenPurpose: string
{
    case ViewOrder = 'viewOrder';
    case Download = 'download';

    public const MAILED_LIFETIME = 60 * 60 * 24 * 90;
    public const BROWSER_LIFETIME = 60 * 60 * 24;

    /**
     * Default lifetime in seconds.
     *
     * `$mailed`: the token goes into a mail (it is the one that confirms the
     * address) rather than into a redirect the browser follows.
     *
     * ── Why the order page's token expires after all (D-6, T1/T2) ────────
     *
     * Until D-6 it did not. Block A (3.4) tied it to the withdrawal: the
     * period starts only when the goods arrive and stretches to 12 months and
     * 14 days where the trader did not inform properly, so a link with the
     * period as its lifetime would often die before the period began. Two
     * things changed that:
     *
     *  - The withdrawal no longer hangs on the link. Since D-2 the element
     *    "Vertrag widerrufen" takes order number and address — the way a
     *    customer without the mail withdraws anyway. The token still
     *    authenticates and never adjudicates; it just is not the only door.
     *  - An expired link is no longer a dead end: the order page offers to
     *    send a fresh one to the order's address (T4, OrderLinkRequest).
     *
     * What remained was a lasting credential — addresses, invoices, payment
     * change — in mailboxes and, worse, in browser histories.
     *
     * Mailed: 90 days, as a download. Every later mail about the order
     * (shipping, payment, a changed method) brings a fresh link, so the link
     * lives 90 days after the LAST event without anything recomputing it.
     * The invoice is attached to the mail anyway.
     *
     * In a redirect: a day. Enough for a tab left open overnight; after that
     * the history entry of a shared computer opens nothing.
     *
     * Downloads: an unlimited file link is a licence nobody agreed to.
     */
    public function defaultLifetime(bool $mailed = false): int
    {
        return match ($this) {
            self::ViewOrder => $mailed ? self::MAILED_LIFETIME : self::BROWSER_LIFETIME,
            self::Download => 60 * 60 * 24 * 90,
        };
    }
}

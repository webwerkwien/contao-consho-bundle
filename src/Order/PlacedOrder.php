<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * What the purchase click has to know once the transaction has committed.
 *
 * The view token is PLAINTEXT and exists here and nowhere else again — it goes
 * into the redirect and is never readable from the database.
 *
 * 🔴 **No payment attempt travels with it** (F5). It used to carry
 * the attempt's id and its plaintext token, and neither was ever read: the
 * attempt is opened where a payment begins, by start() (C-10/C-11), and that
 * caller holds the token while it builds the return and notify URLs.
 */
final class PlacedOrder
{
    /**
     * @param string $viewToken        for the redirect after the click — opens the order page,
     *                                 confirms nothing
     * @param bool   $wasAlreadyPlaced true when a second request found the
     *                                 basket already converted (7.3 step 3)
     * @param string $mailToken        for the link in the confirmation (C-10) — acting through it
     *                                 proves the mailbox. '' for the double click, which sends no mail.
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $viewToken,
        public readonly bool $wasAlreadyPlaced = false,
        public readonly string $mailToken = '',
    ) {
    }
}

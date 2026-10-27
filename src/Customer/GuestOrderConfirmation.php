<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Webwerkwien\ContaoConshoBundle\Confirmation\ConfirmationCheck;
use Webwerkwien\ContaoConshoBundle\Confirmation\ConfirmationPurpose;
use Webwerkwien\ContaoConshoBundle\Confirmation\ConfirmationStatus;
use Webwerkwien\ContaoConshoBundle\Confirmation\EmailConfirmation;

/**
 * "Bestellungen als Gast anzeigen" (D-4, K3) — the one use of
 * EmailConfirmation so far, and the rules that make it safe.
 *
 * A confirmation counts only if ALL of these hold when it is given:
 *
 *  1. The token is valid and ours (EmailConfirmation).
 *  2. The browser is signed in as the account that asked for it. Otherwise
 *     whoever receives the mail — the rightful owner of an address an
 *     attacker typed into his own account — could confirm it for him with an
 *     innocent click.
 *  3. The account still has the address the token was sent to. Changed in
 *     between, the proof is about another mailbox.
 *  4. It comes from a POST (the button). Mail filters open links; a GET
 *     never confirms (EmailConfirmation's class comment).
 */
class GuestOrderConfirmation
{
    public function __construct(
        private readonly EmailConfirmation $confirmation,
        private readonly MemberOrders $orders,
    ) {
    }

    /**
     * Without a name (D-4): the account's name is whatever
     * its holder typed, and the addressee may be somebody else — exactly the
     * case this confirmation guards against. A salutation carrying a
     * stranger's text is a message from the shop that the shop never wrote.
     */
    public function request(int $shopId, int $memberId, string $email, string $language, string $landingUrl): bool
    {
        if ($memberId < 1 || '' === trim($email)) {
            return false;
        }

        return $this->confirmation->request(ConfirmationPurpose::GuestOrders, $shopId, trim($email), '', $language, ['tl_member' => [$memberId]], $landingUrl);
    }

    /**
     * What the linked page shows: 'login', 'invalid', 'otherAccount',
     * 'pending' (the button) or 'confirmed'.
     *
     * The same four rules for every purpose that confirms a MEMBER's address
     * (Block G: ConfirmationPurpose::StockNotice) — written once, here.
     */
    public function state(string $token, int $memberId, string $memberEmail, ConfirmationPurpose $purpose = ConfirmationPurpose::GuestOrders): string
    {
        $check = $this->confirmation->check($purpose, $token);

        if (ConfirmationStatus::Invalid === $check->status) {
            return 'invalid';
        }

        if ($memberId < 1) {
            return 'login';
        }

        if (!$this->belongsTo($check, $memberId, $memberEmail)) {
            return 'otherAccount';
        }

        return ConfirmationStatus::Confirmed === $check->status ? 'confirmed' : 'pending';
    }

    /**
     * From the button's POST. Checks everything again — the page may have
     * been open for a while, and the POST is what counts.
     *
     * @return string 'confirmed', or why not: 'invalid', 'login', 'otherAccount'
     */
    public function confirm(string $token, int $memberId, string $memberEmail, int $now, ConfirmationPurpose $purpose = ConfirmationPurpose::GuestOrders): string
    {
        $state = $this->state($token, $memberId, $memberEmail, $purpose);

        if (!\in_array($state, ['pending', 'confirmed'], true)) {
            return $state;
        }

        $check = $this->confirmation->confirm($purpose, $token);

        if (ConfirmationStatus::Confirmed !== $check->status) {
            return 'invalid';
        }

        $this->orders->markConfirmed($memberId, $check->email, $now);

        return 'confirmed';
    }

    private function belongsTo(ConfirmationCheck $check, int $memberId, string $memberEmail): bool
    {
        return [$memberId] === $check->ids('tl_member')
            && '' !== trim($memberEmail)
            && mb_strtolower(trim($memberEmail)) === mb_strtolower(trim($check->email));
    }
}

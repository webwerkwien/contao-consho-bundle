<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

/**
 * What to say about the last add — shown exactly once, then gone (7.1).
 *
 * Two roads since C-3c, and neither is the session: a session cookie would
 * make every further page private for this visitor (C9), which is the whole
 * reason the basket has a table.
 *
 *   - Success, and anything the visitor does ON the basket page (change a
 *     quantity, remove a line): the basket row, read and cleared there.
 *   - A refusal at the buy button: the address (PARAM below), rendered by the
 *     product page. The basket only enters the picture once something worked,
 *     and a visitor without a basket in this shop had no row to write on.
 */
enum CartNotice: string
{
    /**
     * The query parameter a refusal rides in (C-3c). One name, read by the
     * add route that writes it and the buy button that renders it — two
     * spellings of the same thing drift apart, and the failure would be
     * silence, not an error.
     */
    public const PARAM = 'consho_notice';

    case Added = 'added';
    case Unavailable = 'unavailable';
    case NeedsChoice = 'needs_choice';
    case InvalidQuantity = 'invalid_quantity';
    // Abo-2 (P11): a plan this visitor is not offered — a stale page, a
    // group change, or a hand-made form.
    case NotOffered = 'not_offered';
    // Block G (G7): nothing left and not sold without stock. The page stays,
    // the article cannot go into a basket.
    case OutOfStock = 'out_of_stock';
    // Block Wertgutscheine (WG5): too many unknown codes; the code typed now
    // was not looked at. Only ever written to the basket row, never read off
    // an address — refusalFrom() turns it away like `Added`.
    case TooManyCodes = 'too_many_codes';
    // WG8 (E3): a voucher code this shop does not know, and a basket that
    // holds as many vouchers as it takes. Basket row only, like the one above.
    case VoucherUnknown = 'voucher_unknown';
    case TooManyVouchers = 'too_many_vouchers';

    /**
     * The reason a refusal names, read back off the address (C-3c).
     *
     * Only refusals, and that matters: `Added` is a case of this enum too, so
     * a plain tryFrom() would accept ?consho_notice=added — a link anybody
     * could share to show every visitor "the article has been added to your
     * basket" while nothing happened, on a page that is then cached like
     * that. The route never writes that value into an address; nothing should
     * read it out of one either.
     *
     * Non-strings answer null rather than throwing: ?consho_notice[]=1 is
     * something a crawler produces, not something a product page should meet
     * with a 400.
     */
    public static function refusalFrom(mixed $value): self|null
    {
        $notice = \is_string($value) ? self::tryFrom($value) : null;

        return \in_array($notice, [self::Added, self::TooManyCodes, self::VoucherUnknown, self::TooManyVouchers], true) ? null : $notice;
    }

    // 'other_shop' is gone since C-3b: a basket is now looked up within its
    // shop, so a visitor never meets one belonging to another. A stored value
    // left over from before reads back as "no notice" (tryFrom() returns
    // null), which is what it should be — the reason no longer applies.
}

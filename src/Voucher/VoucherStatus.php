<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

/**
 * What a voucher in the basket can do right now (block Wertgutscheine, 6.5).
 *
 * Asked on every page view and again at the click: a voucher that was fine
 * when it was typed can be spent in another basket, blocked or run out in
 * between. Only `Usable` pays; every other case is said beside the code, so
 * a voucher that does nothing never looks as if it worked.
 */
enum VoucherStatus: string
{
    case Usable = 'usable';
    case Spent = 'spent';
    case Blocked = 'blocked';
    case Expired = 'expired';
    case OtherCurrency = 'otherCurrency';
    // Gone from the shop's reach: deleted, or no longer valid in this shop.
    case Unknown = 'unknown';
}
